<?php

namespace App\Console\Commands;

use App\Models\DomainOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * PHASE 1E.2 — the one-time signed fixture replay.
 *
 * Builds the exact webhook JSON, signs it with the FIXTURE secret using Stripe's own
 * scheme, and submits it over real HTTP to the real local webhook route — so the
 * request traverses routing, middleware, the controller, StripeService, the real
 * \Stripe\Webhook signature verifier, idempotency, the payment transaction and event
 * creation.
 *
 * It never calls StripeService directly, never calls markPaidAndProvision() directly,
 * and never bypasses the route. Artisan only: no route, no UI.
 *
 * RETIRE after the validation.
 */
class ReplayTestWebhookCommand extends Command
{
    protected $signature = 'domains:replay-test-webhook
                            {--confirm= : the token printed by --dry-run}
                            {--dry-run : report the plan and send nothing}';

    protected $description = 'Submit the ONE authorised signed fixture webhook to the real local route (internal, governed)';

    private const URL = 'http://127.0.0.1/api/webhook/stripe';
    private const HOST = 'levelupgrowth.io';

    public function handle(): int
    {
        $cfg = (array) config('domains.fixture_replay', []);
        $dryRun = (bool) $this->option('dry-run');

        // ── refusals, all fail-closed ────────────────────────────────────────
        if (($cfg['enabled'] ?? false) !== true) {
            return $this->refuse('fixture replay is not enabled');
        }

        foreach (['secret', 'event_id', 'session_id', 'payment_intent_id'] as $k) {
            if (($cfg[$k] ?? '') === '') {
                return $this->refuse("fixture_replay.{$k} is not configured");
            }
        }

        $orderId = (int) ($cfg['order_id'] ?? 0);
        $wsId = (int) ($cfg['workspace_id'] ?? 0);

        if ($orderId < 1 || $wsId !== 1) {
            return $this->refuse('fixture_replay.order_id / workspace_id are not correctly configured (workspace must be 1)');
        }

        if (config('domains.fulfilment.enabled') !== false) {
            return $this->refuse('domains.fulfilment.enabled must be FALSE — refusing to risk a registration dispatch');
        }

        if ((((array) config('platform_events.producers', []))['domain.order.paid'] ?? false) !== true) {
            return $this->refuse('the domain.order.paid producer must be enabled, or the replay proves nothing');
        }

        $allowed = (array) config('platform_events.producer_workspace_allowlist', []);

        if (array_map('intval', $allowed) !== [1]) {
            return $this->refuse('the workspace allow-list must be exactly [1]');
        }

        $order = DomainOrder::with('items')->find($orderId);

        if ($order === null) {
            return $this->refuse("order {$orderId} does not exist");
        }

        if ((int) $order->workspace_id !== 1) {
            return $this->refuse("order {$orderId} is not in workspace 1");
        }

        if ($order->paid_at !== null) {
            return $this->refuse("order {$orderId} is already paid — refusing a second transition");
        }

        if ((string) $order->stripe_session_id !== (string) $cfg['session_id']) {
            return $this->refuse("order {$orderId} session '{$order->stripe_session_id}' does not match the authorised fixture session");
        }

        $body = $this->body($cfg);
        $token = substr(hash('sha256', 'replay|' . $cfg['event_id'] . '|' . $orderId . '|' . $cfg['session_id']), 0, 24);

        $this->line('');
        $this->line('PLAN — one signed fixture through the real webhook route');
        $this->line('  route                 POST ' . self::URL . '   (Host: ' . self::HOST . ')');
        $this->line('  stripe event id       ' . $cfg['event_id']);
        $this->line('  session id            ' . $cfg['session_id']);
        $this->line('  payment intent id     ' . $cfg['payment_intent_id']);
        $this->line('  order                 ' . $orderId . ' (workspace ' . $wsId . ')');
        $this->line('  order status now      ' . $order->status . ' / paid_at ' . ($order->paid_at ?? 'NULL'));
        $this->line('  livemode              false (test mode)');
        $this->line('  signature             Stripe scheme t=<ts>,v1=<hmac_sha256>, FIXTURE secret (never the live secret)');
        $this->line('  fulfilment            ' . var_export(config('domains.fulfilment.enabled'), true) . '  -> no registration dispatch');
        $this->line('  expected              HTTP 200, order paid, 1 domain.order.paid event, 0 jobs');
        $this->line('');

        if ($dryRun) {
            $this->info("DRY RUN — nothing was sent. Confirmation token: {$token}");

            return self::SUCCESS;
        }

        if (! hash_equals($token, (string) ($this->option('confirm') ?? ''))) {
            return $this->refuse('confirmation token does not match this plan');
        }

        // ── send it, over real HTTP, to the real route ───────────────────────
        $ts = time();
        $signature = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, (string) $cfg['secret']);

        $response = Http::withHeaders([
            'Host' => self::HOST,
            'Stripe-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->withBody($body, 'application/json')->timeout(30)->post(self::URL);

        $this->line('RESULT');
        $this->line('  HTTP status  ' . $response->status());
        $this->line('  body         ' . mb_substr($response->body(), 0, 600));
        $this->line('');

        $fresh = DomainOrder::find($orderId);
        $this->line('  order status ' . $fresh->status);
        $this->line('  paid_at      ' . ($fresh->paid_at ?? 'NULL'));
        $this->line('  intent       ' . ($fresh->stripe_payment_intent_id ?? 'NULL'));
        // NOTE: this command deliberately does NOT count platform_events itself.
        // Only App\Core\PlatformEvents\Outbox may touch that table, and an
        // architecture test enforces it — a convenience count in CLI output is not a
        // good enough reason to become a second reader of the outbox.
        $this->line('  (event counts: see `php artisan platform-events:process --health`)');

        return $response->status() === 200 ? self::SUCCESS : self::FAILURE;
    }

    /** The exact Stripe event body. Test mode, one session, one intent. */
    private function body(array $cfg): string
    {
        return json_encode([
            'id' => (string) $cfg['event_id'],
            'object' => 'event',
            'type' => (string) $cfg['event_type'],
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => (string) $cfg['session_id'],
                'object' => 'checkout.session',
                'mode' => 'payment',
                'payment_status' => 'paid',
                'payment_intent' => (string) $cfg['payment_intent_id'],
                'metadata' => ['order_type' => 'domain'],
            ]],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function refuse(string $why): int
    {
        $this->error('REFUSED: ' . $why);

        return self::FAILURE;
    }
}
