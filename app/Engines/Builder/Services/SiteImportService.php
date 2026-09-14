<?php

namespace App\Engines\Builder\Services;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RECREATE FROM A URL (DEC-0051 gap 5, 2026-09-15). The customer pastes the address of their current website; we read
 * it (title, description, headings, paragraphs, phone, e-mail, address-like lines, colours, the largest photos), the
 * model turns that into the same build_data the chat conversation produces — only facts that are on the page — and
 * the confirm panel appears exactly as it does after a conversation. Photos are copied into the workspace so the
 * build can use them as the customer's own uploads.
 */
class SiteImportService
{
    public function __construct(private RuntimeClient $runtime) {}

    /** Fetch and distil a public page. Null when it cannot be read. */
    public function fetch(string $url): ?array
    {
        $url = trim($url);
        if (! preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
        if (! filter_var($url, FILTER_VALIDATE_URL)) return null;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || preg_match('/^(localhost|127\.|10\.|192\.168\.|169\.254\.|0\.)/', $host) || str_ends_with($host, '.local')) return null;
        try {
            $res = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LevelUpGrowth site reader; +https://levelupgrowth.io)', 'Accept' => 'text/html,*/*;q=0.8'])
                ->timeout(12)->connectTimeout(6)->withOptions(['allow_redirects' => ['max' => 4]])->get($url);
        } catch (\Throwable $e) { Log::info('[SiteImport] fetch failed', ['url' => $url, 'error' => $e->getMessage()]); return null; }
        if (! $res->ok()) return null;
        $html = (string) $res->body();
        if (strlen($html) > 3_000_000) $html = substr($html, 0, 3_000_000);
        $final = (string) ($res->effectiveUri() ?? $url);
        return $this->distil($html, $final);
    }

