<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STORE PAYMENTS (DEC-0051 gap 2, 2026-09-15). A workspace connects its own Stripe account; every catalogue item
 * with a price gets a Checkout button; a paid session becomes an order and a CRM lead. The platform never holds the
 * money and never sees the card. Keys are stored encrypted and only ever shown as a hint ("…1234").
 */
class StorePaymentsService
{
    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'PHP' => '₱', 'CAD' => 'C$', 'AUD' => 'A$', 'SGD' => 'S$', 'INR' => '₹', 'ZAR' => 'R', 'NZD' => 'NZ$', 'CHF' => 'CHF ', 'SAR' => 'SAR ', 'QAR' => 'QAR '];
    private const ZERO_DECIMAL = ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF', 'TWD', 'UGX', 'XAF', 'XOF'];

    public function account(int $wsId): ?object
    {
        return DB::table('workspace_payment_accounts')->where('workspace_id', $wsId)->first();
    }

    public function enabledFor(int $wsId): bool
    {
        $a = $this->account($wsId);
        return $a !== null && in_array($a->status, ['active', 'no_webhook'], true);
    }

    public function status(int $wsId): array
    {
        $a = $this->account($wsId);
        if (! $a) return ['connected' => false, 'message' => 'No payment account connected. Paste a Stripe restricted key to take payments on your site.'];
        $orders = DB::table('catalogue_orders')->where('workspace_id', $wsId)->selectRaw("count(*) as n, sum(case when status='paid' then 1 else 0 end) as paid")->first();
        return ['connected' => true, 'mode' => $a->mode, 'key_hint' => $a->key_hint, 'currency' => $a->currency, 'status' => $a->status, 'webhook' => $a->webhook_id !== null, 'label' => $a->account_label,
            'orders' => (int) ($orders->n ?? 0), 'paid' => (int) ($orders->paid ?? 0),
            'message' => 'Connected (' . $a->mode . ' mode' . ($a->webhook_id ? '' : ', confirming payments on return') . '). Items with a price show a payment button.'];
    }

    private function client(object $a): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient(Crypt::decryptString($a->secret_key));
    }

    /** Validate the key against Stripe, register the webhook, store encrypted. */
    public function connect(int $wsId, string $secret, ?string $publishable, string $currency, string $appUrl): array
    {
        $secret = trim($secret);
        if (! preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{16,}$/', $secret)) return ['success' => false, 'message' => 'That does not look like a Stripe secret or restricted key (it starts with sk_live_, sk_test_, rk_live_ or rk_test_).'];
        $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', $currency)) ?: 'USD';
        if (strlen($currency) !== 3) $currency = 'USD';
        try {
            $stripe = new \Stripe\StripeClient($secret);
            $acct = null;
            try { $acct = $stripe->accounts->retrieve(); } catch (\Throwable $e) { $stripe->balance->retrieve(); }
        } catch (\Throwable $e) {
            Log::info('[StorePayments] key rejected', ['workspace' => $wsId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Stripe did not accept that key. Check it is complete and belongs to the right account (test keys for test mode, live keys for live).'];
        }
        $mode = str_contains($secret, '_live_') ? 'live' : 'test';
        $label = $acct ? trim((string) (($acct->settings->dashboard->display_name ?? null) ?: ($acct->business_profile->name ?? '') ?: ($acct->email ?? ''))) : '';
        $webhookId = null; $webhookSecret = null; $status = 'active';
        $url = rtrim($appUrl, '/') . '/api/public/store-webhook/' . $wsId;
        try {
            $existing = $this->account($wsId);
            if ($existing && $existing->webhook_id) { try { $this->client($existing)->webhookEndpoints->delete($existing->webhook_id); } catch (\Throwable $e) {} }
            $wh = $stripe->webhookEndpoints->create(['url' => $url, 'enabled_events' => ['checkout.session.completed'], 'description' => 'LevelUpGrowth store payments']);
            $webhookId = $wh->id; $webhookSecret = $wh->secret ?? null;
            if (! $webhookSecret) { $webhookId = null; $status = 'no_webhook'; }
        } catch (\Throwable $e) {
            Log::info('[StorePayments] webhook not registered (restricted key?) — confirming on return instead', ['workspace' => $wsId, 'error' => $e->getMessage()]);
            $status = 'no_webhook';
        }
        DB::table('workspace_payment_accounts')->updateOrInsert(['workspace_id' => $wsId], [
            'provider' => 'stripe', 'secret_key' => Crypt::encryptString($secret), 'publishable_key' => $publishable ? trim($publishable) : null, 'key_hint' => '…' . substr($secret, -4), 'mode' => $mode,
            'webhook_id' => $webhookId, 'webhook_secret' => $webhookSecret ? Crypt::encryptString($webhookSecret) : null, 'currency' => $currency, 'status' => $status, 'account_label' => mb_substr($label, 0, 120) ?: null,
            'verified_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        Log::info('[StorePayments] connected', ['workspace' => $wsId, 'mode' => $mode, 'webhook' => $webhookId !== null]);
        return ['success' => true, 'message' => 'Stripe connected in ' . $mode . ' mode' . ($label !== '' ? ' (' . $label . ')' : '') . ($webhookId ? '.' : ' — payments are confirmed when the customer returns to your site.') . ' Every priced item now shows a payment button.'] + $this->status($wsId);
    }

    public function disconnect(int $wsId): array
    {
        $a = $this->account($wsId);
        if (! $a) return ['success' => true, 'message' => 'No payment account was connected.'];
        if ($a->webhook_id) { try { $this->client($a)->webhookEndpoints->delete($a->webhook_id); } catch (\Throwable $e) {} }
        DB::table('workspace_payment_accounts')->where('workspace_id', $wsId)->delete();
        return ['success' => true, 'message' => 'Payments disconnected. Payment buttons disappear from the site on its next update.'];
    }

    public function priceText(object $item): string
    {
        $sym = self::SYMBOLS[$item->currency] ?? ($item->currency . ' ');
        $n = (float) $item->price;
        return $sym . number_format($n, fmod($n, 1.0) !== 0.0 ? 2 : 0) . (! empty($item->price_period) ? ' / ' . $item->price_period : '');
    }

    /** Start Stripe Checkout for one catalogue item. Returns the URL to send the visitor to. */
    public function checkout(int $websiteId, string $kind, int $itemId, string $returnUrl): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')->first();
        if (! $site) return ['success' => false, 'message' => 'Site not found.'];
        $a = $this->account((int) $site->workspace_id);
        if (! $a || ! in_array($a->status, ['active', 'no_webhook'], true)) return ['success' => false, 'message' => 'This site is not taking payments right now.'];
        $item = DB::table('catalogue_items')->where('id', $itemId)->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')->first();
        if (! $item || $item->price === null || (float) $item->price <= 0) return ['success' => false, 'message' => 'This item cannot be paid for online.'];
        $currency = strtolower((string) ($item->currency ?: $a->currency));
        $amount = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? (int) round((float) $item->price) : (int) round((float) $item->price * 100);
        $base = preg_replace('/[?#].*$/', '', $returnUrl) ?: $returnUrl;
        try {
            $session = $this->client($a)->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => $currency, 'unit_amount' => $amount, 'product_data' => array_filter(['name' => mb_substr((string) $item->title, 0, 120), 'description' => mb_substr((string) ($item->summary ?: ''), 0, 250) ?: null])]]],
                'success_url' => $base . '?paid={CHECKOUT_SESSION_ID}',
                'cancel_url' => $base . '?cancelled=1',
                'customer_creation' => 'always',
                'metadata' => ['workspace_id' => (string) $site->workspace_id, 'website_id' => (string) $websiteId, 'item_id' => (string) $itemId, 'kind' => $kind, 'source' => 'levelupgrowth'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[StorePayments] checkout failed', ['website' => $websiteId, 'item' => $itemId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'The payment page could not be opened just now. Please try again in a moment.'];
        }
        DB::table('catalogue_orders')->insert(['workspace_id' => (int) $site->workspace_id, 'website_id' => $websiteId, 'item_id' => $itemId, 'kind' => $kind, 'item_title' => $item->title, 'session_id' => $session->id, 'amount' => (float) $item->price, 'currency' => strtoupper($currency), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        return ['success' => true, 'url' => $session->url, 'session_id' => $session->id];
    }

    /** A signed webhook from the customer's own account. */
    public function handleWebhook(int $wsId, string $payload, string $sigHeader): array
    {
        $a = $this->account($wsId);
        if (! $a || ! $a->webhook_secret) return ['ok' => false, 'reason' => 'no_webhook'];
        try { $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, Crypt::decryptString($a->webhook_secret)); }
        catch (\Throwable $e) { Log::warning('[StorePayments] webhook signature failed', ['workspace' => $wsId, 'error' => $e->getMessage()]); return ['ok' => false, 'reason' => 'bad_signature']; }
        if ($event->type !== 'checkout.session.completed') return ['ok' => true, 'reason' => 'ignored'];
        $s = $event->data->object;
        return $this->markPaid($wsId, (string) $s->id, (string) ($s->customer_details->email ?? $s->customer_email ?? ''), (string) ($s->customer_details->name ?? ''), (string) ($s->payment_status ?? ''), json_decode(json_encode($s), true) ?: []);
    }

    /** Fallback when no webhook could be registered: the visitor returned with ?paid=<session>; ask Stripe whether it was paid. */
    public function confirmSession(int $websiteId, string $sessionId): array
    {
        $order = DB::table('catalogue_orders')->where('session_id', $sessionId)->where('website_id', $websiteId)->first();
        if (! $order) return ['ok' => false, 'reason' => 'unknown_session'];
        if ($order->status === 'paid') return ['ok' => true, 'paid' => true, 'item' => $order->item_title];
        $a = $this->account((int) $order->workspace_id);
        if (! $a) return ['ok' => false, 'reason' => 'no_account'];
        try { $s = $this->client($a)->checkout->sessions->retrieve($sessionId, []); }
        catch (\Throwable $e) { return ['ok' => false, 'reason' => 'lookup_failed']; }
        if (($s->payment_status ?? '') !== 'paid') return ['ok' => true, 'paid' => false];
        $r = $this->markPaid((int) $order->workspace_id, $sessionId, (string) ($s->customer_details->email ?? $s->customer_email ?? ''), (string) ($s->customer_details->name ?? ''), 'paid', json_decode(json_encode($s), true) ?: []);
        return $r + ['paid' => true, 'item' => $order->item_title];
    }

    private function markPaid(int $wsId, string $sessionId, string $email, string $name, string $paymentStatus, array $raw): array
    {
        $order = DB::table('catalogue_orders')->where('session_id', $sessionId)->where('workspace_id', $wsId)->first();
        if (! $order) return ['ok' => false, 'reason' => 'unknown_session'];
        if ($order->status === 'paid') return ['ok' => true, 'reason' => 'already'];
        if ($paymentStatus !== 'paid' && $paymentStatus !== '') return ['ok' => true, 'reason' => 'not_paid'];
        $contactId = null;
        try {
            if ($email !== '') {
                $existing = DB::table('contacts')->where('workspace_id', $wsId)->where('email', $email)->whereNull('deleted_at')->first();
                $parts = preg_split('/\s+/', trim($name), 2);
                $contactId = $existing ? (int) $existing->id : (int) DB::table('contacts')->insertGetId(['workspace_id' => $wsId, 'name' => mb_substr(trim($name) ?: $email, 0, 190), 'full_name' => mb_substr(trim($name) ?: $email, 0, 190), 'first_name' => mb_substr((string) ($parts[0] ?? ''), 0, 100) ?: null, 'last_name' => mb_substr((string) ($parts[1] ?? ''), 0, 100) ?: null, 'email' => $email, 'source' => 'order', 'status' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
                $money = $this->priceText((object) ['currency' => $order->currency, 'price' => $order->amount, 'price_period' => null]);
                DB::table('leads')->insert(['workspace_id' => $wsId, 'website_id' => $order->website_id, 'name' => mb_substr(trim($name) ?: $email, 0, 190), 'email' => $email, 'source' => 'order', 'status' => 'converted', 'deal_value' => $order->amount, 'metadata_json' => json_encode(['contact_id' => $contactId, 'first_message' => 'Paid ' . $money . ' for ' . $order->item_title . ' (online checkout).', 'session_id' => $sessionId, 'website_id' => $order->website_id]), 'created_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) { Log::warning('[StorePayments] CRM write failed', ['workspace' => $wsId, 'error' => $e->getMessage()]); }
        DB::table('catalogue_orders')->where('id', $order->id)->update(['status' => 'paid', 'paid_at' => now(), 'customer_email' => $email ?: null, 'customer_name' => $name ?: null, 'contact_id' => $contactId, 'raw_json' => json_encode(array_intersect_key($raw, array_flip(['id', 'amount_total', 'currency', 'payment_status', 'customer', 'payment_intent']))), 'updated_at' => now()]);
        Log::info('[StorePayments] order paid', ['workspace' => $wsId, 'order' => $order->id, 'item' => $order->item_title]);
        return ['ok' => true, 'reason' => 'paid'];
    }

    /** The button a catalogue page shows for a priced item (empty when payments are off or the item has no price). */
    public function buttonHtml(int $websiteId, object $item, string $label, string $appUrl): string
    {
        // paying the full listed price online only makes sense for services, dishes, rooms, plans, programmes, sessions —
        // never for a house, a car or a project (their price is a negotiation, not a basket)
        if (in_array((string) $item->kind, ['listing', 'vehicle', 'project', 'event'], true)) return '';
        $site = DB::table('websites')->where('id', $websiteId)->first(['workspace_id']);
        if (! $site || ! $this->enabledFor((int) $site->workspace_id)) return '';
        if ($item->price === null || (float) $item->price <= 0) return '';
        $action = rtrim($appUrl, '/') . '/api/public/checkout/' . $websiteId . '/' . e($item->kind) . '/' . (int) $item->id;
        return '<form class="lu-pay" method="post" action="' . e($action) . '" style="margin:14px 0 0"><input type="hidden" name="return" value=""><button type="submit" style="border:0;border-radius:10px;padding:13px 18px;font:inherit;font-weight:700;font-size:15px;cursor:pointer;background:var(--brand,var(--primary,#1f2937));color:#fff">' . e($label) . ' — ' . e($this->priceText($item)) . '</button><span style="display:block;font-size:12px;opacity:.7;margin-top:6px">Secure payment · card details never touch this site</span></form>';
    }
}
