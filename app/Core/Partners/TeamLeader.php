<?php

namespace App\Core\Partners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0028 / DEC-0085 - Team Leader, the paid upgrade of an affiliate (Owner 2026-10-05).
 *
 * $99 a month. With a Team Leader in the sale the total commission is always 25% (monthly plans, first 6 payments), 20% (yearly,
 * once), 15% (domains, once): on the leader's own referrals the leader splits all of it with the bar; on a recruit's referrals the
 * recruit keeps their 20 / 15 / 10 and the leader gets the 5% (Commissions::teamShare). One level only.
 * Money is only ever earned from customers' payments - never for recruiting, never from the $99 fees.
 *
 * The fee: the first month by card at upgrade (D18). Each renewal is paid from commission on hand first - held money counts (D17),
 * the card pays any shortfall (D16) - by a Stripe customer-balance credit made the day before the renewal. A failed payment gives
 * 30 days to pay or earn it (D20); after that, or at the end of the paid month on a voluntary cancel (D21), the leader goes back
 * to a regular affiliate and the team dissolves.
 */
class TeamLeader
{
    public const FEE_MINOR = 9900;
    public const BUDGET = ['monthly' => 2500, 'yearly' => 2000, 'domain' => 1500];
    public const OVERRIDE_BPS = 500;
    public const GRACE_DAYS = 30;
    private const LIVE = ['active', 'trialing', 'past_due', 'unpaid'];   // Stripe still collecting
    private const PORTAL = 'https://levelupgrowth.io/affiliates/portal';

    // ------------------------------------------------------------------ who is a leader

    public static function inGrace(?object $a): bool
    {
        return $a && $a->tier === 'leader' && $a->leader_grace_until && now()->lt($a->leader_grace_until);
    }

    public static function isActive(?object $a): bool
    {
        if (! $a || $a->tier !== 'leader' || $a->status !== 'approved') return false;
        return in_array((string) $a->leader_status, self::LIVE, true) || self::inGrace($a);
    }

    /** The active Team Leader above an affiliate, or null. */
    public static function leaderOf(int $affiliateId): ?object
    {
        $lid = DB::table('affiliates')->where('id', $affiliateId)->value('leader_id');
        if (! $lid) return null;
        $l = DB::table('affiliates')->where('id', $lid)->first();
        return self::isActive($l) ? $l : null;
    }

    /** A team code typed on an application: the active leader it belongs to. */
    public static function byTeamCode(?string $raw): ?object
    {
        $code = PartnerProgram::normalizeCode($raw);
        if (! $code) return null;
        $l = DB::table('affiliates')->where('team_code', $code)->first();
        return self::isActive($l) ? $l : null;
    }

    /** Commission on hand: everything earned and not yet paid out, held money included (D17). */
    public static function onHand(int $affiliateId): int
    {
        return (int) DB::table('commissions')->where('affiliate_id', $affiliateId)->whereIn('status', ['pending', 'payable'])->sum('amount_minor');
    }

    public static function offer(): array
    {
        return ['fee_minor' => self::FEE_MINOR, 'budgets' => self::BUDGET, 'override_bps' => self::OVERRIDE_BPS, 'grace_days' => self::GRACE_DAYS];
    }

    public static function event(int $leaderId, ?int $recruitId, string $kind, array $data = []): void
    {
        try { DB::table('affiliate_team_events')->insert(['leader_id' => $leaderId, 'recruit_id' => $recruitId, 'kind' => $kind, 'data_json' => $data ? json_encode($data) : null, 'created_at' => now()]); }
        catch (\Throwable $e) { Log::warning('[AFF-TL] event failed', ['kind' => $kind, 'error' => $e->getMessage()]); }
    }

    // ------------------------------------------------------------------ Stripe

    private static function stripe(): ?\Stripe\StripeClient
    {
        $key = (string) config('billing.stripe.secret_key', env('STRIPE_SECRET_KEY', ''));
        return $key === '' ? null : new \Stripe\StripeClient($key);
    }

