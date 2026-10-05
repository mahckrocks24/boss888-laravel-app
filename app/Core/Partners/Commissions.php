<?php

namespace App\Core\Partners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0026 section 6 - the commission ledger, written from the Stripe events we already receive.
 *
 * - invoice.paid: a referred plan payment. Monthly plans earn on their first 6 billing cycles (prorated upgrade invoices
 *   inside that window earn too, without counting as a cycle); a yearly plan earns once.
 * - checkout.session.completed for a domain order: new registrations in the referral's first year, on their first-year price.
 * - charge.refunded / charge.dispute.created: a reversal row with a negative amount (netted from the next payout).
 * Every row is keyed by the Stripe event id, so a replayed webhook never pays twice. Rows are held 30 days, then payable.
 * Commission is a share of the price BEFORE the customer's discount and before tax (D1).
 */
class Commissions
{
    /** Run a partner step without ever affecting billing. */
    public static function safe(callable $fn): void
    {
        if (! PartnerProgram::enabled()) return;
        try { $fn(new self()); } catch (\Throwable $e) { Log::error('[AFF] commission step failed', ['error' => $e->getMessage()]); }
    }

    public function onInvoicePaid(object $event): ?int
    {
        $inv = $event->data->object;
        $subId = $this->subscriptionOf($inv);
        if (! $subId || (int) ($inv->amount_paid ?? 0) <= 0) return null;
        $wsId = (int) DB::table('subscriptions')->where('stripe_subscription_id', $subId)->orderByDesc('id')->value('workspace_id');
        if (! $wsId) return null;
        $ref = DB::table('referrals')->where('workspace_id', $wsId)->whereIn('status', ['signed_up', 'paying'])->first();
        if (! $ref) return null;

        $reason = (string) ($inv->billing_reason ?? '');
        $isCycle = in_array($reason, ['subscription_create', 'subscription_cycle'], true);
        if (! $isCycle && $reason !== 'subscription_update') return null;
        $kind = $this->intervalOf($inv) === 'year' ? 'yearly' : 'monthly';
        $limit = $kind === 'yearly' ? 1 : max(1, (int) $ref->months);
        if ($isCycle && (int) $ref->cycles_paid >= $limit) { $this->end($ref, 'window over'); return null; }
        if (! $isCycle && (int) $ref->cycles_paid < 1) return null;   // an update before the first paid cycle earns nothing

        $this->lock($ref, $wsId, 'plan', (string) ($inv->id ?? ''), (int) $this->discountOf($inv));
        if ($isCycle) DB::table('referrals')->where('id', $ref->id)->increment('cycles_paid');
        if (! $ref->affiliate_id) return null;   // a house promotion: discount only
        if ($this->selfReferral($ref, $wsId)) return null;

        $rate = (int) ($kind === 'yearly' ? $ref->commission_yearly_bps : $ref->commission_monthly_bps);
        $base = max(0, (int) ($inv->subtotal ?? 0));   // before the invoice discount and before tax
        if ($rate <= 0 || $base <= 0) return null;
        $id = $this->write([
            'affiliate_id' => $ref->affiliate_id, 'referral_id' => $ref->id, 'workspace_id' => $wsId, 'source_type' => 'invoice', 'source_id' => (string) $inv->id,
            'stripe_event_id' => (string) $event->id, 'payment_intent_id' => $this->piOf($inv), 'kind' => $kind, 'base_minor' => $base, 'rate_bps' => $rate,
            'amount_minor' => (int) round($base * $rate / 10000), 'currency' => strtoupper((string) ($inv->currency ?? 'usd')),
            'cycle_no' => $isCycle ? (int) $ref->cycles_paid + 1 : null, 'note' => $isCycle ? null : 'plan change in the window',
        ]);
        if ($isCycle && (int) $ref->cycles_paid + 1 >= $limit) $this->end($ref, 'window complete');
        return $id;
    }

