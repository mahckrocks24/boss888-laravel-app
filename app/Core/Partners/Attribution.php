<?php

namespace App\Core\Partners;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0026 section 4 - who brought this business.
 *
 * levelupgrowth.io/r/{handle}[/{video}] records a click and sets a signed first-party cookie for 60 days. At signup the
 * workspace gets ONE referral: a typed code beats a link, and between links the last one clicked wins. The first paid
 * invoice locks it (Commissions). Self-referral never creates one.
 */
class Attribution
{
    // ------------------------------------------------------------------ the link

    public static function click(Request $r, string $handle, ?string $sub = null)
    {
        $code = PartnerProgram::normalizeCode((string) $r->query('code', ''));
        $to = self::safeTarget((string) $r->query('to', $code ? '/start/' : '/'));   // a link with a code goes straight to sign-up
        if ($code) $to .= (str_contains($to, '?') ? '&' : '?') . 'code=' . $code;
        $resp = (new \Illuminate\Http\RedirectResponse($to, 302))->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
        if (! PartnerProgram::enabled()) return $resp;

        $a = DB::table('affiliates')->where('handle', strtolower($handle))->where('status', 'approved')->first();
        if (! $a) return $resp;
        $sub = $sub !== null ? substr(preg_replace('/[^a-z0-9_-]/', '', strtolower($sub)), 0, 40) : null;
        $ua = (string) $r->userAgent();
        $device = preg_match('/bot|crawl|spider|preview|facebookexternalhit|slurp/i', $ua) ? 'bot' : (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'phone' : (preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : 'desktop'));
        if ($device === 'bot') return $resp;   // link previews are not visits

        $ipHash = hash('sha256', (string) $r->ip() . '|' . config('app.key'));
        $linkId = $sub ? DB::table('affiliate_links')->where('affiliate_id', $a->id)->where('sub_id', $sub)->value('id') : null;
        // one visit per person per half hour: refreshing the page is not a new click
        $recent = DB::table('affiliate_clicks')->where('affiliate_id', $a->id)->where('ip_hash', $ipHash)->where('created_at', '>', now()->subMinutes(30))->orderByDesc('id')->value('id');
        $clickId = $recent;
        if (! $recent) {
            $clickId = DB::table('affiliate_clicks')->insertGetId(['affiliate_id' => $a->id, 'link_id' => $linkId, 'sub_id' => $sub, 'ip_hash' => $ipHash,
                'device' => $device, 'landing' => substr($to, 0, 255), 'referer_host' => substr((string) parse_url((string) $r->headers->get('referer'), PHP_URL_HOST), 0, 120) ?: null, 'created_at' => now()]);
            $burst = DB::table('affiliate_clicks')->where('affiliate_id', $a->id)->where('ip_hash', $ipHash)->where('created_at', '>', now()->subDay())->count();
            if ($burst === 20) PartnerProgram::flag((int) $a->id, 'click_burst', ['ip_hash' => substr($ipHash, 0, 12), 'clicks_24h' => $burst]);
        }
        $value = self::sign(['a' => (int) $a->id, 's' => $sub, 'c' => (int) $clickId, 't' => time()]);
        return $resp->header('Set-Cookie', PartnerProgram::COOKIE . '=' . $value . '; Max-Age=' . (PartnerProgram::COOKIE_DAYS * 86400) . '; Path=/; Secure; HttpOnly; SameSite=Lax', false);
    }

    private static function safeTarget(string $to): string
    {
        // only our own pages: a path, never another site
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\') || preg_match('/[\r\n]/', $to)) return '/';
        return substr($to, 0, 200);
    }

    public static function sign(array $p): string
    {
        $b = rtrim(strtr(base64_encode(json_encode($p)), '+/', '-_'), '=');
        return $b . '.' . substr(hash_hmac('sha256', $b, (string) config('app.key')), 0, 32);
    }

    public static function read(?string $v): ?array
    {
        if (! $v || ! str_contains($v, '.')) return null;
        [$b, $sig] = explode('.', $v, 2);
        if (! hash_equals(substr(hash_hmac('sha256', $b, (string) config('app.key')), 0, 32), $sig)) return null;
        $p = json_decode((string) base64_decode(strtr($b, '-_', '+/')), true);
        if (! is_array($p) || empty($p['a']) || (time() - (int) ($p['t'] ?? 0)) > PartnerProgram::COOKIE_DAYS * 86400) return null;
        return $p;
    }

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
            $c = self::read($r->cookies->get(PartnerProgram::COOKIE));
            if ($c) {
                $a = DB::table('affiliates')->where('id', (int) $c['a'])->where('status', 'approved')->first();
                if ($a) self::attach($wsId, $userId, $a, null, 'link', $c['s'] ?? null, (int) ($c['c'] ?? 0) ?: null);
            }
        } catch (\Throwable $e) {
            Log::warning('[AFF] captureSignup failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * A code typed later (Billing, the domain cart) before the business ever paid: a code beats a link, so it replaces an
     * unlocked link referral. New customers only (D11): once anything was paid, codes no longer apply.
     */
    public static function applyCode(int $wsId, int $userId, string $raw): array
    {
        if (! PartnerProgram::enabled()) return ['ok' => false, 'error' => 'Codes are not available right now.'];
        $res = PartnerProgram::resolveCode($raw);
        if (! $res['ok']) return $res;
        $ref = DB::table('referrals')->where('workspace_id', $wsId)->first();
        if ($ref && $ref->locked_at) return ['ok' => false, 'error' => 'Codes are for new customers. Your account already has its partner terms.'];
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
        } else {
            DB::table('referrals')->insert($row + ['workspace_id' => $wsId, 'created_at' => now()]);
        }
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

    /** The domain discount (bps) for a referred business, within its first 6 months. */
    public static function domainDiscount(int $wsId): ?object
    {
        if (! PartnerProgram::enabled()) return null;
        $ref = DB::table('referrals')->where('workspace_id', $wsId)->whereIn('status', ['signed_up', 'paying', 'ended'])->first();
        if (! $ref || ! $ref->signed_up_at || now()->gt(\Carbon\Carbon::parse($ref->signed_up_at)->addMonths(PartnerProgram::DOMAIN_WINDOW_MONTHS))) return null;
        return $ref;
    }
}
