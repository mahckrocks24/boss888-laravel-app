<?php

namespace App\Core\Partners;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0026 section 4 - who brought this business.
 *
 * CODES ONLY (Owner 2026-10-05: "remove the custom URL and just do vouchers, I realized it triggers spamming and we will
 * need to install cookie which we do not do at the moment"). There are no affiliate links, no click tracking and no
 * cookie: a business belongs to an affiliate only when it types (or is handed) that affiliate's code - at sign-up, or in
 * Billing / the domain cart before its first payment. The first paid invoice locks it (Commissions). Self-referral
 * never creates one.
 */
class Attribution
{
    // ------------------------------------------------------------------ signup

    /** Called right after a new account and its workspace exist. Never throws: signup must never fail because of this. */
    public static function captureSignup(int $userId, Request $r): void
    {
        if (! PartnerProgram::enabled()) return;
        try {
            $wsId = (int) DB::table('workspace_users')->where('user_id', $userId)->where('role', 'owner')->orderBy('workspace_id')->value('workspace_id');
            if (! $wsId) return;
            $code = (string) ($r->input('promo_code') ?: $r->input('code') ?: '');
            if ($code !== '') {
                $res = PartnerProgram::resolveCode($code);
                if ($res['ok']) { self::attach($wsId, $userId, $res['affiliate'], $res['voucher'], 'voucher', null, null); return; }
            }
        } catch (\Throwable $e) {
            Log::warning('[AFF] captureSignup failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A code typed later (Billing, the domain cart) before the business ever paid: the latest code typed before the first
     * payment counts. New customers only (D11): once anything was paid, codes no longer apply.
     */
    public static function applyCode(int $wsId, int $userId, string $raw): array
    {
        if (! PartnerProgram::enabled()) return ['ok' => false, 'error' => 'Codes are not available right now.'];
        $res = PartnerProgram::resolveCode($raw);
        if (! $res['ok']) return $res;
        $ref = DB::table('referrals')->where('workspace_id', $wsId)->first();
        if ($ref && $ref->locked_at) return ['ok' => false, 'error' => 'Codes are for new customers. Your account already has its affiliate terms.'];
        if ($ref && $ref->voucher_id && (int) $ref->voucher_id === (int) $res['voucher']->id) return ['ok' => true, 'already' => true, 'message' => PartnerProgram::describe($res['voucher'])];
        $paid = DB::table('subscriptions')->where('workspace_id', $wsId)->whereNotNull('stripe_subscription_id')->exists()
            || DB::table('domain_orders')->where('workspace_id', $wsId)->whereNotNull('paid_at')->exists();
        if ($paid && $res['voucher']->new_customers_only) return ['ok' => false, 'error' => 'Codes are for new customers.'];
        $made = self::attach($wsId, $userId, $res['affiliate'], $res['voucher'], 'voucher', null, null, true);
        return $made ? ['ok' => true, 'message' => PartnerProgram::describe($res['voucher'])] : ['ok' => false, 'error' => 'That code cannot be used on this account.'];
    }

    private static function attach(int $wsId, int $userId, ?object $a, ?object $v, string $source, ?string $sub, ?int $clickId, bool $replace = false): bool
    {
        if ($a && (int) $a->user_id === $userId) {   // self-referral
            PartnerProgram::flag((int) $a->id, 'self_referral', ['user_id' => $userId, 'source' => $source], $wsId);
            return false;
        }
        if ($a && DB::table('workspace_users')->where('workspace_id', $wsId)->where('user_id', $a->user_id)->exists()) {
            PartnerProgram::flag((int) $a->id, 'self_referral', ['user_id' => $userId, 'reason' => 'affiliate is a member', 'source' => $source], $wsId);
            return false;
        }
        $t = PartnerProgram::terms($a, $v);
        $row = $t + ['user_id' => $userId, 'affiliate_id' => $a->id ?? null, 'voucher_id' => $v->id ?? null, 'source' => $source, 'sub_id' => $sub,
            'click_id' => $clickId, 'status' => 'signed_up', 'signed_up_at' => now(), 'updated_at' => now()];
        $exists = DB::table('referrals')->where('workspace_id', $wsId)->first();
        if ($exists) {
            if (! $replace || $exists->locked_at) return false;
            DB::table('referrals')->where('id', $exists->id)->update($row);
            $refId = (int) $exists->id;
        } else {
            $refId = (int) DB::table('referrals')->insertGetId($row + ['workspace_id' => $wsId, 'created_at' => now()]);
        }
        PartnerEmails::safe(function () use ($a, $v, $refId) {   // A5 to the affiliate, C1 to the customer
            if ($a) PartnerEmails::firstSignup((int) $a->id, $refId);
            if ($v) PartnerEmails::customerCode($refId);
        });
        Log::info('[AFF] referral', ['ws' => $wsId, 'affiliate' => $a->id ?? null, 'voucher' => $v->code ?? null, 'source' => $source]);
        return true;
    }

    // ------------------------------------------------------------------ checkout

    /** The referral whose discount still applies to a first plan checkout, or null. */
    public static function planDiscount(int $wsId, string $kind = 'monthly'): ?array
    {
        if (! PartnerProgram::enabled()) return null;
        $ref = DB::table('referrals')->where('workspace_id', $wsId)->whereIn('status', ['signed_up'])->first();
        if (! $ref) return null;
        $bps = (int) ($kind === 'yearly' ? $ref->discount_yearly_bps : $ref->discount_monthly_bps);
        if ($bps <= 0) return ['referral_id' => (int) $ref->id, 'coupon' => null, 'bps' => 0];
        $coupon = PartnerProgram::stripeCoupon($bps, $kind === 'yearly' ? 'once' : 'repeating', (int) $ref->months);
        return ['referral_id' => (int) $ref->id, 'coupon' => $coupon, 'bps' => $coupon ? $bps : 0];
    }

    /** The domain discount (bps) for a referred business, within its first year (domains are yearly). */
    public static function domainDiscount(int $wsId): ?object
    {
        if (! PartnerProgram::enabled()) return null;
        $ref = DB::table('referrals')->where('workspace_id', $wsId)->whereIn('status', ['signed_up', 'paying', 'ended'])->first();
        if (! $ref || ! $ref->signed_up_at || now()->gt(\Carbon\Carbon::parse($ref->signed_up_at)->addMonths(PartnerProgram::DOMAIN_WINDOW_MONTHS))) return null;
        return $ref;
    }
}