    private function distil(string $html, string $url): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $text = fn(?\DOMNode $n) => $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent) ?? '') : '';
        $meta = function (string $name) use ($xp): string { foreach (['@name', '@property'] as $attr) { $n = $xp->query("//meta[$attr='$name']/@content")->item(0); if ($n) return trim($n->nodeValue); } return ''; };
        $title = $text($xp->query('//title')->item(0));
        $desc = $meta('description') ?: $meta('og:description');
        $headings = []; foreach ($xp->query('//h1|//h2|//h3') as $h) { $t = $text($h); if ($t !== '' && mb_strlen($t) < 160) $headings[] = $t; }
        $paras = []; foreach ($xp->query('//p|//li') as $p) { $t = $text($p); if (mb_strlen($t) >= 40 && mb_strlen($t) <= 600) $paras[] = $t; }
        $phones = []; $emails = [];
        foreach ($xp->query('//a[@href]') as $a) { $h = (string) $a->getAttribute('href'); if (str_starts_with($h, 'tel:')) $phones[] = trim(substr($h, 4)); if (str_starts_with($h, 'mailto:')) $emails[] = trim(explode('?', substr($h, 7))[0]); }
        $body = $text($xp->query('//body')->item(0));
        if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $body, $em)) $emails = array_merge($emails, $em[0]);
        if (preg_match_all('/(?:\+?\d[\d\s().-]{7,}\d)/', $body, $pm)) { foreach ($pm[0] as $c) { if (preg_match_all('/\d/', $c) >= 8) $phones[] = trim($c); } }
        $emails = array_values(array_unique(array_filter($emails, fn($e) => ! preg_match('/example\.com|sentry|wixpress|\.png$|\.jpg$/i', $e))));
        $phones = array_values(array_unique($phones));
        $images = [];
        $og = $meta('og:image'); if ($og !== '') $images[] = $og;
        foreach ($xp->query('//img[@src]') as $img) {
            $src = (string) $img->getAttribute('src'); if ($src === '' || str_starts_with($src, 'data:')) continue;
            $w = (int) $img->getAttribute('width'); $hgt = (int) $img->getAttribute('height');
            if (($w > 0 && $w < 300) || ($hgt > 0 && $hgt < 200)) continue;
            if (preg_match('/logo|icon|sprite|badge|pixel|avatar|flag/i', $src . ' ' . $img->getAttribute('class') . ' ' . $img->getAttribute('alt'))) continue;
            $images[] = $src;
        }
        $base = $url;
        $abs = function (string $u) use ($base): string { if (preg_match('~^https?://~i', $u)) return $u; if (str_starts_with($u, '//')) return 'https:' . $u; $p = parse_url($base); $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? ''); if (str_starts_with($u, '/')) return $root . $u; return rtrim(dirname(($p['path'] ?? '/') === '' ? '/' : $root . ($p['path'] ?? '/')), '/') . '/' . $u; };
        $images = array_values(array_unique(array_map($abs, array_slice($images, 0, 12))));
        $colours = [];
        if (preg_match_all('/#([0-9a-f]{6})\b/i', $html, $cm)) { foreach ($cm[1] as $c) { $c = strtoupper($c); $rgb = [hexdec(substr($c, 0, 2)), hexdec(substr($c, 2, 2)), hexdec(substr($c, 4, 2))]; $lum = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) / 255; $sat = max($rgb) - min($rgb); if ($lum > 0.92 || $lum < 0.08 || $sat < 40) continue; $colours['#' . $c] = ($colours['#' . $c] ?? 0) + 1; } }
        arsort($colours);
        $logo = ''; foreach ($xp->query('//img[@src]') as $img) { $s = (string) $img->getAttribute('src'); if (preg_match('/logo/i', $s . ' ' . $img->getAttribute('class') . ' ' . $img->getAttribute('alt'))) { $logo = $abs($s); break; } }
        return ['url' => $url, 'host' => (string) parse_url($url, PHP_URL_HOST), 'title' => $title, 'description' => $desc, 'headings' => array_slice(array_unique($headings), 0, 40), 'paragraphs' => array_slice(array_unique($paras), 0, 40),
            'phones' => array_slice($phones, 0, 4), 'emails' => array_slice($emails, 0, 3), 'images' => $images, 'colours' => array_slice(array_keys($colours), 0, 6), 'logo' => $logo, 'text' => mb_substr($body, 0, 6000)];
    }

    /** The model reads the distilled page and returns build_data in the chat conversation's shape — only what is on the page. */
    public function extract(int $wsId, array $page, array $industries): ?array
    {
        $system = "You read a business's existing website and describe the business so a new site can be built for it. Return ONLY JSON:\n"
            . '{"business_name":"","industry":"one of INDUSTRIES","location":"city/area as written","services":["…"],"tagline":"a short headline in their own spirit","description":"2–3 sentences about the business, from the page","phone":"","email":"","address":"","style":"luxury|minimal|modern|classic|playful|professional|warm","colors":{"primary":"#hex","secondary":"#hex","accent":"#hex"},"confidence":0.0-1.0}' . "\n"
            . "Rules: take names, services, phone, e-mail, address and location ONLY from the page text; leave a field empty when the page does not say. Pick the closest industry from INDUSTRIES (a coffee shop → cafe, a solicitor → consulting, a clinic → medical_clinic). Colours: from COLOURS on the page when they look like brand colours, else empty. No commentary.";
        $user = "INDUSTRIES: " . implode(', ', $industries) . "\nURL: {$page['url']}\nTITLE: {$page['title']}\nDESCRIPTION: {$page['description']}\nHEADINGS: " . implode(' | ', $page['headings']) . "\nPARAGRAPHS:\n- " . implode("\n- ", array_slice($page['paragraphs'], 0, 25)) . "\nPHONES: " . implode(', ', $page['phones']) . "\nEMAILS: " . implode(', ', $page['emails']) . "\nCOLOURS: " . implode(', ', $page['colours']) . "\nPAGE TEXT (first part): " . mb_substr($page['text'], 0, 3500);
        $res = $this->runtime->chatJson($system, $user, ['task' => 'arthur_site_import', 'workspace_id' => $wsId, 'reasoning_budget' => 2000], 900);
        $parsed = ($res['success'] ?? false) && is_array($res['parsed'] ?? null) ? \App\Engines\Builder\Support\GenerationVariableContract::unwrapEnvelope($res['parsed']) : null;
        if (! is_array($parsed) || empty($parsed['business_name'])) { Log::warning('[SiteImport] extraction failed', ['url' => $page['url'], 'error' => $res['error'] ?? null]); return null; }
        if (! in_array((string) ($parsed['industry'] ?? ''), $industries, true)) $parsed['industry'] = $industries[0] ?? 'consulting';
        return $parsed;
    }

    /** Copy the page's largest photos (and its logo) into the workspace so the build treats them as the customer's own uploads. */
    public function copyImages(int $wsId, array $urls, int $max = 4): array
    {
        $dir = storage_path("app/public/imports/{$wsId}");
        if (! is_dir($dir)) @mkdir($dir, 0755, true);
        $out = [];
        foreach (array_slice($urls, 0, 10) as $u) {
            if (count($out) >= $max) break;
            try {
                $r = Http::timeout(10)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LevelUpGrowth site reader)'])->get($u);
                if (! $r->ok()) continue;
                $ct = (string) $r->header('Content-Type'); $bytes = (string) $r->body();
                if (! preg_match('~image/(jpeg|png|webp)~i', $ct, $tm) || strlen($bytes) < 20_000 || strlen($bytes) > 8_000_000) continue;
                $info = @getimagesizefromstring($bytes); if (! $info || $info[0] < 600 || $info[1] < 300) continue;
                $ext = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'][strtolower($tm[1])];
                $name = 'import-' . date('Ymd') . '-' . Str::lower(Str::random(8)) . '.' . $ext;
                file_put_contents("$dir/$name", $bytes); @chmod("$dir/$name", 0644);
                $out[] = "/storage/imports/{$wsId}/{$name}";
            } catch (\Throwable $e) { continue; }
        }
        return $out;
    }

    /** The whole move: a URL in the customer's message becomes a ready-to-confirm build, cached like a conversation's. */
    public function importForChat(int $wsId, string $url): array
    {
        $page = $this->fetch($url);
        if ($page === null) return ['type' => 'question', 'reply' => "I couldn't open that address — check it is public and spelled right, or just tell me about the business and I'll take it from there.", 'ready_to_build' => false, 'ready_to_confirm' => false, 'build_data' => [], 'history' => []];
        $industries = [];
        foreach (glob(storage_path('templates/*/manifest.json')) ?: [] as $m) { $j = json_decode((string) @file_get_contents($m), true); if (! empty($j['industry'])) $industries[(string) $j['industry']] = true; }
        $bd = $this->extract($wsId, $page, array_keys($industries));
        if ($bd === null) return ['type' => 'question', 'reply' => "I could read {$page['host']} but not make out the business from it. Tell me what the business does and where, and I'll build from that.", 'ready_to_build' => false, 'ready_to_confirm' => false, 'build_data' => [], 'history' => []];
        $buildData = [
            'business_name' => (string) $bd['business_name'], 'industry' => (string) $bd['industry'], 'location' => (string) ($bd['location'] ?? ''),
            'services' => array_values(array_filter(array_map('strval', (array) ($bd['services'] ?? [])))), 'description' => (string) ($bd['description'] ?? ''), 'tagline' => (string) ($bd['tagline'] ?? ''),
            'phone' => (string) ($bd['phone'] ?? ''), 'email' => (string) ($bd['email'] ?? ''), 'address' => (string) ($bd['address'] ?? ''), 'style' => (string) ($bd['style'] ?? ''),
            'source_url' => $page['url'],
        ];
        $colors = array_filter((array) ($bd['colors'] ?? []), fn($v) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v));
        if ($colors !== []) $buildData['colors'] = $colors;
        $images = $this->copyImages($wsId, $page['images']);
        if ($images !== []) $buildData['uploaded_images'] = $images;
        if ($page['logo'] !== '') { $logo = $this->copyImages($wsId, [$page['logo']], 1); if ($logo !== []) $buildData['logo_url'] = $logo[0]; }
        Cache::put('arthur_build_data_' . $wsId, $buildData, 300);
        $svc = $buildData['services'] !== [] ? ' offering ' . implode(', ', array_slice($buildData['services'], 0, 5)) : '';
        $reply = "I read {$page['host']}: {$buildData['business_name']}" . ($buildData['location'] !== '' ? " in {$buildData['location']}" : '') . ", a " . str_replace('_', ' ', $buildData['industry']) . " business{$svc}."
            . ($buildData['phone'] !== '' || $buildData['email'] !== '' ? ' I kept the contact details as they are on the site.' : ' I did not find a phone number or e-mail on the page, so those stay blank until you give them.')
            . ($images !== [] ? ' I copied ' . count($images) . ' of its photos to use.' : '')
            . ' Check the summary, pick colours or upload a logo if you like, and I will build the new site.';
        Log::info('[SiteImport] ready to confirm', ['workspace' => $wsId, 'url' => $page['url'], 'business' => $buildData['business_name'], 'industry' => $buildData['industry'], 'images' => count($images)]);
        return ['type' => 'confirm', 'reply' => $reply, 'ready_to_build' => false, 'ready_to_confirm' => true, 'build_data' => $buildData, 'history' => [['role' => 'user', 'content' => $url], ['role' => 'assistant', 'content' => $reply]]];
    }
}
