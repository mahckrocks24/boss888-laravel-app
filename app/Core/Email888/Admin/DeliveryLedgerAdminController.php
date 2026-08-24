<?php

namespace App\Core\Email888\Admin;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EMAIL888 - the operator's view of outbound mail.
 *
 * THE LEDGER IS THE SYSTEM OF RECORD. Everything below is read back out of it;
 * nothing here infers a state, recomputes one, or asks a provider. If the
 * ledger does not know it, this surface does not claim it.
 *
 * Filtering is done in SQL, never in the browser. A page that filters a partial
 * result set client-side tells an operator "no bounces" when it means "no
 * bounces on this page" - which is the same fabricated-success shape that lost
 * 37 messages in the first place.
 */
class DeliveryLedgerAdminController
{
    private const PER_PAGE = 50;
    private const MAX_PER_PAGE = 200;

    public function index(Request $request): JsonResponse
    {
        $q = EmailDelivery::query();

        // ── exact-match filters ──────────────────────────────────────────
        foreach ([
            'workspace_id'        => 'workspace_id',
            'purpose'             => 'purpose',
            'stream'              => 'stream_class',
            'provider'            => 'provider',
            'state'               => 'state',
            'failure_category'    => 'failure_category',
            'correlation_id'      => 'correlation_id',
            'provider_message_id' => 'provider_message_id',
        ] as $param => $column) {
            if (($v = $request->query($param)) !== null && $v !== '') {
                $q->where($column, $v);
            }
        }

        if ($request->query('retryable') !== null && $request->query('retryable') !== '') {
            $q->where('retryable', filter_var($request->query('retryable'), FILTER_VALIDATE_BOOLEAN));
        }

        // Suppressed and bounced are states, but operators think of them as
        // questions ("was anything refused?"), so both spellings work.
        if (filter_var($request->query('suppressed'), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('state', DeliveryState::SUPPRESSED->value);
        }
        if (filter_var($request->query('bounced'), FILTER_VALIDATE_BOOLEAN)) {
            $q->where('state', DeliveryState::BOUNCED->value);
        }

        // ── date range ───────────────────────────────────────────────────
        if ($from = $request->query('from')) { $q->where('queued_at', '>=', $from); }
        if ($to = $request->query('to')) { $q->where('queued_at', '<=', $to); }

        // ── search ───────────────────────────────────────────────────────
        // Bounded to the identifiers an operator actually pastes in.
        if (($s = trim((string) $request->query('search', ''))) !== '') {
            $q->where(function ($w) use ($s) {
                $w->where('recipient_address', 'like', '%' . $s . '%')
                  ->orWhere('sender_address', 'like', '%' . $s . '%')
                  ->orWhere('subject', 'like', '%' . $s . '%')
                  ->orWhere('provider_message_id', $s)
                  ->orWhere('correlation_id', $s)
                  ->orWhere('purpose', $s);
            });
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('per_page', self::PER_PAGE)));

        $page = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => array_map(
                fn (EmailDelivery $d) => $this->row($d),
                $page->items()
            ),
            'meta' => [
                'total'        => $page->total(),
                'per_page'     => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
            ],
            // Real counts over the WHOLE ledger, not the current page.
            'summary' => $this->summary(),
            'facets'  => $this->facets(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $d = EmailDelivery::find($id);

        if (! $d) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        return response()->json([
            'success'  => true,
            'delivery' => $this->row($d) + [
                'sender_name'     => $d->sender_name,
                'idempotency_key' => $d->idempotency_key,
                'metadata'        => $d->metadata,
            ],
            'timeline' => $this->timeline($d),
            'events'   => $d->events()->orderBy('occurred_at')->get()->map(fn ($e) => [
                'id'                  => $e->id,
                'event_type'          => $e->event_type,
                'provider_event_type' => $e->provider_event_type,
                'occurred_at'         => optional($e->occurred_at)->toIso8601String(),
                'evidence'            => $e->raw_metadata,
            ])->all(),
        ]);
    }

    /** @return array<string,mixed> */
    private function row(EmailDelivery $d): array
    {
        $state = $d->deliveryState();

        return $d->toAdminArray() + [
            'is_success'       => $state->isSuccess(),
            'latency_seconds'  => $this->latency($d),
            'stream'           => $d->stream_class,
            'provider_stream'  => $d->provider_stream,
        ];
    }

    /**
     * Hand-off to terminal outcome, in seconds. Null when there is no terminal
     * state yet - an unfinished message has no duration, and inventing one
     * would be a small lie of exactly the kind this subsystem exists to stop.
     */
    private function latency(EmailDelivery $d): ?int
    {
        $end = $d->delivered_at ?? $d->bounced_at ?? $d->suppressed_at ?? $d->failed_at;

        if ($end === null || $d->queued_at === null) {
            return null;
        }

        return max(0, $end->getTimestamp() - $d->queued_at->getTimestamp());
    }

    /**
     * The truthful transition history. Each entry exists only because a
     * timestamp column is populated - nothing is interpolated to make the
     * timeline look complete.
     */
    private function timeline(EmailDelivery $d): array
    {
        $steps = [
            ['state' => 'queued',     'at' => $d->queued_at,     'source' => 'application', 'note' => 'Handed to the mailer.'],
            ['state' => 'accepted',   'at' => $d->accepted_at,   'source' => 'provider',    'note' => 'Provider took responsibility. Not delivery.'],
            ['state' => 'deferred',   'at' => $d->deferred_at,   'source' => 'provider',    'note' => 'Temporarily refused upstream; still trying.'],
            ['state' => 'delivered',  'at' => $d->delivered_at,  'source' => 'provider',    'note' => 'Receiving server accepted it.'],
            ['state' => 'bounced',    'at' => $d->bounced_at,    'source' => 'provider',    'note' => 'Permanently refused.'],
            ['state' => 'suppressed', 'at' => $d->suppressed_at, 'source' => 'provider',    'note' => 'Refused before sending: recipient suppressed.'],
            ['state' => 'failed',     'at' => $d->failed_at,     'source' => 'application', 'note' => 'Never handed over, or died without a verdict.'],
        ];

        $out = [];
        foreach ($steps as $s) {
            if ($s['at'] === null) {
                continue;
            }
            $out[] = [
                'state'  => $s['state'],
                'at'     => $s['at']->toIso8601String(),
                'source' => $s['source'],
                'note'   => $s['note'],
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $out;
    }

    private function summary(): array
    {
        $rows = EmailDelivery::select('state', DB::raw('count(*) as n'))->groupBy('state')->pluck('n', 'state');

        $out = ['total' => (int) array_sum($rows->all())];
        foreach (DeliveryState::cases() as $c) {
            $out[$c->value] = (int) ($rows[$c->value] ?? 0);
        }

        return $out;
    }

    /** Filter options built from what is actually in the ledger. */
    private function facets(): array
    {
        return [
            'purposes'   => EmailDelivery::distinct()->orderBy('purpose')->pluck('purpose')->all(),
            'streams'    => EmailDelivery::distinct()->orderBy('stream_class')->pluck('stream_class')->all(),
            'providers'  => EmailDelivery::distinct()->orderBy('provider')->pluck('provider')->all(),
            'states'     => array_map(fn ($c) => $c->value, DeliveryState::cases()),
            'workspaces' => EmailDelivery::whereNotNull('workspace_id')
                ->distinct()->orderBy('workspace_id')->pluck('workspace_id')->all(),
        ];
    }
}