    public function onDomainPaid(object $event, object $session): ?int
    {
        if (($session->payment_status ?? 'paid') !== 'paid') return null;
        $orderId = (int) ($session->metadata->domain_order_id ?? 0);
        if (! $orderId) return null;
        $order = DB::table('domain_orders')->where('id', $orderId)->first();
        if (! $order) return null;
        $ref = Attribution::domainDiscount((int) $order->workspace_id);
        $meta = json_decode((string) $order->metadata_json, true) ?: [];
        if ($ref && ! empty($meta['partner']['saved_minor'])) $this->lock($ref, (int) $order->workspace_id, 'domain', 'order-' . $orderId, (int) $meta['partner']['saved_minor'], false);
        if (! $ref || ! $ref->affiliate_id || (int) $ref->commission_domain_bps <= 0) return null;
        if ($this->selfReferral($ref, (int) $order->workspace_id)) return null;

        $base = 0; $cap = 0;
        foreach (DB::table('domain_order_items')->where('domain_order_id', $orderId)->get() as $it) {
            if (($it->action ?? 'register') !== 'register' || (int) $it->retail_minor <= (int) $it->registrar_cost_minor) continue;   // promos below cost earn nothing
            $base += (int) round($it->retail_minor / max(1, (int) $it->years)); $cap += (int) $it->markup_minor;   // AFF-DOMAIN-1: the first year only
        }
        if ($base <= 0) return null;
        $rate = (int) $ref->commission_domain_bps;
        $saved = (int) ($meta['partner']['saved_minor'] ?? 0);
        $amount = min((int) round($base * $rate / 10000), max(0, $cap - $saved));   // never below our cost
        if ($amount <= 0) return null;
        return $this->write([
            'affiliate_id' => $ref->affiliate_id, 'referral_id' => $ref->id, 'workspace_id' => $order->workspace_id, 'source_type' => 'domain_order', 'source_id' => (string) $orderId,
            'stripe_event_id' => (string) $event->id, 'payment_intent_id' => is_string($session->payment_intent ?? null) ? $session->payment_intent : null,
            'kind' => 'domain', 'base_minor' => $base, 'rate_bps' => $rate, 'amount_minor' => $amount, 'currency' => strtoupper((string) ($order->currency ?: 'USD')),
        ]);
    }

    /** A refund or a dispute: reverse the matching commissions in proportion (all of it for a dispute). */
    public function onRefundOrDispute(object $event): int
    {
        $o = $event->data->object;
        $isDispute = str_starts_with((string) $event->type, 'charge.dispute');
        $pi = is_string($o->payment_intent ?? null) ? $o->payment_intent : null;
        $invId = is_string($o->invoice ?? null) ? $o->invoice : null;
        $ratio = 1.0;
        if (! $isDispute) {
            $amt = (int) ($o->amount ?? 0); $ref = (int) ($o->amount_refunded ?? 0);
            if ($amt <= 0 || $ref <= 0) return 0;
            $ratio = min(1.0, $ref / $amt);
        }
        $q = DB::table('commissions')->where('amount_minor', '>', 0)->where(function ($w) use ($pi, $invId) {
            if ($pi) $w->orWhere('payment_intent_id', $pi);
            if ($invId) $w->orWhere(fn ($x) => $x->where('source_type', 'invoice')->where('source_id', $invId));
        });
        if (! $pi && ! $invId) return 0;
        $n = 0;
        foreach ($q->get() as $c) {
            // a partial refund that grows (refund 30%, later 100%) reverses only the part not yet reversed
            $already = (int) -DB::table('commissions')->where('reverses_id', $c->id)->sum('amount_minor');
            $target = (int) round($c->amount_minor * $ratio);
            $delta = $target - $already;
            if ($delta <= 0) continue;
            $this->write([
                'affiliate_id' => $c->affiliate_id, 'referral_id' => $c->referral_id, 'workspace_id' => $c->workspace_id, 'source_type' => 'adjustment',
                'source_id' => (string) ($o->id ?? ''), 'stripe_event_id' => $event->id . ':' . $c->id, 'payment_intent_id' => $c->payment_intent_id,
                'kind' => $c->kind, 'base_minor' => 0, 'rate_bps' => 0, 'amount_minor' => -$delta, 'currency' => $c->currency, 'reverses_id' => $c->id,
                'status' => $c->status === 'paid' ? 'payable' : $c->status, 'payable_at' => $c->status === 'paid' ? now() : $c->payable_at,
                'note' => $isDispute ? 'dispute' : 'refund',
            ]);
            $n++;
            if ($isDispute) PartnerProgram::flag((int) $c->affiliate_id, 'dispute', ['commission_id' => $c->id, 'charge' => $o->charge ?? $o->id ?? null], (int) $c->workspace_id);
        }
        return $n;
    }

    /** Daily: held commissions become payable after 30 days. */
    public static function release(): int
    {
        return DB::table('commissions')->where('status', 'pending')->where('payable_at', '<=', now())->update(['status' => 'payable', 'updated_at' => now()]);
    }

    // ------------------------------------------------------------------ helpers

