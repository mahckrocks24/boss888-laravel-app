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
    /** @return array{business_id:?int,name:string,address:string,reply_to:?string,kit:array,website:?string,phone:?string,address_line:?string,hero:?string} */
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
        $heroSrc = null;
        if ($site) { $tv = json_decode((string) ($site->template_variables ?? ''), true) ?: []; foreach (['hero_image', 'hero_bg', 'hero_img', 'hero_photo'] as $hk) { if (is_string($tv[$hk] ?? null) && $tv[$hk] !== '') { $heroSrc = $tv[$hk]; break; } } }
        return ['business_id' => $biz ? (int) $biz->id : null, 'name' => $name, 'address' => $address, 'reply_to' => $reply, 'kit' => $kit, 'website' => $website,
            'phone' => ($biz->phone ?? null) ?: null, 'address_line' => $addr ?: ($biz->location ?? null), 'hero' => self::heroUrl($heroSrc)];
    }

    /**
     * WL-MAIL-1: the business's own website hero photo, cut to an email-safe banner (1200x480 JPEG) and cached under
     * public/storage/email-heroes. Only local /storage images are used; anything else returns null (no banner).
     */
    public static function heroUrl(?string $src): ?string
    {
        if (! is_string($src) || $src === '') return null;
        $path = (string) (parse_url($src, PHP_URL_PATH) ?: '');
        if (! str_starts_with($path, '/storage/') || str_contains($path, '..')) return null;
        $file = public_path(ltrim($path, '/'));
        if (! is_file($file)) return null;
        $name = 'email-heroes/' . substr(md5($path . '|' . filemtime($file)), 0, 20) . '.jpg';
        $out = public_path('storage/' . $name);
        if (! is_file($out)) {
            try {
                $img = @imagecreatefromstring((string) file_get_contents($file));
                if (! $img) return null;
                $w = imagesx($img); $h = imagesy($img); $tw = 1200; $th = 480;
                $scale = max($tw / $w, $th / $h); $cw = (int) round($tw / $scale); $ch = (int) round($th / $scale);
                $dst = imagecreatetruecolor($tw, $th);
                imagecopyresampled($dst, $img, 0, 0, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2.6), $tw, $th, $cw, $ch);
                if (! is_dir(dirname($out))) @mkdir(dirname($out), 0775, true);
                imagejpeg($dst, $out, 80); imagedestroy($dst); imagedestroy($img);
            } catch (\Throwable $e) { return null; }
        }
        return rtrim((string) config('app.url'), '/') . '/storage/' . $name;
    }

    /**
     * The business-branded email layout. $bodyHtml is trusted HTML from the caller (escape user text before passing it).
     * No LevelUpGrowth name, colour or link appears.
     *
     * WL-MAIL-1 (Owner 2026-09-28): same quality bar as the approved platform design (MAIL-BRAND-2), in the BUSINESS's
     * brand. Optional $o: hero (url|false — defaults to the website's own hero photo), eyebrow, lead, details
     * ([[label, value], ...]), steps ([text, ...]), note, signoff, reason.
     */
    public static function layout(array $id, string $title, string $bodyHtml, ?array $cta = null, ?string $unsubscribeUrl = null, ?string $preheader = null, array $o = []): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $k = $id['kit'] ?? [];
        $plat = fn ($c) => ! is_string($c) || WorkspaceBrandKitResolver::isPlatformColor($c) || ! preg_match('/^#[0-9a-f]{6}$/i', $c);
        $primary = $plat($k['primary_color'] ?? null) ? '#1F2937' : $k['primary_color'];
        $accent = $plat($k['secondary_color'] ?? null) ? $primary : $k['secondary_color'];
        $lum = function ($h) { $h = ltrim($h, '#'); $c = array_map(fn ($x) => hexdec($x) / 255, str_split($h, 2)); $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c); return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]; };
        $mix = function ($h, $t) { $c = array_map('hexdec', str_split(ltrim($h, '#'), 2)); return sprintf('#%02X%02X%02X', ...array_map(fn ($v) => (int) round($v + (255 - $v) * $t), $c)); };
        // btn = the brand colour when it carries white text; tint/line = whispers of it for the details card
        $btn = $lum($primary) > 0.3 ? ($lum($accent) <= 0.3 ? $accent : '#111827') : $primary;
        $ink = $lum($primary) > 0.3 ? '#0F172A' : $primary;
        $tint = $mix($btn, 0.94); $line = $mix($btn, 0.84);
        $font = trim(explode(',', (string) ($k['heading_font'] ?? 'Georgia'))[0], " '\"");
        $serif = "'" . $e($font) . "',Georgia,'Times New Roman',serif";
        $sans = "-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
        $logo = is_string($k['logo_url'] ?? null) && preg_match('#^https?://#', $k['logo_url']) ? $k['logo_url'] : (is_string($k['logo_url'] ?? null) && str_starts_with($k['logo_url'], '/') ? rtrim((string) config('app.url'), '/') . $k['logo_url'] : null);
        $home = $id['website'] ?? null;
        $mark = $logo ? '<img src="' . $e($logo) . '" alt="' . $e($id['name']) . '" style="max-height:46px;max-width:230px;display:block;border:0">'
            : '<span style="font-family:' . $serif . ';font-size:23px;font-weight:700;color:' . $ink . ';letter-spacing:-.01em">' . $e($id['name']) . '</span>';
        if ($home) $mark = '<a href="' . $e($home) . '" style="text-decoration:none">' . $mark . '</a>';
        $hero = array_key_exists('hero', $o) ? $o['hero'] : ($id['hero'] ?? null);
        $label = 'font:700 12px/1 ' . $sans . ';letter-spacing:.14em;text-transform:uppercase;';

        $h = '';
        if (! empty($o['eyebrow'])) $h .= '<div style="' . $label . 'color:' . $btn . ';margin:0 0 12px">' . $e($o['eyebrow']) . '</div>';
        if ($title !== '') $h .= '<h1 style="margin:0 0 14px;font-family:' . $serif . ';font-size:30px;line-height:1.18;font-weight:700;color:#0F172A;letter-spacing:-.015em">' . $e($title) . '</h1>';
        if (! empty($o['lead'])) $h .= '<p style="margin:0 0 22px;font:17px/1.6 ' . $sans . ';color:#475569">' . $e($o['lead']) . '</p>';
        $h .= $bodyHtml;
        if (! empty($o['details'])) {
            $rows = '';
            foreach (array_values($o['details']) as $i => $d) {
                $bt = $i ? 'border-top:1px solid ' . $line . ';' : '';
                $rows .= '<tr><td style="padding:13px 18px;' . $bt . 'font:600 11px/1.5 ' . $sans . ';letter-spacing:.1em;text-transform:uppercase;color:#64748B;width:36%;vertical-align:top">' . $e($d[0]) . '</td>'
                    . '<td style="padding:13px 18px;' . $bt . 'font:600 15px/1.45 ' . $sans . ';color:#0F172A">' . $e($d[1]) . '</td></tr>';
            }
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 26px;background:' . $tint . ';border:1px solid ' . $line . ';border-radius:14px;border-collapse:separate">' . $rows . '</table>';
        }
        if (! empty($o['steps'])) {
            $h .= '<div style="' . $label . 'color:#64748B;margin:4px 0 16px">What happens next</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px">';
            foreach (array_values($o['steps']) as $i => $s) {
                $h .= '<tr><td style="width:42px;vertical-align:top;padding:0 0 14px"><div style="width:28px;height:28px;border-radius:14px;background:' . $btn . ';color:#FFFFFF;font:700 13px/28px ' . $sans . ';text-align:center">' . ($i + 1) . '</div></td>'
                    . '<td style="vertical-align:top;padding:4px 0 14px;font:15px/1.55 ' . $sans . ';color:#334155">' . $e($s) . '</td></tr>';
            }
            $h .= '</table>';
        }
        if ($cta && ! empty($cta['url'])) $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 6px"><tr><td style="border-radius:999px;background:' . $btn . '"><a href="' . $e($cta['url']) . '" style="display:inline-block;padding:14px 28px;font:600 15px ' . $sans . ';color:#FFFFFF;text-decoration:none;border-radius:999px">' . $e($cta['label'] ?? 'Open') . ' &rarr;</a></td></tr></table>';
        if (! empty($o['note'])) $h .= '<p style="margin:22px 0 0;font:14px/1.6 ' . $sans . ';color:#64748B">' . $e($o['note']) . '</p>';
        if (! empty($o['signoff'])) $h .= '<p style="margin:26px 0 0;font:15px/1.5 ' . $sans . ';color:#334155">Warm regards,<br><span style="font-family:' . $serif . ';font-size:18px;font-weight:700;color:#0F172A">' . $e($o['signoff']) . '</span></p>';

        $foot = array_filter([
            ! empty($id['address_line']) ? $e($id['address_line']) : null,
            ! empty($id['phone']) ? '<a href="tel:' . $e(preg_replace('/[^0-9+]/', '', (string) $id['phone'])) . '" style="color:#64748B;text-decoration:none">' . $e($id['phone']) . '</a>' : null,
            $home ? '<a href="' . $e($home) . '" style="color:#64748B">' . $e(self::siteLabel($home) ?? 'Our website') . '</a>' : null,
        ]);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . $e($title !== '' ? $title : $id['name']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#F1F5F9">' . ($preheader ? '<div style="display:none;max-height:0;overflow:hidden">' . $e($preheader) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9"><tr><td align="center" style="padding:32px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px">'
            . '<tr><td style="padding:0 6px 18px">' . $mark . '</td></tr>'
            . '<tr><td style="background:#FFFFFF;border-radius:18px;overflow:hidden;border:1px solid #E2E8F0">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="height:5px;background:' . $btn . ';line-height:5px;font-size:0">&nbsp;</td></tr>'
            . ($hero ? '<tr><td style="line-height:0;font-size:0"><img src="' . $e($hero) . '" width="600" alt="" style="display:block;width:100%;max-width:600px;height:auto;border:0"></td></tr>' : '')
            . '<tr><td style="padding:36px 36px 34px;font:15px/1.65 ' . $sans . ';color:#334155">' . $h . '</td></tr>'
            . '</table></td></tr>'
            . '<tr><td align="center" style="padding:26px 20px 6px;font:13px/1.7 ' . $sans . ';color:#64748B">'
            . '<div style="font-family:' . $serif . ';font-size:16px;font-weight:700;color:#0F172A;margin-bottom:4px">' . $e($id['name']) . '</div>'
            . implode(' &nbsp;&middot;&nbsp; ', $foot)
            . (! empty($o['reason']) ? '<div style="margin-top:12px;font-size:12px;color:#94A3B8">' . $e($o['reason']) . '</div>' : '')
            . ($unsubscribeUrl ? '<div style="margin-top:8px;font-size:12px"><a href="' . $e($unsubscribeUrl) . '" style="color:#94A3B8">Unsubscribe</a></div>' : '')
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** WL-MAIL-1: the visitor's booking / registration confirmation, in the business's voice. */
    public static function bookingOptions(array $id, string $name, string $service, string $date, string $time): array
    {
        $details = [];
        if (trim($name) !== '') $details[] = ['Name', $name];
        if (trim($service) !== '') $details[] = ['Service', $service];
        if (trim($date) !== '') $details[] = ['Preferred date', $date];
        if (trim($time) !== '') $details[] = ['Preferred time', $time];
        $site = ! empty($id['website']) ? self::siteLabel($id['website']) : null;
        return [
            'eyebrow' => 'Request received',
            'lead' => 'We have your request and our team is on it. Here is what you sent us, and what happens next.',
            'details' => $details,
            'steps' => [
                'Our team reviews your request and checks availability.',
                'We contact you to confirm the exact date and time.',
                'Need to change something? Simply reply to this email.',
            ],
            'signoff' => 'The ' . $id['name'] . ' team',
            'reason' => $site ? 'You are receiving this email because you made a request on ' . $site . '.' : 'You are receiving this email because you made a request on our website.',
        ];
    }

    /** The site host to print, or null when it sits on a platform subdomain (a white-label email never names the platform). */
    public static function siteLabel(?string $url): ?string
    {
        $host = strtolower((string) preg_replace('#^https?://#', '', rtrim((string) $url, '/')));
        return $host === '' || str_contains($host, 'levelup') ? null : $host;
    }

    /** Plain paragraphs from plain text (escaped), for callers that compose text. */
    public static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split("/\n{2,}/", trim($text)) as $p) { $out .= '<p style="margin:0 0 14px">' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8')) . '</p>'; }
        return $out;
    }
}
