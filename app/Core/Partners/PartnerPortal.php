<?php

namespace App\Core\Partners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RFC-0026 section 8 - what an affiliate sees about their own work, and nothing else: their clicks, the businesses they
 * referred (masked email, plan, status, what they earned), their links and codes, their money. Never a customer's
 * content, contacts or website.
 */
class PartnerPortal
{
    public static function maskEmail(?string $e): string
    {
        if (! $e || ! str_contains($e, '@')) return 'hidden';
        [$u, $d] = explode('@', $e, 2);
        return mb_substr($u, 0, 1) . '***@' . $d;
    }

    public static function handleFrom(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($name))), '-') ?: 'partner';
        $base = substr($base, 0, 24); $h = $base; $i = 2;
        while (DB::table('affiliates')->where('handle', $h)->exists() || in_array(strtoupper(str_replace('-', '', $h)), PartnerProgram::RESERVED, true)) $h = substr($base, 0, 20) . '-' . $i++;
        return $h;
    }

    public static function me(object $user, ?object $a): array
    {
        $out = ['user' => ['name' => $user->name, 'email' => $user->email, 'verified' => $user->email_verified_at !== null], 'partner' => null,
            'budgets' => PartnerProgram::BUDGET, 'yearly_live' => PartnerProgram::yearlyLive(), 'months' => PartnerProgram::MONTHLY_CYCLES,
            'hold_days' => PartnerProgram::HOLD_DAYS, 'min_payout' => PartnerProgram::MIN_PAYOUT_MINOR, 'max_codes' => PartnerProgram::MAX_CODES,
            'has_workspace' => DB::table('workspace_users')->where('user_id', $user->id)->exists()];
        if ($a) {
            $out['partner'] = ['handle' => $a->handle, 'display_name' => $a->display_name, 'status' => $a->status, 'channel_url' => $a->channel_url,
                'link' => 'https://levelupgrowth.io/r/' . $a->handle, 'budgets' => PartnerProgram::budgets($a),
                'payout_method' => $a->payout_method, 'payouts_enabled' => (bool) $a->payouts_enabled, 'decision_note' => $a->status === 'rejected' ? $a->decision_note : null,
                'since' => $a->approved_at];
        }
        return $out;
    }

    public static function overview(object $a, int $days): array
    {
        $from = $days > 0 ? now()->subDays($days) : null;
        $w = fn ($q, $col) => $from ? $q->where($col, '>=', $from) : $q;
        $clicks = $w(DB::table('affiliate_clicks')->where('affiliate_id', $a->id), 'created_at')->count();
        $signups = $w(DB::table('referrals')->where('affiliate_id', $a->id)->where('status', '!=', 'void'), 'signed_up_at')->count();
        // paying = still on a paid plan now (a business that cancelled no longer counts)
        $paying = DB::table('referrals as r')->where('r.affiliate_id', $a->id)->where('r.status', 'paying')->whereExists(fn ($q) => $q->from('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->whereColumn('s.workspace_id', 'r.workspace_id')->whereIn('s.status', ['active', 'trialing', 'past_due'])->where('p.price', '>', 0))->count();
        $earned = (int) $w(DB::table('commissions')->where('affiliate_id', $a->id), 'created_at')->sum('amount_minor');
        $money = [];
        foreach (['pending', 'payable', 'paid'] as $s) $money[$s] = (int) DB::table('commissions')->where('affiliate_id', $a->id)->where('status', $s)->sum('amount_minor');
        $bySub = [];
        foreach ($w(DB::table('affiliate_clicks')->where('affiliate_id', $a->id), 'created_at')->selectRaw("COALESCE(sub_id, '') s, COUNT(*) n")->groupBy('s')->get() as $r) $bySub[$r->s] = ['sub' => $r->s, 'clicks' => (int) $r->n, 'signups' => 0, 'paying' => 0, 'earned' => 0];
        foreach ($w(DB::table('referrals')->where('affiliate_id', $a->id)->where('status', '!=', 'void'), 'signed_up_at')->get(['id', 'sub_id', 'status']) as $r) {
            $k = (string) $r->sub_id; $bySub[$k] ??= ['sub' => $k, 'clicks' => 0, 'signups' => 0, 'paying' => 0, 'earned' => 0];
            $bySub[$k]['signups']++; if ($r->status === 'paying') $bySub[$k]['paying']++;
            $bySub[$k]['earned'] += (int) DB::table('commissions')->where('referral_id', $r->id)->sum('amount_minor');
        }
        usort($bySub, fn ($x, $y) => [$y['earned'], $y['clicks']] <=> [$x['earned'], $x['clicks']]);
        return ['days' => $days, 'clicks' => $clicks, 'signups' => $signups, 'paying' => $paying, 'conversion' => $clicks ? round($signups / $clicks * 100, 1) : 0,
            'earned_minor' => $earned, 'money' => $money, 'by_sub' => array_values($bySub)];
    }

    public static function referrals(object $a): array
    {
        $rows = DB::table('referrals as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')->leftJoin('vouchers as v', 'v.id', '=', 'r.voucher_id')
            ->where('r.affiliate_id', $a->id)->orderByDesc('r.id')->limit(500)
            ->get(['r.id', 'r.workspace_id', 'r.source', 'r.sub_id', 'r.status', 'r.signed_up_at', 'r.cycles_paid', 'r.months', 'u.email', 'v.code']);
        return $rows->map(function ($r) {
            $plan = DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')->where('s.workspace_id', $r->workspace_id)
                ->whereIn('s.status', ['active', 'trialing', 'past_due'])->where('p.price', '>', 0)->orderByDesc('s.id')->first(['p.name', 's.status']);
            return ['id' => $r->id, 'who' => self::maskEmail($r->email), 'joined' => $r->signed_up_at, 'via' => $r->source === 'voucher' ? ('code ' . $r->code) : 'link', 'video' => $r->sub_id,
                'plan' => $plan->name ?? 'Free', 'trial' => ($plan->status ?? '') === 'trialing',
                // a business that stopped paying shows as Cancelled, not Paying
                'status' => ($r->status === 'paying' && ! $plan) ? 'Cancelled' : (['signed_up' => 'Signed up', 'paying' => 'Paying', 'ended' => 'Earning ended', 'void' => 'Not counted'][$r->status] ?? $r->status),
                'payments' => (int) $r->cycles_paid . ' of ' . (int) $r->months,
                'earned_minor' => (int) DB::table('commissions')->where('referral_id', $r->id)->sum('amount_minor')];
        })->all();
    }

    public static function links(object $a): array
    {
        $rows = DB::table('affiliate_links')->where('affiliate_id', $a->id)->orderBy('sub_id')->get();
        return ['main' => 'https://levelupgrowth.io/r/' . $a->handle, 'links' => $rows->map(fn ($l) => ['id' => $l->id, 'sub' => $l->sub_id, 'label' => $l->label,
            'url' => 'https://levelupgrowth.io/r/' . $a->handle . '/' . $l->sub_id,
            'clicks' => DB::table('affiliate_clicks')->where('affiliate_id', $a->id)->where('sub_id', $l->sub_id)->count()])->all()];
    }

    public static function addLink(object $a, string $sub, ?string $label): array
    {
        $s = substr(trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower($sub)), '-'), 0, 40);
        if ($s === '') return ['ok' => false, 'error' => 'Give the link a short name, like the video title.'];
        if (DB::table('affiliate_links')->where('affiliate_id', $a->id)->count() >= 200) return ['ok' => false, 'error' => 'You have 200 links. Remove one first.'];
        if (DB::table('affiliate_links')->where('affiliate_id', $a->id)->where('sub_id', $s)->exists()) return ['ok' => false, 'error' => 'You already have a link called ' . $s . '.'];
        DB::table('affiliate_links')->insert(['affiliate_id' => $a->id, 'sub_id' => $s, 'label' => $label ? mb_substr($label, 0, 120) : null, 'created_at' => now(), 'updated_at' => now()]);
        return ['ok' => true, 'url' => 'https://levelupgrowth.io/r/' . $a->handle . '/' . $s];
    }

    public static function codes(object $a): array
    {
        return DB::table('vouchers')->where('affiliate_id', $a->id)->where('status', '!=', 'archived')->orderByDesc('id')->get()->map(fn ($v) => [
            'id' => $v->id, 'code' => $v->code, 'status' => $v->status, 'redemptions' => (int) $v->redemptions, 'max' => $v->max_redemptions, 'ends_at' => $v->ends_at,
            'discount' => ['monthly' => (int) $v->discount_monthly_bps, 'yearly' => (int) $v->discount_yearly_bps, 'domain' => (int) $v->discount_domain_bps],
            'commission' => PartnerProgram::terms($a, $v), 'describe' => PartnerProgram::describe($v),
            'share' => 'https://levelupgrowth.io/r/' . $a->handle . '?code=' . $v->code,
        ])->all();
    }

    public static function saveCode(object $a, array $in, ?int $id = null): array
    {
        $v = null;
        if ($id) { $v = DB::table('vouchers')->where('id', $id)->where('affiliate_id', $a->id)->first(); if (! $v) return ['ok' => false, 'error' => 'Code not found.']; }
        // an edit that only pauses or resumes keeps the split, the end date and the limit it had
        $sp = PartnerProgram::split($a, ['monthly' => (int) ($in['monthly'] ?? $v->discount_monthly_bps ?? 0), 'yearly' => (int) ($in['yearly'] ?? $v->discount_yearly_bps ?? 0), 'domain' => (int) ($in['domain'] ?? $v->discount_domain_bps ?? 0)]);
        if (! $sp['ok']) return $sp;
        $ends = array_key_exists('ends_at', $in) ? (! empty($in['ends_at']) ? \Carbon\Carbon::parse($in['ends_at'])->endOfDay() : null) : ($v->ends_at ?? null);
        if ($ends && array_key_exists('ends_at', $in) && \Carbon\Carbon::parse($ends)->isPast()) return ['ok' => false, 'error' => 'The end date has passed.'];
        $max = array_key_exists('max', $in) ? ($in['max'] !== '' && $in['max'] !== null ? max(1, (int) $in['max']) : null) : ($v->max_redemptions ?? null);
        $row = ['discount_monthly_bps' => $sp['discount']['monthly'], 'discount_yearly_bps' => $sp['discount']['yearly'], 'discount_domain_bps' => $sp['discount']['domain'],
            'applies_plans' => 1, 'applies_domains' => 1, 'months' => PartnerProgram::MONTHLY_CYCLES, 'ends_at' => $ends, 'max_redemptions' => $max, 'updated_at' => now()];
        if ($id) {
            if (isset($in['status']) && in_array($in['status'], ['active', 'paused', 'archived'], true)) $row['status'] = $in['status'];
            DB::table('vouchers')->where('id', $id)->update($row);   // existing customers keep the terms they joined on (snapshotted on the referral)
            return ['ok' => true, 'id' => $id];
        }
        $code = PartnerProgram::normalizeCode($in['code'] ?? '');
        if (! $code) return ['ok' => false, 'error' => 'Use 4 to 20 letters and numbers, no spaces.'];
        if ($why = PartnerProgram::reservedReason($code)) return ['ok' => false, 'error' => $why];
        if (DB::table('vouchers')->where('code', $code)->exists()) return ['ok' => false, 'error' => 'That code is taken. Try another.'];
        if (DB::table('vouchers')->where('affiliate_id', $a->id)->where('status', 'active')->count() >= PartnerProgram::MAX_CODES) return ['ok' => false, 'error' => 'You have ' . PartnerProgram::MAX_CODES . ' active codes. Pause one first.'];
        $id = DB::table('vouchers')->insertGetId($row + ['code' => $code, 'affiliate_id' => $a->id, 'label' => mb_substr((string) ($in['label'] ?? ''), 0, 120) ?: null, 'status' => 'active', 'redemptions' => 0, 'new_customers_only' => 1, 'created_by' => $a->user_id, 'created_at' => now()]);
        return ['ok' => true, 'id' => $id, 'code' => $code];
    }

    public static function money(object $a): array
    {
        $bal = []; foreach (['pending', 'payable', 'paid'] as $s) $bal[$s] = (int) DB::table('commissions')->where('affiliate_id', $a->id)->where('status', $s)->sum('amount_minor');
        $next = DB::table('commissions')->where('affiliate_id', $a->id)->where('status', 'pending')->min('payable_at');
        $rows = DB::table('commissions')->where('affiliate_id', $a->id)->orderByDesc('id')->limit(200)->get(['id', 'kind', 'base_minor', 'rate_bps', 'amount_minor', 'status', 'payable_at', 'created_at', 'note', 'referral_id']);
        $payouts = DB::table('payouts')->where('affiliate_id', $a->id)->orderByDesc('id')->limit(36)->get(['id', 'period', 'method', 'amount_minor', 'status', 'sent_at', 'reference']);
        return ['balances' => $bal, 'next_release' => $next, 'min_payout' => PartnerProgram::MIN_PAYOUT_MINOR, 'payout_method' => $a->payout_method, 'payouts_enabled' => (bool) $a->payouts_enabled,
            'commissions' => $rows, 'payouts' => $payouts];
    }
}
