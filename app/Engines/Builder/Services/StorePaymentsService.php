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
        if ($a && $this->isConnect($a) && $a->status !== 'active' && $a->connect_state !== 'disconnected') { $this->connectRefresh($wsId); $a = $this->account($wsId); }   // PAY-CONNECT-1: back from Stripe
        $cx = ['connect_available' => $this->connectAvailable(), 'fee_pct' => $this->feePct($a)];
        if ($a && $this->isConnect($a) && $a->status !== 'active') {
            return $cx + ['connected' => false, 'via' => 'connect', 'onboarding' => $a->connect_state !== 'disconnected',
                'message' => $a->connect_state === 'disconnected' ? 'Payments are off. Connect Stripe again to take payments.' : 'Stripe still needs a few details before you can take payments. Continue where you left off.'];
        }
        if (! $a) return $cx + ['connected' => false, 'message' => 'No payment account connected. Connect Stripe to take card payments.'];
        if ($this->isConnect($a)) {
            $orders = DB::table('catalogue_orders')->where('workspace_id', $wsId)->selectRaw("count(*) as n, sum(case when status='paid' then 1 else 0 end) as paid")->first();
            return $cx + ['connected' => true, 'via' => 'connect', 'mode' => $a->mode, 'key_hint' => null, 'currency' => $a->currency, 'status' => $a->status, 'webhook' => false, 'label' => $a->account_label,
                'orders' => (int) ($orders->n ?? 0), 'paid' => (int) ($orders->paid ?? 0),
                'message' => 'Stripe connected' . ($a->account_label ? ' (' . $a->account_label . ')' : '') . ($a->mode === 'test' ? ', test mode' : '') . '. Card payments go straight to your Stripe account; a ' . $this->feePct($a) . '% platform fee applies to each payment.'];
        }
        $orders = DB::table('catalogue_orders')->where('workspace_id', $wsId)->selectRaw("count(*) as n, sum(case when status='paid' then 1 else 0 end) as paid")->first();
        return ['connected' => true, 'mode' => $a->mode, 'key_hint' => $a->key_hint, 'currency' => $a->currency, 'status' => $a->status, 'webhook' => $a->webhook_id !== null, 'label' => $a->account_label,
            'orders' => (int) ($orders->n ?? 0), 'paid' => (int) ($orders->paid ?? 0),
            'message' => 'Connected (' . $a->mode . ' mode' . ($a->webhook_id ? '' : ', confirming payments on return') . '). Items with a price show a payment button.'] + $cx + ['via' => 'key'];
    }

    private function client(object $a): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient(Crypt::decryptString($a->secret_key));
    }

    // ── PAY-CONNECT-1: Stripe Connect (Standard accounts, direct charges, platform fee) ─────────────────────
    public function isConnect(?object $a): bool { return $a !== null && $a->provider === 'stripe_connect' && ! empty($a->connect_account_id); }

    private function platform(): \Stripe\StripeClient { return new \Stripe\StripeClient((string) config('billing.stripe.secret_key')); }

    public function connectAvailable(): bool { return (string) config('billing.stripe.secret_key') !== '' && file_exists(storage_path('app/payconnect.on')); }

    public function feePct(?object $a): float { return round(((int) ($a->fee_bps ?? 100)) / 100, 2); }

    /** [client, request options] for a payment account: Connect uses the platform key on the business's account. */
    public function stripeFor(object $a): array
    {
        return $this->isConnect($a) ? [$this->platform(), ['stripe_account' => $a->connect_account_id]] : [$this->client($a), []];
    }

    /** The platform fee in the smallest currency unit (Connect only; a pasted key pays no fee). */
    public function feeFor(object $a, int $amountMinor): int
    {
        if (! $this->isConnect($a)) return 0;
        return (int) max(0, min($amountMinor - 1, (int) ceil($amountMinor * ((int) ($a->fee_bps ?? 100)) / 10000)));
    }

    /** Start (or continue) Stripe onboarding. Returns the Stripe page to send the owner to. */
    public function connectStart(int $wsId, string $email, string $back): array
    {
        if (! $this->connectAvailable()) return ['success' => false, 'message' => 'Connecting Stripe is not available yet. You can paste a Stripe key instead.'];
        $a = $this->account($wsId);
        $s = $this->platform();
        try {
            $acct = ($a && ! empty($a->connect_account_id)) ? $a->connect_account_id : null;
            if (! $acct) {
                $biz = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('id')->first(['name']);
                $new = $s->accounts->create(array_filter(['type' => 'standard', 'email' => $email ?: null,
                    'business_profile' => $biz && $biz->name ? ['name' => mb_substr($biz->name, 0, 100)] : null,
                    'metadata' => ['workspace_id' => (string) $wsId, 'source' => 'levelupgrowth']]));
                $acct = $new->id;
                $mode = str_contains((string) config('billing.stripe.secret_key'), '_live_') ? 'live' : 'test';
                // a pasted key's row becomes a Connect row only once Stripe says the account can take payments (connectRefresh)
                if (! $a) DB::table('workspace_payment_accounts')->insert(['workspace_id' => $wsId, 'provider' => 'stripe_connect', 'connect_account_id' => $acct, 'fee_bps' => 100, 'connect_state' => 'onboarding',
                    'secret_key' => null, 'mode' => $mode, 'currency' => 'USD', 'status' => 'onboarding', 'created_at' => now(), 'updated_at' => now()]);
                else DB::table('workspace_payment_accounts')->where('workspace_id', $wsId)->update(['connect_account_id' => $acct, 'connect_state' => 'onboarding', 'updated_at' => now()]);
            } elseif ($a->connect_state === 'disconnected') {
                DB::table('workspace_payment_accounts')->where('workspace_id', $wsId)->update(['connect_state' => 'onboarding', 'updated_at' => now()]);
                $r = $this->connectRefresh($wsId);
                if (! empty($r['connected'])) return ['success' => true, 'done' => true, 'message' => 'Stripe is connected again.'];
            }
            $sep = str_contains($back, '?') ? '&' : '?';
            $link = $s->accountLinks->create(['account' => $acct, 'type' => 'account_onboarding', 'refresh_url' => $back . $sep . 'stripe=refresh', 'return_url' => $back . $sep . 'stripe=return']);
        } catch (\Throwable $e) {
            Log::warning('[PayConnect] start failed', ['workspace' => $wsId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Stripe could not be opened just now. Please try again in a moment.'];
        }
        return ['success' => true, 'url' => $link->url];
    }

    /** Ask Stripe where onboarding stands; switch payments on once the account can take charges. */
    public function connectRefresh(int $wsId): array
    {
        $a = $this->account($wsId);
        if (! $a || empty($a->connect_account_id)) return ['connected' => false];
        try { $acct = $this->platform()->accounts->retrieve($a->connect_account_id, []); }
        catch (\Throwable $e) { Log::info('[PayConnect] refresh failed', ['workspace' => $wsId, 'error' => $e->getMessage()]); return ['connected' => $a->status === 'active' && $this->isConnect($a)]; }
        if (! empty($acct->charges_enabled) && $a->connect_state !== 'disconnected') {
            $label = trim((string) (($acct->settings->dashboard->display_name ?? null) ?: ($acct->business_profile->name ?? '') ?: ($acct->email ?? '')));
            if ($a->webhook_id && $a->secret_key) { try { $this->client($a)->webhookEndpoints->delete($a->webhook_id); } catch (\Throwable $e) {} }   // the pasted key's webhook is no longer used
            DB::table('workspace_payment_accounts')->where('workspace_id', $wsId)->update(['provider' => 'stripe_connect', 'status' => 'active', 'connect_state' => 'active',
                'currency' => strtoupper((string) ($acct->default_currency ?: 'usd')), 'account_label' => mb_substr($label, 0, 120) ?: null,
                'secret_key' => null, 'key_hint' => null, 'webhook_id' => null, 'webhook_secret' => null, 'verified_at' => now(), 'updated_at' => now()]);
            Log::info('[PayConnect] active', ['workspace' => $wsId, 'account' => $a->connect_account_id]);
            return ['connected' => true];
        }
        return ['connected' => false, 'details_needed' => ! empty($acct->requirements->currently_due)];
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
            'provider' => 'stripe', 'connect_state' => null, 'secret_key' => Crypt::encryptString($secret), 'publishable_key' => $publishable ? trim($publishable) : null, 'key_hint' => '…' . substr($secret, -4), 'mode' => $mode,
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
        if ($this->isConnect($a) || ! empty($a->connect_account_id)) {   // PAY-CONNECT-1: keep the account id so reconnecting needs no new Stripe account
            if ($a->webhook_id && $a->secret_key) { try { $this->client($a)->webhookEndpoints->delete($a->webhook_id); } catch (\Throwable $e) {} }
            DB::table('workspace_payment_accounts')->where('workspace_id', $wsId)->update(['provider' => 'stripe_connect', 'status' => 'off', 'connect_state' => 'disconnected', 'secret_key' => null, 'key_hint' => null, 'webhook_id' => null, 'webhook_secret' => null, 'updated_at' => now()]);
            return ['success' => true, 'message' => 'Payments turned off. Your Stripe account stays yours; connect again any time.'];
        }
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
        $fee = $this->feeFor($a, $amount); [$sc, $opt] = $this->stripeFor($a);
        try {
            $session = $sc->checkout->sessions->create(array_filter([
                'payment_intent_data' => $fee > 0 ? ['application_fee_amount' => $fee] : null,   // PAY-CONNECT-1
                'mode' => 'payment',
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => $currency, 'unit_amount' => $amount, 'product_data' => array_filter(['name' => mb_substr((string) $item->title, 0, 120), 'description' => mb_substr((string) ($item->summary ?: ''), 0, 250) ?: null])]]],
                'success_url' => $base . '?paid={CHECKOUT_SESSION_ID}',
                'cancel_url' => $base . '?cancelled=1',
                'customer_creation' => 'always',
                'metadata' => ['workspace_id' => (string) $site->workspace_id, 'website_id' => (string) $websiteId, 'item_id' => (string) $itemId, 'kind' => $kind, 'source' => 'levelupgrowth'],
            ]), $opt);
        } catch (\Throwable $e) {
            Log::warning('[StorePayments] checkout failed', ['website' => $websiteId, 'item' => $itemId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'The payment page could not be opened just now. Please try again in a moment.'];
        }
        DB::table('catalogue_orders')->insert(['workspace_id' => (int) $site->workspace_id, 'website_id' => $websiteId, 'item_id' => $itemId, 'kind' => $kind, 'item_title' => $item->title, 'session_id' => $session->id, 'amount' => (float) $item->price, 'platform_fee' => $fee > 0 ? (in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? $fee : $fee / 100) : null, 'currency' => strtoupper($currency), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
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
        try { [$sc, $opt] = $this->stripeFor($a); $s = $sc->checkout->sessions->retrieve($sessionId, [], $opt); }
        catch (\Throwable $e) { return ['ok' => false, 'reason' => 'lookup_failed']; }
        if (($s->payment_status ?? '') !== 'paid') return ['ok' => true, 'paid' => false];
        $r = $this->markPaid((int) $order->workspace_id, $sessionId, (string) ($s->customer_details->email ?? $s->customer_email ?? ''), (string) ($s->customer_details->name ?? ''), 'paid', json_decode(json_encode($s), true) ?: []);
        return $r + ['paid' => true, 'item' => $order->item_title];
    }

    private function markPaid(int $wsId, string $sessionId, string $email, string $name, string $paymentStatus, array $raw): array
    {
        $order = DB::table('catalogue_orders')->where('session_id', $sessionId)->where('workspace_id', $wsId)->first();
        // CRM-PACKS-4a: the same Stripe account also takes payment for quotes, deposits and invoices sent from Clients
        if (! $order && $paymentStatus === 'paid' && ($crm = app(\App\Engines\CRM\Services\CrmPayments::class)->markPaidBySession($wsId, $sessionId, (float) (($raw['amount_total'] ?? 0) / 100)))) return $crm;
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
                // CRM-DATA-1: through the one door — a repeat buyer in the same business gets the purchase on their timeline
                $__cap = app(\App\Engines\CRM\Services\CrmService::class)->captureLead($wsId, ['website_id' => $order->website_id, 'name' => mb_substr(trim($name) ?: $email, 0, 190), 'email' => $email, 'source' => 'order', 'status' => 'converted', 'deal_value' => $order->amount,
                    'activity' => 'Paid ' . $money . ' for ' . $order->item_title . ' (online checkout)',
                    'metadata' => ['contact_id' => $contactId, 'first_message' => 'Paid ' . $money . ' for ' . $order->item_title . ' (online checkout).', 'session_id' => $sessionId, 'website_id' => $order->website_id]]);
                if (! $__cap['created']) DB::table('leads')->where('id', $__cap['lead']->id)->update(['status' => 'converted', 'converted_at' => DB::raw('COALESCE(converted_at, NOW())'), 'deal_value' => DB::raw('COALESCE(deal_value,0) + ' . (float) $order->amount)]);
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