    private static function mode(): string
    {
        return str_starts_with((string) config('billing.stripe.secret_key', env('STRIPE_SECRET_KEY', '')), 'sk_live') ? 'live' : 'test';
    }

    /** The $99 monthly price, created once per Stripe mode. */
    public static function priceId(): ?string
    {
        $mode = self::mode();
        if ($p = DB::table('affiliate_leader_prices')->where('mode', $mode)->value('price_id')) return $p;
        $sc = self::stripe(); if (! $sc) return null;
        $prod = $sc->products->create(['name' => 'LevelUpGrowth Team Leader', 'metadata' => ['kind' => 'team_leader']]);
        $price = $sc->prices->create(['product' => $prod->id, 'unit_amount' => self::FEE_MINOR, 'currency' => 'usd', 'recurring' => ['interval' => 'month'], 'metadata' => ['kind' => 'team_leader']]);
        DB::table('affiliate_leader_prices')->insertOrIgnore(['mode' => $mode, 'product_id' => $prod->id, 'price_id' => $price->id, 'created_at' => now(), 'updated_at' => now()]);
        return (string) DB::table('affiliate_leader_prices')->where('mode', $mode)->value('price_id');
    }

    private static function customer(\Stripe\StripeClient $sc, object $a, string $email): string
    {
        if ($a->leader_customer_id) return $a->leader_customer_id;
        $cus = $sc->customers->create(['email' => $email, 'name' => $a->display_name, 'metadata' => ['kind' => 'team_leader', 'affiliate_id' => (string) $a->id]])->id;
        DB::table('affiliates')->where('id', $a->id)->update(['leader_customer_id' => $cus, 'updated_at' => now()]);
        return $cus;
    }

    private static function subLive(?object $a): bool
    {
        return $a && in_array((string) $a->leader_status, self::LIVE, true);
    }

