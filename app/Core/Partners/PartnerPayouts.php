<?php

namespace App\Core\Partners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0026 section 7 - paying partners.
 *
 * Two routes, one ledger:
 *   - Stripe Connect Express (bank payouts handled by Stripe): behind storage/app/aff-connect.on. On 2026-10-05 Connect
 *     is NOT enabled on the platform's Stripe account (test mode refuses to create connected accounts) and the platform
 *     account is registered in the UAE, so the countries it can pay out to are confirmed when the Owner enables it.
 *   - Recorded payouts (PayPal or Wise, sent by hand from /admin): working now. The run creates a payout row per
 *     partner; admin sends the money and records its reference, which marks the commissions paid and tells the partner.
 * A partner is paid when their ready ("payable") balance, net of reversals, is at least $50. Smaller balances roll over.
 */
class PartnerPayouts
{
    public const METHODS = ['stripe' => 'Bank account', 'paypal' => 'PayPal', 'wise' => 'Wise'];

    public static function connectEnabled(): bool
    {
        return is_file(storage_path('app/aff-connect.on'));
    }

    private static function stripe(): \Stripe\StripeClient
    {
        return new \Stripe\StripeClient((string) config('billing.stripe.secret_key', env('STRIPE_SECRET_KEY', '')));
    }

    public static function methods(): array
    {
        $m = self::METHODS;
        if (! self::connectEnabled()) unset($m['stripe']);
        return $m;
    }

