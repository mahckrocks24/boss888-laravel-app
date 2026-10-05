<?php

namespace App\Core\Partners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0026 - the Affiliate Program's rules in one place (Owner 2026-10-05).
 *
 * Every referred payment carries a budget the affiliate splits between the customer's discount and their own commission:
 *   monthly plans 20% (the first 6 monthly payments), yearly plans 15% (once), domain registrations 10%.
 * Discount + commission always equals that product's budget, chosen in whole percent. A link alone gives the affiliate the
 * full budget. House promotions (no affiliate) are discount only. Behind storage/app/aff1.on; off = checkout as before.
 */
class PartnerProgram
{
    public const BUDGET = ['monthly' => 2000, 'yearly' => 1500, 'domain' => 1000];   // basis points
    public const MONTHLY_CYCLES = 6;
    public const HOLD_DAYS = 30;
    public const DOMAIN_WINDOW_MONTHS = 12;    // AFF-DOMAIN-1: domains are yearly - new registrations in a referred business's first year earn, on their first year
    public const MAX_CODES = 10;
    public const MIN_PAYOUT_MINOR = 5000;      // $50
    public const RESERVED = ['LEVELUP', 'LEVELUPGROWTH', 'LUG', 'SARAH', 'ARIA', 'ADMIN', 'SUPPORT', 'FREE', 'TEST', 'STAFF', 'OFFICIAL', 'STRIPE', 'REFUND', 'BILLING', 'PARTNER', 'PARTNERS', 'AFFILIATE'];

    /** Yearly plans are not on sale yet (RFC-0026 P0 waits for the yearly price, D12): codes do not mention them until then. */
    public static function yearlyLive(): bool
    {
        return is_file(storage_path('app/yearly1.on'));
    }

    public static function enabled(): bool
    {
        return is_file(storage_path('app/aff1.on'));
    }

    /** "chef red 10" -> "CHEFRED10"; null when it can never be a code. */
    public static function normalizeCode(?string $code): ?string
    {
        $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
        return preg_match('/^[A-Z0-9]{4,20}$/', $c) ? $c : null;
    }

    public static function reservedReason(string $code): ?string
    {
        foreach (self::RESERVED as $w) {
            if (str_contains($code, $w)) return 'Codes cannot contain "' . $w . '".';
        }
        return null;
    }

    public static function budgets(?object $affiliate): array
    {
        if (! $affiliate) return ['monthly' => 0, 'yearly' => 0, 'domain' => 0];
        return ['monthly' => (int) $affiliate->budget_monthly_bps, 'yearly' => (int) $affiliate->budget_yearly_bps, 'domain' => (int) $affiliate->budget_domain_bps];
    }

    /**
     * The split for an affiliate's code: $discounts = ['monthly' => bps, 'yearly' => bps, 'domain' => bps].
     * Returns ['ok' => true, 'discount' => [...], 'commission' => [...]] or ['ok' => false, 'error' => ...].
     */
    public static function split(object $affiliate, array $discounts): array
    {
        $budget = self::budgets($affiliate); $d = []; $c = [];
        foreach (['monthly', 'yearly', 'domain'] as $k) {
            $v = (int) ($discounts[$k] ?? 0);
            if ($v < 0 || $v % 100 !== 0) return ['ok' => false, 'error' => 'Choose the discount in whole percent.'];
            if ($v > $budget[$k]) return ['ok' => false, 'error' => 'The ' . $k . ' discount can be at most ' . ($budget[$k] / 100) . '%.'];
            $d[$k] = $v; $c[$k] = $budget[$k] - $v;
        }
        return ['ok' => true, 'discount' => $d, 'commission' => $c];
    }

    /** The terms a customer joins on: from a code, or from a link alone (full budget to the affiliate). */
    public static function terms(?object $affiliate, ?object $voucher): array
    {
        $budget = self::budgets($affiliate);
        $disc = [
            'monthly' => $voucher && $voucher->applies_plans ? (int) $voucher->discount_monthly_bps : 0,
            'yearly'  => $voucher && $voucher->applies_plans ? (int) $voucher->discount_yearly_bps : 0,
            'domain'  => $voucher && $voucher->applies_domains ? (int) $voucher->discount_domain_bps : 0,
        ];
        $out = ['months' => $voucher ? max(1, (int) $voucher->months) : self::MONTHLY_CYCLES];
        foreach (['monthly', 'yearly', 'domain'] as $k) {
            if ($affiliate) $disc[$k] = min($disc[$k], $budget[$k]);   // never more than that product's budget
            $out['discount_' . $k . '_bps'] = $disc[$k];
            $out['commission_' . $k . '_bps'] = $affiliate ? max(0, $budget[$k] - $disc[$k]) : 0;
        }
        if ($affiliate) $out['months'] = self::MONTHLY_CYCLES;
        return $out;
    }

