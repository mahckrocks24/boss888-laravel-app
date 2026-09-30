<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * PLATFORM-6 (RFC-0021 wave 6, 2026-10-01) — the platform around the site.
 *
 * REPORT-0067 #10 #12 #15 and REPORT-0068 #8: unpublished drafts were readable by anyone who guessed the number,
 * Sarah's discovery never found the site because publishing never recorded its address, the customer's 404 page was
 * LevelUpGrowth-branded, upload replies leaked server paths and uploads stayed under /storage/tmp/, and Sarah's
 * introduction called every customer "Boss". Kill switch: storage/app/platform6.on.
 */
final class Platform6
{
    public const SWITCH = 'app/platform6.on';

    public static function on(): bool
    {
        return is_file(storage_path(self::SWITCH));
    }

    /** A short-lived link to an unpublished draft, for its owner only. */
    public static function signDraft(int $websiteId, int $ttlSeconds = 7200): string
    {
        $exp = time() + max(60, $ttlSeconds);
        return $exp . '.' . substr(hash_hmac('sha256', $websiteId . '|' . $exp, (string) config('app.key')), 0, 32);
    }

    public static function verifyDraft(int $websiteId, ?string $token): bool
    {
        if (! is_string($token) || ! preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $token, $m)) return false;
        if ((int) $m[1] < time()) return false;
        return hash_equals(substr(hash_hmac('sha256', $websiteId . '|' . $m[1], (string) config('app.key')), 0, 32), $m[2]);
    }

    /** The customer's own not-found page: their name, their colour, a way home — never ours. */
    public static function notFoundPage(?object $website, array $variables = []): string
    {
        $name = trim((string) ($website->name ?? ''));
        $primary = trim((string) ($variables['primary_color'] ?? ''));
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#1F2937';
        $lang = (isset($variables['business_name']) && BuildQuality::isArabic((string) $variables['business_name'])) ? 'ar' : 'en';
        $dir = $lang === 'ar' ? ' dir="rtl"' : '';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $title = $lang === 'ar' ? 'الصفحة غير موجودة' : 'Page not found';
        $body = $lang === 'ar' ? 'هذه الصفحة غير موجودة أو تم نقلها.' : 'This page does not exist or has moved.';
        $home = $lang === 'ar' ? 'العودة إلى الصفحة الرئيسية' : 'Back to the home page';
        return '<!DOCTYPE html><html lang="' . $lang . '"' . $dir . '><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $e($title) . ($name !== '' ? ' – ' . $e($name) : '') . '</title>'
            . '<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#FAFAF9;color:#1F2937;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center}.card{max-width:520px}.brand{font-weight:700;letter-spacing:.02em;color:' . $primary . ';margin-bottom:28px;font-size:18px}h1{font-size:clamp(28px,6vw,44px);line-height:1.1;margin-bottom:12px}p{color:#4B5563;font-size:16px;line-height:1.6}a{display:inline-block;margin-top:24px;background:' . $primary . ';color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600}</style></head>'
            . '<body><div class="card">' . ($name !== '' ? '<div class="brand">' . $e($name) . '</div>' : '') . '<h1>' . $e($title) . '</h1><p>' . $e($body) . '</p><a href="/">' . $e($home) . '</a></div></body></html>';
    }

    /** Publishing records the site's address for Sarah's discovery and the SEO engine. */
    public static function ensureSeoSiteUrl(int $wsId, int $websiteId, ?string $subdomain, ?string $name = null): bool
    {
        $sub = trim((string) $subdomain); if ($sub === '') return false;
        $url = 'https://' . (str_contains($sub, '.') ? $sub : $sub . '.levelupgrowth.io');
        try {
            DB::table('seo_settings')->updateOrInsert(['workspace_id' => $wsId, 'website_id' => $websiteId, 'key' => 'site_url'], ['value' => $url, 'updated_at' => now(), 'created_at' => now()]);
            if ($name !== null && trim($name) !== '') DB::table('seo_settings')->updateOrInsert(['workspace_id' => $wsId, 'website_id' => $websiteId, 'key' => 'site_name'], ['value' => mb_substr(trim($name), 0, 160), 'updated_at' => now(), 'created_at' => now()]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Uploads that still live under /storage/tmp/ move into the site's own folder and every variable that pointed at
     * them follows. Returns [variables, moved].
     */
    public static function tmpImagesToSite(int $websiteId, array $variables): array
    {
        $moved = 0; $dir = storage_path("app/public/sites/{$websiteId}/img"); $map = [];
        foreach ($variables as $k => $v) {
            if (! is_string($v) || ! preg_match('#^/storage/tmp/(images|logos)/([A-Za-z0-9._\-]+)$#', $v, $m)) continue;
            if (isset($map[$v])) { $variables[$k] = $map[$v]; continue; }
            $src = storage_path('app/public/tmp/' . $m[1] . '/' . $m[2]);
            if (! is_file($src)) continue;
            if (! is_dir($dir)) @mkdir($dir, 0775, true);
            $dst = $dir . '/' . $m[2];
            if (is_file($dst) || @copy($src, $dst)) { @chmod($dst, 0664); $map[$v] = "/storage/sites/{$websiteId}/img/{$m[2]}"; $variables[$k] = $map[$v]; $moved++; }
        }
        return [$variables, $moved];
    }
}
