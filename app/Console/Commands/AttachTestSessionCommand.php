<?php

namespace App\Console\Commands;

use App\Models\DomainOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 1E.2 — governed internal setup for ONE workspace-1 test order.
 *
 * Associates a single internal_test order with a synthetic Stripe test session
 * reference, so the real webhook branch can resolve it. Artisan only: no route, no
 * UI, no HTTP surface of any kind.
 *
 * It writes exactly ONE column — stripe_session_id — because that is the only field
 * the webhook uses to resolve an order. It never marks the order paid, never creates
 * an event, never queues a job and never contacts Stripe.
 *
 * RETIRE after the validation.
 */
class AttachTestSessionCommand extends Command
{
    protected $signature = 'domains:attach-test-session
                            {order : the exact order id}
                            {--expect-status=pending : the status the order must currently have}
                            {--session= : the synthetic Stripe test session id}
                            {--confirm= : the token printed by --dry-run}
                            {--dry-run : report the plan and change nothing}';

    protected $description = 'Attach a synthetic Stripe test session to one internal test order (internal, governed)';

    /** Only this operator may authorise, and it is recorded. */
    private const AUTHORISING_USER_ID = 1;

    public function handle(): int
    {
        $orderId = (int) $this->argument('order');
        $expect = (string) $this->option('expect-status');
        $session = (string) ($this->option('session') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        $order = DomainOrder::with('items')->find($orderId);

        // ── refusals, every one fail-closed ──────────────────────────────────
        if ($order === null) {
            return $this->refuse("order {$orderId} does not exist");
        }

        if ((int) $order->workspace_id !== 1) {
            return $this->refuse("order {$orderId} belongs to workspace {$order->workspace_id}; only workspace 1 is permitted");
        }

        $meta = is_array($order->metadata_json) ? $order->metadata_json : [];

        if (($meta['internal_test'] ?? null) !== true) {
            return $this->refuse("order {$orderId} is NOT marked internal_test — a fixture must never touch a customer order");
        }

        if ($order->paid_at !== null) {
            return $this->refuse("order {$orderId} is already paid (paid_at {$order->paid_at})");
        }

        if ($order->status === DomainOrder::STATUS_CANCELLED) {
            return $this->refuse("order {$orderId} is cancelled");
        }

        if ($order->status !== $expect) {
            return $this->refuse("order {$orderId} status is '{$order->status}', expected '{$expect}'");
        }

        foreach ($order->items as $item) {
            if ($item->status !== 'pending' || $item->registered_at !== null || $item->provider_order_id !== null) {
                return $this->refuse("order {$orderId} item {$item->id} is not unfulfilled (status {$item->status})");
            }
        }

        if (! empty($order->stripe_session_id)) {
            return $this->refuse("order {$orderId} already has stripe_session_id '{$order->stripe_session_id}' — refusing to overwrite a payment reference");
        }

        if (! empty($order->stripe_payment_intent_id)) {
            return $this->refuse("order {$orderId} already has a payment intent — refusing to overwrite");
        }

        if ($session === '' || ! str_starts_with($session, 'cs_test_')) {
            return $this->refuse("--session must be a synthetic Stripe TEST session id beginning 'cs_test_'");
        }

        // ── the plan ─────────────────────────────────────────────────────────
        $token = substr(hash('sha256', implode('|', [
            'attach-test-session', (string) $order->id, (string) $order->workspace_id, $session,
        ])), 0, 24);

        $this->line('');
        $this->line('PLAN — attach a synthetic test session');
        $this->line(sprintf('  order                    %d (workspace %d)', $order->id, $order->workspace_id));
        $this->line(sprintf('  status                   %s  (unchanged)', $order->status));
        $this->line(sprintf('  paid_at                  %s  (unchanged)', $order->paid_at ?? 'NULL'));
        $this->line(sprintf('  stripe_session_id        %s  ->  %s   <== THE ONLY CHANGE',
            $order->stripe_session_id ?? 'NULL', $session));
        $this->line(sprintf('  stripe_payment_intent_id %s  (unchanged — the webhook supplies it)',
            $order->stripe_payment_intent_id ?? 'NULL'));
        $this->line(sprintf('  total_minor              %d (unchanged)', $order->total_minor));
        $this->line(sprintf('  items                    %d, all pending (unchanged)', $order->items->count()));
        $this->line('');
        $this->line('  no payment transition · no platform event · no queued job · no Stripe API call');
        $this->line(sprintf('  governance audit         action=domains.test_session_attached user_id=%d workspace_id=%d',
            self::AUTHORISING_USER_ID, $order->workspace_id));
        $this->line('');

        if ($dryRun) {
            $this->info("DRY RUN — nothing was written. Confirmation token: {$token}");

            return self::SUCCESS;
        }

        if (! hash_equals($token, (string) ($this->option('confirm') ?? ''))) {
            return $this->refuse('confirmation token does not match this plan; re-run with --dry-run and pass the printed token');
        }

        // ── the write: one column, inside a transaction with the audit ───────
        DB::transaction(function () use ($order, $session) {
            DB::table('domain_orders')->where('id', $order->id)->update([
                'stripe_session_id' => $session,
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'workspace_id' => (int) $order->workspace_id,
                'user_id' => self::AUTHORISING_USER_ID,
                'action' => 'domains.test_session_attached',
                'entity_type' => 'domain_order',
                'entity_id' => (int) $order->id,
                'metadata_json' => json_encode([
                    'source' => 'internal_setup_command',
                    'command' => 'domains:attach-test-session',
                    'authorised_by_user_id' => self::AUTHORISING_USER_ID,
                    'stripe_session_id' => $session,
                    'previous_stripe_session_id' => null,
                    'order_status_unchanged' => $order->status,
                    'paid_at_unchanged' => null,
                    'reason' => 'Phase 1E.2 one-time signed webhook replay preparation',
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        });

        $fresh = DomainOrder::find($order->id);

        $this->line('AFTER');
        $this->line(sprintf('  stripe_session_id        %s', $fresh->stripe_session_id));
        $this->line(sprintf('  status                   %s', $fresh->status));
        $this->line(sprintf('  paid_at                  %s', $fresh->paid_at ?? 'NULL'));
        $this->line(sprintf('  stripe_payment_intent_id %s', $fresh->stripe_payment_intent_id ?? 'NULL'));

        $this->info('Attached. One column changed, one governance record written.');

        return self::SUCCESS;
    }

    private function refuse(string $why): int
    {
        $this->error('REFUSED: ' . $why);

        return self::FAILURE;
    }
}