    /** A code a customer typed, checked: ['ok' => true, 'voucher' => row, 'affiliate' => row|null] or an error sentence. */
    public static function resolveCode(?string $raw): array
    {
        $code = self::normalizeCode($raw);
        if (! $code) return ['ok' => false, 'error' => 'That code is not valid.'];
        $v = DB::table('vouchers')->where('code', $code)->first();
        if (! $v || $v->status !== 'active') return ['ok' => false, 'error' => 'That code is not valid.'];
        if ($v->starts_at && now()->lt($v->starts_at)) return ['ok' => false, 'error' => 'That code is not active yet.'];
        if ($v->ends_at && now()->gt($v->ends_at)) return ['ok' => false, 'error' => 'That code has ended.'];
        if ($v->max_redemptions !== null && (int) $v->redemptions >= (int) $v->max_redemptions) return ['ok' => false, 'error' => 'That code has been fully used.'];
        $a = null;
        if ($v->affiliate_id) {
            $a = DB::table('affiliates')->where('id', $v->affiliate_id)->first();
            if (! $a || $a->status !== 'approved') return ['ok' => false, 'error' => 'That code is not valid.'];
        }
        return ['ok' => true, 'voucher' => $v, 'affiliate' => $a];
    }

    /** What a customer is told about a code, in words. */
    public static function describe(object $voucher): string
    {
        $p = []; $m = (int) $voucher->discount_monthly_bps; $y = (int) $voucher->discount_yearly_bps; $d = (int) $voucher->discount_domain_bps;
        if ($voucher->applies_plans && $m) $p[] = ($m / 100) . '% off your first ' . (int) $voucher->months . ' monthly payments';
        if ($voucher->applies_plans && $y && self::yearlyLive()) $p[] = ($y / 100) . '% off a yearly plan';
        if ($voucher->applies_domains && $d) $p[] = ($d / 100) . '% off new domains';
        return $p ? ucfirst(implode(', ', $p)) . '.' : 'This code links you to your affiliate. It gives no discount.';
    }

