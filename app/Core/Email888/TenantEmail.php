<?php

namespace App\Core\Email888;

use App\Core\Brand\WorkspaceBrandKitResolver;
use Illuminate\Support\Facades\DB;

/**
 * EMAIL-BRAND-1 (Owner 2026-09-27): "Clearly separate LevelUpGrowth branded email notifications intact with its own
 * branding while the white labeled email notifications that go out to the clients of users use their respective
 * branding at all times."
 *
 * Two audiences, decided by the purpose registry (config/email888.php 'audience'):
 *   platform — LevelUpGrowth speaking to its own users (password reset, verification, billing, alerts, digests).
 *              Untouched: LevelUpGrowth name, address and templates.
 *   tenant   — a business speaking to ITS customers (booking confirmations, form replies, campaigns, sequences,
 *              newsletters, CV-ready mail). From name = the business; Reply-To = the business's own inbox, never
 *              LevelUpGrowth support; body wrapped in the business's brand (logo or wordmark, colours, footer).
 *              LevelUpGrowth is never named.
 *
 * The From ADDRESS must sit on a domain the provider has verified. Until a neutral white-label sending domain is
 * configured (EMAIL888_TENANT_DOMAIN) the platform address carries the business's display name.
 */
final class TenantEmail
{
    /** @return array{business_id:?int,name:string,address:string,reply_to:?string,kit:array,website:?string,phone:?string,address_line:?string} */
    public static function identity(?int $wsId, ?int $businessId = null): array
    {
        $kit = $wsId ? app(WorkspaceBrandKitResolver::class)->resolve($wsId, $businessId) : [];
        $biz = null;
        if ($wsId) {
            $q = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at');
            $biz = ($businessId ? (clone $q)->where('id', $businessId)->first() : null) ?: (clone $q)->orderByDesc('is_default')->orderBy('id')->first();
        }
        $name = trim((string) ($biz->name ?? $kit['brand_name'] ?? ''));
        if ($name === '' || stripos($name, 'levelup') !== false) $name = 'Customer care';

        // The business's own inbox for replies: profile email > its website's contact email > the workspace owner.
        $valid = fn ($e) => is_string($e) && filter_var(trim($e), FILTER_VALIDATE_EMAIL) && ! str_ends_with(strtolower(trim($e)), '@levelupgrowth.io') ? trim($e) : null;
        $reply = $valid($biz->email ?? null);
        $site = null;
        if ($wsId) {
            $sq = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at');
            $site = ($biz ? (clone $sq)->where('business_id', $biz->id)->orderByDesc('published_at')->first() : null) ?: (clone $sq)->orderByDesc('published_at')->first();
            if (! $reply && $site) {
                $tv = json_decode((string) ($site->template_variables ?? ''), true) ?: [];
                $st = json_decode((string) ($site->settings_json ?? ''), true) ?: [];
                foreach ([$tv['contact_email'] ?? null, $tv['email'] ?? null, $st['contact_email'] ?? null, $st['email'] ?? null] as $c) { if ($reply = $valid($c)) break; }
            }
            if (! $reply) {
                $owner = DB::table('workspace_users as wu')->join('users as u', 'u.id', '=', 'wu.user_id')->where('wu.workspace_id', $wsId)->where('wu.role', 'owner')->orderBy('wu.id')->value('u.email');
                $reply = $valid($owner);
            }
        }
        $host = $site ? ($site->custom_domain ?: $site->subdomain) : null;
        $website = $host ? 'https://' . preg_replace('#^https?://#', '', rtrim((string) $host, '/')) : null;

        $domain = trim((string) config('email888.tenant.domain', ''));
        $local = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'hello';
        $address = $domain !== '' ? substr($local, 0, 40) . '@' . $domain : (string) (config('email888.senders.platform.address') ?: 'hello@levelupgrowth.io');

        $addr = null;
        if (! empty($biz->address_json)) { $a = json_decode((string) $biz->address_json, true); if (is_array($a)) $addr = implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $a))); }
        return ['business_id' => $biz ? (int) $biz->id : null, 'name' => $name, 'address' => $address, 'reply_to' => $reply, 'kit' => $kit, 'website' => $website,
            'phone' => ($biz->phone ?? null) ?: null, 'address_line' => $addr ?: ($biz->location ?? null)];
    }

    /**
     * The business-branded email layout. $bodyHtml is trusted HTML from the caller (escape user text before passing it).
     * No LevelUpGrowth name, colour or link appears.
     */
    public static function layout(array $id, string $title, string $bodyHtml, ?array $cta = null, ?string $unsubscribeUrl = null, ?string $preheader = null): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $k = $id['kit'] ?? [];
        $plat = fn ($c) => ! is_string($c) || WorkspaceBrandKitResolver::isPlatformColor($c) || ! preg_match('/^#[0-9a-f]{6}$/i', $c);
        $primary = $plat($k['primary_color'] ?? null) ? '#1F2937' : $k['primary_color'];
        $accent = $plat($k['secondary_color'] ?? null) ? $primary : $k['secondary_color'];
        $lum = function ($h) { $h = ltrim($h, '#'); $c = array_map(fn ($x) => hexdec($x) / 255, str_split($h, 2)); $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c); return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; };
        $onPrimary = $lum($primary) > 0.45 ? '#111111' : '#FFFFFF';
        $btn = $lum($accent) > 0.6 || $lum($accent) < 0.02 ? $primary : $accent;
        $onBtn = $lum($btn) > 0.45 ? '#111111' : '#FFFFFF';
        $font = trim(explode(',', (string) ($k['heading_font'] ?? 'Georgia'))[0], " '\"");
        $logo = is_string($k['logo_url'] ?? null) && preg_match('#^https?://#', $k['logo_url']) ? $k['logo_url'] : (is_string($k['logo_url'] ?? null) && str_starts_with($k['logo_url'], '/') ? rtrim((string) config('app.url'), '/') . $k['logo_url'] : null);
        $mark = $logo ? '<img src="' . $e($logo) . '" alt="' . $e($id['name']) . '" style="max-height:44px;max-width:220px;display:block;border:0">'
            : '<span style="font-family:\'' . $e($font) . '\',Georgia,\'Times New Roman\',serif;font-size:22px;font-weight:700;color:' . $onPrimary . ';letter-spacing:.01em">' . $e($id['name']) . '</span>';
        $ctaHtml = $cta && ! empty($cta['url']) ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 4px"><tr><td style="border-radius:8px;background:' . $btn . '"><a href="' . $e($cta['url']) . '" style="display:inline-block;padding:12px 22px;font:600 15px Arial,Helvetica,sans-serif;color:' . $onBtn . ';text-decoration:none;border-radius:8px">' . $e($cta['label'] ?? 'Open') . '</a></td></tr></table>' : '';
        $foot = array_filter([$e($id['name']), $id['address_line'] ? $e($id['address_line']) : null, $id['phone'] ? $e($id['phone']) : null,
            $id['website'] ? '<a href="' . $e($id['website']) . '" style="color:#6B7280">' . $e(preg_replace('#^https?://#', '', $id['website'])) . '</a>' : null]);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F3F4F6">' . ($preheader ? '<div style="display:none;max-height:0;overflow:hidden">' . $e($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F3F4F6"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E5E7EB">'
            . '<tr><td style="background:' . $primary . ';padding:22px 28px">' . $mark . '</td></tr>'
            . '<tr><td style="height:4px;background:' . $accent . ';line-height:4px;font-size:0">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px;font:15px/1.6 Arial,Helvetica,sans-serif;color:#1F2937">'
            . ($title !== '' ? '<h1 style="margin:0 0 14px;font:700 21px/1.3 Arial,Helvetica,sans-serif;color:#111827">' . $e($title) . '</h1>' : '')
            . $bodyHtml . $ctaHtml . '</td></tr>'
            . '<tr><td style="padding:18px 28px;border-top:1px solid #E5E7EB;font:12px/1.6 Arial,Helvetica,sans-serif;color:#6B7280">' . implode(' · ', $foot)
            . ($unsubscribeUrl ? '<br><a href="' . $e($unsubscribeUrl) . '" style="color:#6B7280">Unsubscribe</a>' : '') . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Plain paragraphs from plain text (escaped), for callers that compose text. */
    public static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split("/\n{2,}/", trim($text)) as $p) { $out .= '<p style="margin:0 0 14px">' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>'; }
        return $out;
    }
}