    /** Stripe Checkout for the upgrade (first month by card, D18), or to restart a subscription Stripe ended during the grace period. */
    public static function checkout(object $a, string $email): array
    {
        if ($a->status !== 'approved') return ['ok' => false, 'error' => 'Your affiliate account must be approved first.'];
        if (self::subLive($a)) return ['ok' => false, 'error' => 'You are already a Team Leader.'];
        $sc = self::stripe(); if (! $sc) return ['ok' => false, 'error' => 'Payments are not available right now. Nothing was charged.'];
        try {
            $meta = ['kind' => 'team_leader', 'affiliate_id' => (string) $a->id];
            $s = $sc->checkout->sessions->create([
                'mode' => 'subscription', 'customer' => self::customer($sc, $a, $email), 'line_items' => [['price' => self::priceId(), 'quantity' => 1]],
                'allow_promotion_codes' => false, 'metadata' => $meta, 'subscription_data' => ['metadata' => $meta],
                'success_url' => self::PORTAL . '?leader=done#team', 'cancel_url' => self::PORTAL . '?leader=cancel#overview',
            ]);
            return ['ok' => true, 'url' => $s->url];
        } catch (\Throwable $e) {
            Log::warning('[AFF-TL] checkout failed', ['affiliate' => $a->id, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'We could not open the payment page. Nothing was charged. Please try again.'];
        }
    }

    /** Cancel at the end of the paid month (true) or keep going (false). */
    public static function setCancel(object $a, bool $cancel): array
    {
        if (! $a->leader_sub_id || ! self::subLive($a)) return ['ok' => false, 'error' => 'There is no Team Leader plan to change.'];
        $sc = self::stripe(); if (! $sc) return ['ok' => false, 'error' => 'Not available right now.'];
        try {
            $sub = $sc->subscriptions->update($a->leader_sub_id, ['cancel_at_period_end' => $cancel]);
            self::sync($sub);
            if ($cancel) {
                self::reverseCredits((int) $a->id, 'cancelled');   // a credit made for a renewal that will not happen goes back to the commissions
                PartnerEmails::safe(fn () => PartnerEmails::leaderCancelling((int) $a->id));   // L2
                self::event((int) $a->id, null, 'leader_cancelling', ['until' => (string) DB::table('affiliates')->where('id', $a->id)->value('leader_until')]);
            }
            return ['ok' => true, 'cancel_at_end' => (bool) $sub->cancel_at_period_end];
        } catch (\Throwable $e) {
            Log::warning('[AFF-TL] cancel toggle failed', ['affiliate' => $a->id, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Not saved. Please try again.'];
        }
    }

    /** "Pay now" during the grace period: the open invoice's payment page, or a new Checkout when Stripe already ended the subscription. */
    public static function payNow(object $a, string $email): array
    {
        if (! self::inGrace($a)) return ['ok' => false, 'error' => 'Nothing is due.'];
        $sc = self::stripe(); if (! $sc) return ['ok' => false, 'error' => 'Payments are not available right now.'];
        try {
            if ($a->leader_sub_id && self::subLive($a)) {
                $inv = $sc->invoices->all(['subscription' => $a->leader_sub_id, 'status' => 'open', 'limit' => 1])->data[0] ?? null;
                if ($inv && $inv->hosted_invoice_url) return ['ok' => true, 'url' => $inv->hosted_invoice_url];
            }
            return self::checkout($a, $email);
        } catch (\Throwable $e) {
            Log::warning('[AFF-TL] pay now failed', ['affiliate' => $a->id, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'We could not open the payment page. Please try again.'];
        }
    }

    // ------------------------------------------------------------------ webhooks

    /** True when this Stripe event is about a Team Leader subscription: it is handled here and never as a plan. */
    public static function owns(object $event): bool
    {
        $o = $event->data->object ?? null; if (! $o) return false;
        if (($o->metadata->kind ?? null) === 'team_leader') return true;
        if (str_starts_with((string) $event->type, 'invoice.')) {
            if (($o->parent->subscription_details->metadata->kind ?? $o->subscription_details->metadata->kind ?? null) === 'team_leader') return true;
            $sid = $o->subscription ?? ($o->parent->subscription_details->subscription ?? null);
            if (! $sid) foreach (($o->lines->data ?? []) as $l) { $sid = $l->subscription ?? ($l->parent->subscription_item_details->subscription ?? null); if ($sid) break; }
            if (is_object($sid)) $sid = $sid->id ?? null;
            if ($sid && DB::table('affiliates')->where('leader_sub_id', (string) $sid)->exists()) return true;
            return ! empty($o->customer) && DB::table('affiliates')->where('leader_customer_id', (string) $o->customer)->exists() && ! DB::table('subscriptions')->where('stripe_subscription_id', (string) $sid)->exists();
        }
        return false;
    }

    public static function onEvent(object $event): array
    {
        try {
            $o = $event->data->object; $sc = self::stripe();
            if ($event->type === 'checkout.session.completed') {
                if ($sc && ! empty($o->subscription)) self::sync($sc->subscriptions->retrieve(is_string($o->subscription) ? $o->subscription : $o->subscription->id));
            } elseif (str_starts_with((string) $event->type, 'customer.subscription.')) {
                self::sync($o);
            } elseif ($event->type === 'invoice.paid') {
                self::onFeePaid($o);
            } elseif ($event->type === 'invoice.payment_failed') {
                self::onFeeFailed($o);
            }
            return ['handled' => true, 'type' => $event->type, 'team_leader' => true];
        } catch (\Throwable $e) {
            Log::error('[AFF-TL] webhook step failed', ['type' => $event->type ?? null, 'error' => $e->getMessage()]);
            return ['handled' => false, 'type' => $event->type ?? null, 'error' => 'team leader step failed'];
        }
    }

    private static function byCustomer(object $inv): ?object
    {
        return DB::table('affiliates')->where('leader_customer_id', (string) ($inv->customer ?? ''))->first();
    }

    /** A fee invoice was paid (card, credit or both): the grace period ends and the payment is recorded. */
    private static function onFeePaid(object $inv): void
    {
        $a = self::byCustomer($inv); if (! $a) return;
        $fromCredit = max(0, -(int) ($inv->starting_balance ?? 0) + (int) ($inv->ending_balance ?? 0));
        $credited = DB::table('affiliate_leader_fees')->where('affiliate_id', $a->id)->where('status', 'credited')->orderBy('id')->first();
        if ($credited && $fromCredit > 0) {
            DB::table('affiliate_leader_fees')->where('id', $credited->id)->update(['status' => 'applied', 'invoice_id' => (string) $inv->id, 'card_minor' => (int) ($inv->amount_paid ?? 0), 'updated_at' => now()]);
        } elseif (! DB::table('affiliate_leader_fees')->where('affiliate_id', $a->id)->where('invoice_id', (string) $inv->id)->exists() && (int) ($inv->amount_paid ?? 0) > 0) {
            DB::table('affiliate_leader_fees')->insertOrIgnore(['affiliate_id' => $a->id, 'period_key' => 'inv-' . $inv->id, 'from_commission_minor' => 0, 'card_minor' => (int) $inv->amount_paid,
                'invoice_id' => (string) $inv->id, 'status' => 'applied', 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($a->leader_grace_until) {
            DB::table('affiliates')->where('id', $a->id)->update(['leader_grace_until' => null, 'updated_at' => now()]);
            self::event((int) $a->id, null, 'grace_cleared', ['invoice' => $inv->id]);
        }
        if ($sid = ($inv->subscription ?? ($inv->parent->subscription_details->subscription ?? null))) { $sc = self::stripe(); if ($sc) self::sync($sc->subscriptions->retrieve(is_object($sid) ? $sid->id : $sid)); }
    }

    /** A fee payment failed: 30 days to pay or earn it (D20). Commission on hand is tried at once. */
    private static function onFeeFailed(object $inv): void
    {
        $a = self::byCustomer($inv); if (! $a || $a->tier !== 'leader') return;
        if (! $a->leader_grace_until) {
            DB::table('affiliates')->where('id', $a->id)->update(['leader_grace_until' => now()->addDays(self::GRACE_DAYS), 'updated_at' => now()]);
            self::event((int) $a->id, null, 'grace_started', ['invoice' => $inv->id, 'until' => now()->addDays(self::GRACE_DAYS)->toDateString()]);
            PartnerEmails::safe(fn () => PartnerEmails::graceStarted((int) $a->id));   // G1
        }
        self::collectGrace(DB::table('affiliates')->where('id', $a->id)->first());
    }

    /** Bring an affiliate in line with their Stripe subscription. */
    public static function sync(object $sub): void
    {
        $affId = (int) ($sub->metadata->affiliate_id ?? 0);
        $a = $affId ? DB::table('affiliates')->where('id', $affId)->first() : DB::table('affiliates')->where('leader_sub_id', (string) $sub->id)->first();
        if (! $a) { Log::warning('[AFF-TL] subscription without affiliate', ['sub' => $sub->id ?? null]); return; }
        $live = in_array((string) $sub->status, self::LIVE, true);
        // an older subscription ending must not end a newer one
        if ($a->leader_sub_id && $a->leader_sub_id !== $sub->id && ! $live) return;
        $until = $sub->current_period_end ?? ($sub->items->data[0]->current_period_end ?? null);
        $upd = ['leader_sub_id' => (string) $sub->id, 'leader_status' => (string) $sub->status, 'leader_cancel_at_end' => (bool) ($sub->cancel_at_period_end ?? false),
            'leader_until' => $until ? \Carbon\Carbon::createFromTimestamp((int) $until) : $a->leader_until, 'leader_customer_id' => is_string($sub->customer ?? null) ? $sub->customer : $a->leader_customer_id, 'updated_at' => now()];
        if ($live && in_array((string) $sub->status, ['active', 'trialing'], true)) $upd['leader_grace_until'] = null;
        if ($live && $a->tier !== 'leader') {
            $cur = PartnerProgram::budgets($a);
            $upd += ['tier' => 'leader', 'leader_since' => now(), 'pre_leader_budgets' => json_encode($cur), 'team_code' => $a->team_code ?: self::newTeamCode($a),
                'budget_monthly_bps' => max($cur['monthly'], self::BUDGET['monthly']), 'budget_yearly_bps' => max($cur['yearly'], self::BUDGET['yearly']), 'budget_domain_bps' => max($cur['domain'], self::BUDGET['domain'])];
            if ($a->leader_id) $upd['leader_id'] = null;   // D22: a recruit who upgrades leaves their leader's team
            DB::table('affiliates')->where('id', $a->id)->update($upd);
            self::event((int) $a->id, null, 'leader_on', ['sub' => $sub->id]);
            if ($a->leader_id) {
                self::leftTeam((int) $a->leader_id, $a, 'upgraded');
                self::event((int) $a->leader_id, (int) $a->id, 'recruit_upgraded', ['name' => $a->display_name]);
                PartnerEmails::safe(fn () => PartnerEmails::recruitUpgraded((int) $a->leader_id, (string) $a->display_name));   // L5
            }
            Log::info('[AFF-TL] leader on', ['affiliate' => $a->id, 'sub' => $sub->id]);
            PartnerEmails::safe(fn () => PartnerEmails::leaderWelcome((int) $a->id, (string) $sub->id));   // L1
            return;
        }
        DB::table('affiliates')->where('id', $a->id)->update($upd);
        if (! $live && $a->tier === 'leader') {
            $fresh = DB::table('affiliates')->where('id', $a->id)->first();
            if (self::inGrace($fresh)) { self::event((int) $a->id, null, 'sub_ended_in_grace', ['sub' => $sub->id]); return; }   // the 30 days still run
            self::dissolve($fresh, 'cancelled');   // D21: a voluntary cancel ends at the end of the paid month
        }
    }

    private static function newTeamCode(object $a): string
    {
        $base = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $a->handle)), 0, 14) ?: 'LEADER';
        $c = $base . 'TEAM'; $i = 2;
        while (DB::table('affiliates')->where('team_code', $c)->where('id', '!=', $a->id)->exists() || DB::table('vouchers')->where('code', $c)->exists()) $c = $base . 'TEAM' . $i++;
        return $c;
    }

    // ------------------------------------------------------------------ the fee from commissions

    private static function feeRow(int $affId, int $amount, string $key, string $note): int
    {
        return (int) DB::table('commissions')->insertGetId(['affiliate_id' => $affId, 'referral_id' => 0, 'workspace_id' => 0, 'source_type' => 'leader_fee', 'source_id' => substr($key, 0, 80),
            'stripe_event_id' => 'leader-fee:' . $affId . ':' . $key . ($amount > 0 ? ':back' : ''), 'kind' => 'fee', 'base_minor' => 0, 'rate_bps' => 0, 'amount_minor' => $amount, 'currency' => 'USD',
            'status' => 'payable', 'payable_at' => now(), 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * The day before a renewal: take the fee from commission on hand (D17 held money counts; D16 the card pays the rest) by a
     * Stripe customer-balance credit the renewal invoice uses automatically. Once per renewal. $force skips the timing check (tests).
     */
    public static function collectRenewal(object $a, bool $force = false): ?array
    {
        if (! self::subLive($a) || self::inGrace($a) || $a->leader_cancel_at_end || ! $a->leader_until || ! $a->leader_customer_id) return null;
        if (! $force && now()->diffInHours($a->leader_until, false) > 36) return null;
        $key = \Carbon\Carbon::parse($a->leader_until)->format('Y-m-d');
        if (DB::table('affiliate_leader_fees')->where('affiliate_id', $a->id)->where('period_key', $key)->exists()) return null;
        $take = min(self::FEE_MINOR, max(0, self::onHand((int) $a->id)));
        if ($take <= 0) return ['taken' => 0];
        $sc = self::stripe(); if (! $sc) return null;
        $feeId = DB::table('affiliate_leader_fees')->insertOrIgnore(['affiliate_id' => $a->id, 'period_key' => $key, 'from_commission_minor' => $take, 'status' => 'credited', 'created_at' => now(), 'updated_at' => now()]);
        if (! $feeId) return null;
        try {
            $txn = $sc->customers->createBalanceTransaction($a->leader_customer_id, ['amount' => -$take, 'currency' => 'usd', 'description' => 'Team Leader fee paid from affiliate commissions', 'metadata' => ['affiliate_id' => (string) $a->id, 'period' => $key]]);
            $cid = self::feeRow((int) $a->id, -$take, $key, 'Team Leader fee for the month from ' . $key);
            DB::table('affiliate_leader_fees')->where('affiliate_id', $a->id)->where('period_key', $key)->update(['credit_txn_id' => $txn->id, 'commission_id' => $cid, 'updated_at' => now()]);
            self::event((int) $a->id, null, 'fee_from_commissions', ['amount' => $take, 'period' => $key]);
            PartnerEmails::safe(fn () => PartnerEmails::feeFromCommissions((int) $a->id, $take, $key));   // F1
            return ['taken' => $take];
        } catch (\Throwable $e) {
            DB::table('affiliate_leader_fees')->where('affiliate_id', $a->id)->where('period_key', $key)->delete();
            Log::warning('[AFF-TL] renewal credit failed', ['affiliate' => $a->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** In the grace period: commission on hand settles what is owed (all or part), by a credit note on the open invoice. */
    public static function collectGrace(?object $a): ?array
    {
        if (! $a || ! self::inGrace($a)) return null;
        $sc = self::stripe(); if (! $sc) return null;
        $have = max(0, self::onHand((int) $a->id)); if ($have <= 0) return ['taken' => 0];
        try {
            if ($a->leader_sub_id && self::subLive($a)) {
                $inv = $sc->invoices->all(['subscription' => $a->leader_sub_id, 'status' => 'open', 'limit' => 1])->data[0] ?? null;
                if (! $inv || (int) $inv->amount_remaining <= 0) return ['taken' => 0];
                $take = min($have, (int) $inv->amount_remaining); $key = 'grace-' . $inv->id . '-' . ((int) $inv->amount_remaining);
                if (! DB::table('affiliate_leader_fees')->insertOrIgnore(['affiliate_id' => $a->id, 'period_key' => $key, 'from_commission_minor' => $take, 'invoice_id' => $inv->id, 'status' => 'out_of_band', 'created_at' => now(), 'updated_at' => now()])) return null;
                $cid = self::feeRow((int) $a->id, -$take, $key, 'Team Leader fee (overdue) paid from commissions');
                DB::table('affiliate_leader_fees')->where('affiliate_id', $a->id)->where('period_key', $key)->update(['commission_id' => $cid, 'updated_at' => now()]);
                $sc->creditNotes->create(['invoice' => $inv->id, 'amount' => $take, 'memo' => 'Paid from affiliate commissions', 'metadata' => ['affiliate_id' => (string) $a->id]]);
                self::event((int) $a->id, null, 'fee_from_commissions', ['amount' => $take, 'invoice' => $inv->id, 'overdue' => true]);
                return ['taken' => $take];
            }
            // Stripe already ended the subscription: once the commissions cover a whole month, start a new one paid from them
            if ($have < self::FEE_MINOR || ! $a->leader_customer_id) return ['taken' => 0];
            $key = 'grace-restart-' . now()->format('Ymd');
            if (! DB::table('affiliate_leader_fees')->insertOrIgnore(['affiliate_id' => $a->id, 'period_key' => $key, 'from_commission_minor' => self::FEE_MINOR, 'status' => 'credited', 'created_at' => now(), 'updated_at' => now()])) return null;
            $txn = $sc->customers->createBalanceTransaction($a->leader_customer_id, ['amount' => -self::FEE_MINOR, 'currency' => 'usd', 'description' => 'Team Leader fee paid from affiliate commissions']);
            $cid = self::feeRow((int) $a->id, -self::FEE_MINOR, $key, 'Team Leader fee (overdue) paid from commissions');
            DB::table('affiliate_leader_fees')->where('affiliate_id', $a->id)->where('period_key', $key)->update(['credit_txn_id' => $txn->id, 'commission_id' => $cid, 'updated_at' => now()]);
            $meta = ['kind' => 'team_leader', 'affiliate_id' => (string) $a->id];
            $sub = $sc->subscriptions->create(['customer' => $a->leader_customer_id, 'items' => [['price' => self::priceId()]], 'metadata' => $meta, 'payment_behavior' => 'allow_incomplete']);
            self::sync($sub);
            return ['taken' => self::FEE_MINOR, 'restarted' => $sub->id];
        } catch (\Throwable $e) {
            Log::warning('[AFF-TL] grace collection failed', ['affiliate' => $a->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** A credit made for a renewal that will not happen goes back to the affiliate's commissions. */
    private static function reverseCredits(int $affId, string $why): void
    {
        $sc = self::stripe();
        foreach (DB::table('affiliate_leader_fees')->where('affiliate_id', $affId)->where('status', 'credited')->get() as $f) {
            try {
                $cus = DB::table('affiliates')->where('id', $affId)->value('leader_customer_id');
                if ($sc && $cus && $f->credit_txn_id) $sc->customers->createBalanceTransaction($cus, ['amount' => (int) $f->from_commission_minor, 'currency' => 'usd', 'description' => 'Team Leader fee credit returned (' . $why . ')']);
                self::feeRow($affId, (int) $f->from_commission_minor, $f->period_key, 'Team Leader fee returned (' . $why . ')');
                DB::table('affiliate_leader_fees')->where('id', $f->id)->update(['status' => 'reversed', 'updated_at' => now()]);
            } catch (\Throwable $e) { Log::warning('[AFF-TL] credit reversal failed', ['fee' => $f->id, 'error' => $e->getMessage()]); }
        }
    }

    // ------------------------------------------------------------------ the end of a team

    /** Back to a regular affiliate; the team dissolves. Money already earned stays. */
    public static function dissolve(object $a, string $why): void
    {
        if ($a->tier !== 'leader') return;
        $sc = self::stripe();
        if ($sc && $a->leader_sub_id && self::subLive($a)) { try { $sc->subscriptions->cancel($a->leader_sub_id); } catch (\Throwable $e) { Log::warning('[AFF-TL] cancel on dissolve failed', ['affiliate' => $a->id, 'error' => $e->getMessage()]); } }
        self::reverseCredits((int) $a->id, $why);
        $pre = json_decode((string) $a->pre_leader_budgets, true) ?: PartnerProgram::BUDGET;
        $b = ['monthly' => (int) ($pre['monthly'] ?? 2000), 'yearly' => (int) ($pre['yearly'] ?? 1500), 'domain' => (int) ($pre['domain'] ?? 1000)];
        DB::table('affiliates')->where('id', $a->id)->update(['tier' => 'affiliate', 'leader_status' => 'canceled', 'leader_grace_until' => null, 'leader_cancel_at_end' => false,
            'budget_monthly_bps' => $b['monthly'], 'budget_yearly_bps' => $b['yearly'], 'budget_domain_bps' => $b['domain'], 'updated_at' => now()]);
        // codes keep working, their discount trimmed to the budget they now have (customers who joined keep their terms)
        foreach ($b as $k => $max) DB::table('vouchers')->where('affiliate_id', $a->id)->where('discount_' . $k . '_bps', '>', $max)->update(['discount_' . $k . '_bps' => $max, 'updated_at' => now()]);
        $recruits = DB::table('affiliates')->where('leader_id', $a->id)->pluck('id')->all();
        DB::table('affiliates')->where('leader_id', $a->id)->update(['leader_id' => null, 'leader_recommendation' => null, 'leader_rec_note' => null, 'updated_at' => now()]);
        DB::table('affiliate_team_invites')->where('leader_id', $a->id)->where('status', 'sent')->update(['status' => 'withdrawn', 'updated_at' => now()]);
        self::event((int) $a->id, null, 'team_dissolved', ['why' => $why, 'recruits' => count($recruits)]);
        self::returnToOldLeader($a);
        Log::info('[AFF-TL] team dissolved', ['affiliate' => $a->id, 'why' => $why, 'recruits' => count($recruits)]);
        PartnerEmails::safe(fn () => PartnerEmails::teamDissolved((int) $a->id, $why));   // G4
        foreach ($recruits as $rid) PartnerEmails::safe(fn () => PartnerEmails::teamEndedForRecruit((int) $rid, (string) $a->display_name));   // G5
    }

    /** D22: a member leaves a team (upgraded, removed, moved); the leader keeps the records, badged and frozen at this day. */
    public static function leftTeam(int $leaderId, object $m, string $reason): void
    {
        try {
            DB::table('affiliate_team_history')->insert(['leader_id' => $leaderId, 'recruit_id' => $m->id, 'reason' => $reason, 'joined_at' => $m->approved_at ?? null, 'left_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) { Log::warning('[AFF-TL] team history failed', ['error' => $e->getMessage()]); }
    }

    /** Owner: "if it rolls back to regular affiliate he goes back to his old TL" - when that leader is still a Team Leader. */
    private static function returnToOldLeader(object $a): void
    {
        $h = DB::table('affiliate_team_history')->where('recruit_id', $a->id)->where('reason', 'upgraded')->whereNull('returned_at')->orderByDesc('id')->first();
        if (! $h) return;
        $old = DB::table('affiliates')->where('id', $h->leader_id)->first();
        DB::table('affiliate_team_history')->where('id', $h->id)->update(['returned_at' => now(), 'updated_at' => now()]);
        if (! self::isActive($old)) return;
        DB::table('affiliates')->where('id', $a->id)->update(['leader_id' => $old->id, 'updated_at' => now()]);
        self::event((int) $old->id, (int) $a->id, 'recruit_returned', ['name' => $a->display_name]);
        Log::info('[AFF-TL] back on the old team', ['affiliate' => $a->id, 'leader' => $old->id]);
        PartnerEmails::safe(fn () => PartnerEmails::recruitReturned((int) $old->id, (int) $a->id));   // L6
    }

    /** Daily (partners:leaders): renewals from commissions, the grace period (collect, remind, end). */
    public static function daily(): array
    {
        $n = ['renewal_credits' => 0, 'grace_collected' => 0, 'reminders' => 0, 'dissolved' => 0];
        foreach (DB::table('affiliates')->where('tier', 'leader')->get() as $a) {
            if (self::inGrace($a)) {
                $r = self::collectGrace($a); if (($r['taken'] ?? 0) > 0) $n['grace_collected']++;
                $left = (int) ceil(now()->diffInHours($a->leader_grace_until, false) / 24);
                if (in_array($left, [7, 1], true)) $n['reminders'] += (int) PartnerEmails::graceReminder((int) $a->id, $left);   // G2 / G3
                continue;
            }
            if ($a->leader_grace_until && now()->gte($a->leader_grace_until)) { self::dissolve($a, 'unpaid'); $n['dissolved']++; continue; }
            $r = self::collectRenewal($a); if (($r['taken'] ?? 0) > 0) $n['renewal_credits']++;
        }
        return $n;
    }
}