    /**
     * The Stripe coupon for a percentage: repeating for 6 months on monthly plans, once on yearly plans. Created once per
     * percentage and mode, then reused. Returns null when Stripe is not configured or the call fails (checkout then
     * runs without a discount rather than not at all - the customer is never blocked).
     */
    public static function stripeCoupon(int $bps, string $duration, int $months = self::MONTHLY_CYCLES): ?string
    {
        if ($bps <= 0) return null;
        $key = (string) config('billing.stripe.secret_key', env('STRIPE_SECRET_KEY', ''));
        if ($key === '') return null;
        $mode = str_starts_with($key, 'sk_live') ? 'live' : 'test';
        $dur = $duration === 'once' ? 'once' : 'repeating' . $months;
        $row = DB::table('partner_stripe_coupons')->where(['percent_bps' => $bps, 'duration' => $dur, 'mode' => $mode])->value('coupon_id');
        if ($row) return $row;
        try {
            $sc = new \Stripe\StripeClient($key);
            $params = ['percent_off' => $bps / 100, 'name' => 'Affiliate discount ' . ($bps / 100) . '%', 'metadata' => ['kind' => 'partner', 'bps' => (string) $bps, 'duration' => $dur]];
            $params += $duration === 'once' ? ['duration' => 'once'] : ['duration' => 'repeating', 'duration_in_months' => $months];
            $c = $sc->coupons->create($params);
            DB::table('partner_stripe_coupons')->insertOrIgnore(['percent_bps' => $bps, 'duration' => $dur, 'mode' => $mode, 'coupon_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);
            return (string) DB::table('partner_stripe_coupons')->where(['percent_bps' => $bps, 'duration' => $dur, 'mode' => $mode])->value('coupon_id');
        } catch (\Throwable $e) {
            Log::warning('[AFF] coupon create failed', ['bps' => $bps, 'duration' => $dur, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public static function flag(int $affiliateId, string $kind, array $evidence, ?int $workspaceId = null): void
    {
        try {
            DB::table('affiliate_flags')->insert(['affiliate_id' => $affiliateId, 'workspace_id' => $workspaceId, 'kind' => $kind,
                'evidence_json' => json_encode($evidence), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            PartnerEmails::tellAdmin('Affiliate flag: ' . str_replace('_', ' ', $kind), 'Affiliate #' . $affiliateId . ($workspaceId ? ', workspace ' . $workspaceId : '') . '. Review it in Admin, Affiliates, Flags.');   // M2
        } catch (\Throwable $e) { Log::warning('[AFF] flag failed', ['error' => $e->getMessage()]); }
    }

    /** RFC-0028 D11 / D19: who approves applications - 'bella' or 'manual' (an admin switch). */
    public static function approvalMode(): string
    {
        return BellaReviewer::enabled() ? 'bella' : 'manual';
    }

    /**
     * One door for every application decision, by an admin or by Bella ($by). Approval stays with LevelUpGrowth (D11): a Team
     * Leader only recommends. A suspended or closed Team Leader's team dissolves and their subscription stops.
     */
    public static function decide(int $id, string $action, ?string $note, ?int $actorId, string $by = 'admin'): array
    {
        $a = DB::table('affiliates')->where('id', $id)->first();
        if (! $a) return ['ok' => false, 'error' => 'Not found', 'code' => 404];
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reinstate' => 'approved', 'close' => 'closed'];
        if (! isset($map[$action])) return ['ok' => false, 'error' => 'Unknown action', 'code' => 422];
        if ($by === 'bella' && $a->status !== 'pending') return ['ok' => false, 'error' => 'Already decided', 'code' => 409];
        $to = $map[$action];
        $upd = ['status' => $to, 'decision_note' => $note, 'decided_by' => $by, 'updated_at' => now()];
        if ($action === 'approve' && ! $a->approved_at) { $upd['approved_at'] = now(); $upd['approved_by'] = $actorId; }
        $leader = $a->leader_id ? DB::table('affiliates')->where('id', $a->leader_id)->first() : null;
        if ($action === 'approve' && $a->leader_id && ! TeamLeader::isActive($leader)) { $upd['leader_id'] = null; $leader = null; }   // that team has ended: they join as a regular affiliate
        DB::table('affiliates')->where('id', $id)->update($upd);
        // a suspended or closed affiliate's codes stop working at once; their earned money stays in the ledger for review
        if (in_array($to, ['suspended', 'closed'], true)) {
            DB::table('vouchers')->where('affiliate_id', $id)->where('status', 'active')->update(['status' => 'paused', 'updated_at' => now()]);
            if ($a->tier === 'leader') TeamLeader::dissolve(DB::table('affiliates')->where('id', $id)->first(), $to);
        }
        try {
            DB::table('audit_logs')->insert(['workspace_id' => null, 'user_id' => $actorId, 'action' => 'affiliates.' . $action, 'entity_type' => 'Affiliate', 'entity_id' => $id,
                'metadata_json' => json_encode(['from' => $a->status, 'to' => $to, 'note' => $note, 'by' => $by]), 'created_at' => now()]);
        } catch (\Throwable $e) { Log::warning('[AFF] decision audit failed', ['error' => $e->getMessage()]); }
        if ($leader && in_array($action, ['approve', 'reject'], true)) {
            TeamLeader::event((int) $leader->id, $id, $action === 'approve' ? 'recruit_approved' : 'recruit_rejected', ['name' => $a->display_name]);
            if ($action === 'approve') PartnerEmails::safe(fn () => PartnerEmails::recruitJoined((int) $leader->id, $id));   // L3
        }
        PartnerEmails::safe(fn () => PartnerEmails::decided($id, $to, $note));   // A2 / A2r
        return ['ok' => true, 'status' => $to, 'from' => $a->status];
    }

    public static function money(int $minor): string
    {
        return '$' . number_format($minor / 100, 2);
    }
}