    /** PayPal or Wise: the email the money goes to. Stripe goes through onboarding instead. */
    public static function setManual(object $a, string $method, string $email): array
    {
        if (! in_array($method, ['paypal', 'wise'], true)) return ['ok' => false, 'error' => 'Choose PayPal or Wise.'];
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'Enter the email of your ' . self::METHODS[$method] . ' account.'];
        DB::table('affiliates')->where('id', $a->id)->update(['payout_method' => $method, 'payout_email' => $email, 'payouts_enabled' => 1, 'updated_at' => now()]);
        return ['ok' => true, 'method' => $method];
    }

    /** Stripe Express onboarding: create the connected account once, then hand over Stripe's own onboarding page. */
    public static function stripeStart(object $a, string $email, string $country): array
    {
        if (! self::connectEnabled()) return ['ok' => false, 'error' => 'Bank payouts open soon. Choose PayPal or Wise for now.'];
        $country = strtoupper(substr($country, 0, 2));
        try {
            $sc = self::stripe(); $acct = $a->payout_account_id;
            if (! $acct) {
                $platform = (string) $sc->accounts->retrieve()->country;
                $p = ['type' => 'express', 'country' => $country, 'email' => $email, 'capabilities' => ['transfers' => ['requested' => true]],
                    'business_profile' => ['product_description' => 'LevelUpGrowth partner commissions'], 'metadata' => ['affiliate_id' => (string) $a->id]];
                if ($country !== $platform) $p['tos_acceptance'] = ['service_agreement' => 'recipient'];   // cross-border payouts
                $acct = $sc->accounts->create($p)->id;
                DB::table('affiliates')->where('id', $a->id)->update(['payout_account_id' => $acct, 'payout_method' => 'stripe', 'payouts_enabled' => 0, 'updated_at' => now()]);
            }
            $link = $sc->accountLinks->create(['account' => $acct, 'type' => 'account_onboarding',
                'refresh_url' => 'https://levelupgrowth.io/partners/portal?payout=retry#money', 'return_url' => 'https://levelupgrowth.io/partners/portal?payout=back#money']);
            return ['ok' => true, 'url' => $link->url];
        } catch (\Throwable $e) {
            Log::warning('[AFF] stripe onboarding failed', ['affiliate' => $a->id, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'Bank payouts are not available for that country yet. Choose PayPal or Wise.'];
        }
    }

    /** Read the connected account back: can it receive payouts, and what is Stripe still asking for? */
    public static function stripeRefresh(object $a): array
    {
        if (! $a->payout_account_id || ! self::connectEnabled()) return ['ready' => (bool) $a->payouts_enabled, 'due' => []];
        try {
            $acc = self::stripe()->accounts->retrieve($a->payout_account_id);
            $ready = (bool) ($acc->payouts_enabled ?? false);
            DB::table('affiliates')->where('id', $a->id)->update(['payouts_enabled' => $ready ? 1 : 0, 'updated_at' => now()]);
            return ['ready' => $ready, 'due' => array_values((array) ($acc->requirements->currently_due ?? []))];
        } catch (\Throwable $e) { return ['ready' => (bool) $a->payouts_enabled, 'due' => [], 'error' => 'Could not reach the payout provider.']; }
    }

    public static function stripeDashboard(object $a): ?string
    {
        if (! $a->payout_account_id || ! self::connectEnabled()) return null;
        try { return self::stripe()->accounts->createLoginLink($a->payout_account_id)->url; } catch (\Throwable $e) { return null; }
    }

    // ------------------------------------------------------------------ the monthly run (admin)

    public static function preview(): array
    {
        $rows = DB::table('commissions as c')->join('affiliates as a', 'a.id', '=', 'c.affiliate_id')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('c.status', 'payable')->whereNull('c.payout_id')
            ->groupBy('a.id', 'a.display_name', 'a.status', 'a.payout_method', 'a.payout_email', 'a.payouts_enabled', 'a.payout_account_id', 'u.email')
            ->selectRaw('a.id, a.display_name, a.status, a.payout_method, a.payout_email, a.payouts_enabled, a.payout_account_id, u.email, SUM(c.amount_minor) amount, COUNT(*) n')->get();
        return $rows->map(function ($r) {
            $why = null;
            if ($r->status !== 'approved') $why = 'Partner is ' . $r->status;
            elseif ((int) $r->amount < PartnerProgram::MIN_PAYOUT_MINOR) $why = 'Under ' . PartnerProgram::money(PartnerProgram::MIN_PAYOUT_MINOR) . ', rolls over';
            elseif (! $r->payout_method) $why = 'No payout method set';
            elseif ($r->payout_method === 'stripe' && (! self::connectEnabled() || ! $r->payouts_enabled)) $why = 'Bank account not ready';
            return ['affiliate_id' => (int) $r->id, 'name' => $r->display_name, 'email' => $r->email, 'method' => $r->payout_method, 'to' => $r->payout_method === 'stripe' ? 'Stripe ' . $r->payout_account_id : $r->payout_email,
                'amount_minor' => (int) $r->amount, 'commissions' => (int) $r->n, 'eligible' => $why === null, 'reason' => $why];
        })->sortByDesc('amount_minor')->values()->all();
    }

    /**
     * Create this period's payouts for every eligible partner. A Stripe partner is paid at once by transfer; a PayPal or
     * Wise partner gets a payout waiting for admin to send and record. Idempotent per partner per period.
     */
    public static function run(string $period, int $adminId): array
    {
        $out = ['sent' => 0, 'waiting' => 0, 'skipped' => 0, 'failed' => 0, 'total_minor' => 0];
        foreach (self::preview() as $p) {
            if (! $p['eligible']) { $out['skipped']++; continue; }
            $key = 'payout-' . $p['affiliate_id'] . '-' . $period;
            if (DB::table('payouts')->where('idempotency_key', $key)->exists()) { $out['skipped']++; continue; }
            $ids = DB::table('commissions')->where('affiliate_id', $p['affiliate_id'])->where('status', 'payable')->whereNull('payout_id')->pluck('id')->all();
            $amount = (int) DB::table('commissions')->whereIn('id', $ids)->sum('amount_minor');
            if ($amount < PartnerProgram::MIN_PAYOUT_MINOR) { $out['skipped']++; continue; }
            $pid = DB::table('payouts')->insertGetId(['affiliate_id' => $p['affiliate_id'], 'period' => $period, 'method' => $p['method'], 'amount_minor' => $amount, 'currency' => 'USD',
                'status' => 'draft', 'idempotency_key' => $key, 'commission_ids' => json_encode($ids), 'created_by' => $adminId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('commissions')->whereIn('id', $ids)->update(['payout_id' => $pid, 'updated_at' => now()]);   // locked to this payout
            if ($p['method'] === 'stripe') {
                $a = DB::table('affiliates')->where('id', $p['affiliate_id'])->first();
                try {
                    $t = self::stripe()->transfers->create(['amount' => $amount, 'currency' => 'usd', 'destination' => $a->payout_account_id, 'description' => 'LevelUpGrowth partner commissions ' . $period,
                        'metadata' => ['affiliate_id' => (string) $a->id, 'payout_id' => (string) $pid, 'period' => $period]], ['idempotency_key' => $key]);
                    self::markPaid($pid, (string) $t->id, 'stripe');
                    $out['sent']++; $out['total_minor'] += $amount;
                } catch (\Throwable $e) {
                    DB::table('payouts')->where('id', $pid)->update(['status' => 'failed', 'note' => substr($e->getMessage(), 0, 255), 'updated_at' => now()]);
                    DB::table('commissions')->where('payout_id', $pid)->update(['payout_id' => null, 'updated_at' => now()]);   // back to ready for the next run
                    PartnerEmails::safe(fn () => PartnerEmails::payoutProblem($pid));
                    $out['failed']++;
                }
            } else { $out['waiting']++; $out['total_minor'] += $amount; }
        }
        return $out;
    }

    /** Admin sent a PayPal or Wise payment: record its reference. */
    public static function record(int $payoutId, string $reference, int $adminId): array
    {
        $p = DB::table('payouts')->where('id', $payoutId)->first();
        if (! $p) return ['ok' => false, 'error' => 'Not found.'];
        if ($p->status !== 'draft') return ['ok' => false, 'error' => 'This payout is ' . $p->status . '.'];
        self::markPaid($payoutId, $reference, $p->method);
        return ['ok' => true];
    }

    public static function cancel(int $payoutId): array
    {
        $p = DB::table('payouts')->where('id', $payoutId)->first();
        if (! $p || $p->status !== 'draft') return ['ok' => false, 'error' => 'Only a payout waiting to be sent can be cancelled.'];
        DB::table('commissions')->where('payout_id', $payoutId)->update(['payout_id' => null, 'updated_at' => now()]);
        DB::table('payouts')->where('id', $payoutId)->update(['status' => 'cancelled', 'updated_at' => now(), 'idempotency_key' => $p->idempotency_key . '-x' . time()]);
        return ['ok' => true];
    }

    private static function markPaid(int $payoutId, string $reference, string $method): void
    {
        DB::transaction(function () use ($payoutId, $reference, $method) {
            DB::table('payouts')->where('id', $payoutId)->update(['status' => 'paid', ($method === 'stripe' ? 'stripe_transfer_id' : 'reference') => substr($reference, 0, 120), 'sent_at' => now(), 'updated_at' => now()]);
            DB::table('commissions')->where('payout_id', $payoutId)->update(['status' => 'paid', 'updated_at' => now()]);
        });
        PartnerEmails::safe(fn () => PartnerEmails::payoutSent($payoutId));
    }
}