    private function write(array $row): ?int
    {
        $row += ['status' => 'pending', 'payable_at' => now()->addDays(PartnerProgram::HOLD_DAYS), 'created_at' => now(), 'updated_at' => now()];
        try {
            $id = (int) DB::table('commissions')->insertGetId($row);
            PartnerEmails::safe(fn () => PartnerEmails::commission($id));   // A6 first commission, A8 a reversal
            Log::info('[AFF] commission', ['id' => $id, 'affiliate' => $row['affiliate_id'], 'amount' => $row['amount_minor'], 'kind' => $row['kind']]);
            return $id;
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) return null;   // this event was already counted
            throw $e;
        }
    }

    private function lock(object $ref, int $wsId, string $kind, string $refKey, int $saved, bool $setPaying = true): void
    {
        $upd = ['updated_at' => now()];
        if (! $ref->locked_at) $upd['locked_at'] = now();
        if ($setPaying && $ref->status === 'signed_up') $upd['status'] = 'paying';
        DB::table('referrals')->where('id', $ref->id)->update($upd);
        if ($ref->voucher_id) {
            $ins = DB::table('voucher_redemptions')->insertOrIgnore(['voucher_id' => $ref->voucher_id, 'workspace_id' => $wsId, 'kind' => $kind, 'ref' => substr($refKey, 0, 80), 'amount_saved_minor' => $saved, 'created_at' => now()]);
            $first = DB::table('voucher_redemptions')->where('voucher_id', $ref->voucher_id)->where('workspace_id', $wsId)->count() === 1;
            if ($ins && $first) DB::table('vouchers')->where('id', $ref->voucher_id)->increment('redemptions');   // a business counts once
        }
    }

    private function end(object $ref, string $why): void
    {
        DB::table('referrals')->where('id', $ref->id)->update(['status' => 'ended', 'void_reason' => $why, 'updated_at' => now()]);
    }

    private function selfReferral(object $ref, int $wsId): bool
    {
        $uid = DB::table('affiliates')->where('id', $ref->affiliate_id)->value('user_id');
        if ($uid && DB::table('workspace_users')->where('workspace_id', $wsId)->where('user_id', $uid)->exists()) {
            DB::table('referrals')->where('id', $ref->id)->update(['status' => 'void', 'void_reason' => 'self-referral', 'updated_at' => now()]);
            PartnerProgram::flag((int) $ref->affiliate_id, 'self_referral', ['reason' => 'affiliate joined the workspace'], $wsId);
            return true;
        }
        return false;
    }

    private function intervalOf(object $inv): string
    {
        foreach (($inv->lines->data ?? []) as $l) {
            $iv = $l->price->recurring->interval ?? null;
            if ($iv) return (string) $iv;
            if (! empty($l->plan->interval)) return (string) $l->plan->interval;
        }
        return 'month';
    }

    private function discountOf(object $inv): int
    {
        $s = 0;
        foreach (($inv->total_discount_amounts ?? []) as $d) $s += (int) ($d->amount ?? 0);
        return $s;
    }

    private function subscriptionOf(object $inv): ?string
    {
        // the same reading StripeService uses: older API versions put it on the invoice, newer ones under parent / lines
        $id = $inv->subscription ?? ($inv->parent->subscription_details->subscription ?? null);
        if (! $id) foreach (($inv->lines->data ?? []) as $l) { $id = $l->subscription ?? ($l->parent->subscription_item_details->subscription ?? null); if ($id) break; }
        if (is_object($id)) $id = $id->id ?? null;
        return $id ? (string) $id : null;
    }

    /** The payment intent that paid the invoice, so a refund on it can be matched. Webhooks may omit it; the invoice is then read back from Stripe. */
    private function piOf(object $inv): ?string
    {
        if (is_string($inv->payment_intent ?? null)) return $inv->payment_intent;
        foreach (($inv->payments->data ?? []) as $p) { if (is_string($p->payment->payment_intent ?? null)) return $p->payment->payment_intent; }
        try {
            $key = (string) config('billing.stripe.secret_key', env('STRIPE_SECRET_KEY', ''));
            if ($key === '' || empty($inv->id)) return null;
            // webhooks arrive in the account's newer API version (no payment_intent on the invoice); the library's pinned
            // version still returns it on a plain retrieve
            $pi = (new \Stripe\StripeClient($key))->invoices->retrieve($inv->id)->payment_intent ?? null;
            if (is_object($pi)) $pi = $pi->id ?? null;
            if ($pi) return (string) $pi;
        } catch (\Throwable $e) { Log::info('[AFF] invoice payment lookup skipped', ['error' => $e->getMessage()]); }
        return null;
    }
}
