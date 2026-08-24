<?php

namespace App\Core\Email888\Console;

use App\Core\Email888\DeliveryLedger;
use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * EMAIL888 — resolve deliveries that never reached a terminal state.
 *
 * Two distinct ambiguities, two distinct treatments:
 *
 *   STALE QUEUED    hand-off never completed. The transport threw, or the
 *                   process died mid-send. No provider id exists, so there is
 *                   nothing to ask anyone about — it is a failure, and saying
 *                   so is better than leaving it 'queued' forever.
 *
 *   STUCK ACCEPTED  the provider has it and owes us an event we never got,
 *                   usually because no webhook was configured. Here we ASK the
 *                   provider rather than guess, which is the whole point: a
 *                   state may only advance on evidence.
 */
class ReconcileDeliveries extends Command
{
    protected $signature = 'email888:reconcile
                            {--limit=200 : Maximum accepted rows to poll}
                            {--dry-run   : Report only}';

    protected $description = 'EMAIL888: close out deliveries with no terminal state, using provider evidence.';

    public function handle(DeliveryLedger $ledger): int
    {
        $dry = (bool) $this->option('dry-run');

        // ── 1. stale hand-offs ────────────────────────────────────────────
        $staleMinutes = (int) config('email888.stale_queued_minutes', 15);
        $cutoff = now()->subMinutes($staleMinutes);

        $stale = EmailDelivery::where('state', DeliveryState::QUEUED->value)
            ->where('queued_at', '<', $cutoff)
            ->get();

        $this->line(sprintf('stale queued (> %d min): %d', $staleMinutes, $stale->count()));

        foreach ($stale as $row) {
            if ($dry) {
                $this->line("  would fail #{$row->id} → {$row->recipient_address}");
                continue;
            }
            $ledger->markFailed(
                (int) $row->id,
                'handoff_incomplete',
                true,
                ['reason' => 'no provider acceptance recorded within ' . $staleMinutes . ' minutes']
            );
        }

        // ── 2. accepted but no terminal event ─────────────────────────────
        $token = (string) config('services.postmark.token');
        if ($token === '') {
            $this->warn('no Postmark token configured — skipping provider poll');

            return self::SUCCESS;
        }

        $pending = EmailDelivery::whereIn('state', [DeliveryState::ACCEPTED->value, DeliveryState::DEFERRED->value])
            ->whereNotNull('provider_message_id')
            ->where('accepted_at', '<', now()->subMinutes(5))
            ->orderBy('accepted_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->line("accepted awaiting terminal state: {$pending->count()}");

        $counts = ['applied' => 0, 'duplicate' => 0, 'out_of_order' => 0, 'unmatched' => 0, 'error' => 0, 'none' => 0];

        foreach ($pending as $row) {
            if ($dry) {
                $this->line("  would poll #{$row->id} {$row->provider_message_id}");
                continue;
            }

            $outcome = $this->pollOne($ledger, $token, $row);
            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        foreach ($counts as $k => $v) {
            if ($v > 0) {
                $this->line("  {$k}: {$v}");
            }
        }

        return self::SUCCESS;
    }

    private function pollOne(DeliveryLedger $ledger, string $token, EmailDelivery $row): string
    {
        try {
            $res = Http::withHeaders([
                'X-Postmark-Server-Token' => $token,
                'Accept'                  => 'application/json',
            ])->timeout(20)->get(
                'https://api.postmarkapp.com/messages/outbound/' . urlencode((string) $row->provider_message_id) . '/details'
            );

            if (! $res->successful()) {
                return 'error';
            }

            foreach ($res->json('MessageEvents') ?? [] as $ev) {
                $type = (string) ($ev['Type'] ?? '');

                $state = match ($type) {
                    'Delivered'  => DeliveryState::DELIVERED,
                    'Bounced'    => DeliveryState::BOUNCED,
                    'SpamComplaint', 'SubscriptionChanged' => DeliveryState::SUPPRESSED,
                    default      => null,
                };

                if ($state === null) {
                    continue;
                }

                return $ledger->applyProviderEvent(
                    provider:          'postmark',
                    providerMessageId: (string) $row->provider_message_id,
                    state:             $state,
                    rawEventType:      'poll:' . $type,
                    // Digest includes the message id and event type so polling
                    // the same message twice is one event, not two.
                    payloadDigest:     hash('sha256', 'poll|' . $row->provider_message_id . '|' . $type),
                    evidence:          $this->flatten($ev['Details'] ?? []),
                    occurredAt:        $this->time($ev['ReceivedAt'] ?? null),
                );
            }

            return 'none';
        } catch (Throwable $e) {
            $this->warn("  poll failed #{$row->id}: " . $e->getMessage());

            return 'error';
        }
    }

    private function flatten(mixed $details): array
    {
        if (! is_array($details)) {
            return [];
        }

        $out = [];
        foreach ($details as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $out[(string) $k] = $v;
            }
        }

        return $out;
    }

    private function time(?string $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
