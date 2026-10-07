<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * PAGE-PREVIEW-2 (Owner 2026-10-07, add-page preview screenshot: an empty Legal page, an empty footer, sample copy).
 *
 * The page stacks in ArthurService::buildRawPageSections() carry sample content ("Replace with a real quote...",
 * "Detail one", empty team / gallery / testimonial lists). An added page - and its preview - must read as the
 * customer's own: this fills each section from the site's own content (template variables over the design's
 * defaults, the catalogue) and leaves out what the site has nothing real for. Nothing here writes.
 */
final class PageFill
{
    private const PLACEHOLDER = '/\b(replace with|placeholder|lorem ipsum|client name|listing title|a short tagline for this listing)\b/i';
    private const SAMPLE_TITLE = '/^(detail|project|item|listing|feature|service|member)\s+(one|two|three|four|five|six)$/i';
    /** Sections that are only worth showing with real content; the rest keep their structure. */
    private const OPTIONAL = ['team', 'testimonials', 'gallery', 'stats', 'trust_signals', 'related_listings', 'features'];
    private const LEGAL = ['legal', 'privacy', 'privacy_policy', 'terms', 'terms_of_service'];

    /** The site's content: the design's own defaults with the owner's values on top. */
    public static function siteVars(array $tv, string $design): array
    {
        $vars = [];
        $design = preg_replace('/[^a-z0-9_\-]/i', '', $design);
        if ($design !== '' && is_file($mf = storage_path("templates/{$design}/manifest.json"))) {
            $m = json_decode((string) file_get_contents($mf), true) ?: [];
            foreach ((array) ($m['variables'] ?? []) as $k => $v) {
                if (is_array($v) && array_key_exists('default', $v) && is_scalar($v['default'])) $vars[$k] = (string) $v['default'];
            }
        }
        foreach ($tv as $k => $v) { if (is_scalar($v) && trim((string) $v) !== '') $vars[$k] = (string) $v; }
        return $vars;
    }

    /** A signed, short-lived link to one site's page preview: the editor opens it in a frame, which cannot carry the bearer. */
    public static function previewToken(int $websiteId, string $slug, int $ttl = 7200): string
    {
        $exp = time() + $ttl;
        return $exp . '.' . substr(hash_hmac('sha256', 'page-preview|' . $websiteId . '|' . $slug . '|' . $exp, (string) config('app.key')), 0, 32);
    }

    public static function verifyPreviewToken(int $websiteId, string $slug, ?string $token): bool
    {
        if (! is_string($token) || ! preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $token, $m) || (int) $m[1] < time()) return false;
        return hash_equals(substr(hash_hmac('sha256', 'page-preview|' . $websiteId . '|' . $slug . '|' . $m[1], (string) config('app.key')), 0, 32), $m[2]);
    }

    public static function isLegal(string $slug): bool
    {
        return in_array(strtolower(str_replace('-', '_', $slug)), self::LEGAL, true);
    }

    /** The legal stack: privacy, terms, cookies and data requests, written for this business. */
    public static function legalSection(string $slug, array $id): array
    {
        $name = trim((string) ($id['business_name'] ?? '')) ?: 'We';
        $loc = trim((string) ($id['location'] ?? ''));
        $email = trim((string) ($id['email'] ?? ''));
        $phone = trim((string) ($id['phone'] ?? ''));
        $reach = $email !== '' ? "email {$email}" : ($phone !== '' ? "call {$phone}" : 'use the contact form on this website');
        $where = $loc !== '' ? " in {$loc}" : '';
        $privacy = ['id' => 'privacy', 'heading' => 'Privacy policy', 'paragraphs' => [
            "{$name}{$where} respects your privacy. This policy explains what we collect when you use this website or contact us, why we collect it, and the choices you have.",
            'We collect what you give us - your name, email address, phone number and the details of your enquiry or booking - and basic technical information such as your browser type and the pages you visit.',
            'We use it to answer your enquiries, manage bookings and orders, improve this website and, only if you agree, send you news and offers. We never sell your personal information.',
            'We share it only with the service providers that help us run this website and our business, under agreements that protect it, or where the law requires us to.',
            'We keep your information for as long as we need it for these purposes or as the law requires, and then delete it securely.'],
        ];
        $rights = ['id' => 'your-rights', 'heading' => 'Your rights and data requests', 'paragraphs' => [
            "You can ask to see the personal information we hold about you, to correct it, to delete it, or to stop us using it for marketing. To make a request, {$reach}. We reply within 30 days.",
            'If you believe we have not handled your information properly, you can also contact the data protection authority where you live.'],
        ];
        $cookies = ['id' => 'cookies', 'heading' => 'Cookies', 'paragraphs' => [
            'This website uses a small number of cookies to work properly and to understand how visitors use it, so we can improve it.',
            'You can block or delete cookies in your browser settings. Some parts of the website may not work as intended without them.'],
        ];
        $terms = ['id' => 'terms', 'heading' => 'Terms of use', 'paragraphs' => [
            "By using this website you agree to these terms. The content is provided for general information about {$name} and its services and may change without notice.",
            'Prices, availability and descriptions are given in good faith; a booking or order is confirmed only when we confirm it to you directly.',
            "All text, images and branding on this website belong to {$name} or are used with permission. Please do not copy them without asking.",
            'We are not responsible for the content of other websites we link to. Nothing in these terms limits rights you have under the law.'],
        ];
        $contact = ['id' => 'contact-us', 'heading' => 'Contact us', 'paragraphs' => [
            "Questions about this page? Contact {$name}" . ($loc !== '' ? ", {$loc}" : '') . ': ' . $reach . '.'],
        ];
        $n = strtolower(str_replace('-', '_', $slug));
        if (in_array($n, ['privacy', 'privacy_policy'], true)) $parts = [$privacy, $rights, $cookies, $contact];
        elseif (in_array($n, ['terms', 'terms_of_service'], true)) $parts = [$terms, $contact];
        else $parts = [$privacy, $rights, $cookies, $terms, $contact];
        return ['type' => 'legal', 'intro' => "How {$name} handles your information, and the terms for using this website.", 'parts' => $parts];
    }

    /** Fill a page stack from the site's content; drop sections with nothing real to show. */
    public static function fill(array $sections, array $vars, array $id, string $slug, int $websiteId = 0): array
    {
        $name = trim((string) ($id['business_name'] ?? ''));
        $out = [];
        foreach ($sections as $sec) {
            if (! is_array($sec)) continue;
            $type = (string) ($sec['type'] ?? '');
            switch ($type) {
                case 'generic':
                    if (self::isLegal($slug)) { $sec = self::legalSection($slug, $id); $type = 'legal'; }
                    break;
                case 'testimonials':
                    $items = array_values(array_filter((array) ($sec['items'] ?? []), fn ($t) => is_array($t) && ! preg_match(self::PLACEHOLDER, (string) ($t['quote'] ?? $t['text'] ?? '') . ' ' . (string) ($t['author'] ?? $t['name'] ?? ''))));
                    if ($items === []) {
                        for ($i = 1; $i <= 6; $i++) {
                            $q = trim((string) ($vars["testimonial_{$i}_quote"] ?? $vars["testimonial_{$i}_text"] ?? $vars["review_{$i}_text"] ?? ''));
                            if ($q === '' || preg_match(self::PLACEHOLDER, $q)) continue;
                            $items[] = ['quote' => $q, 'author' => trim((string) ($vars["testimonial_{$i}_name"] ?? $vars["testimonial_{$i}_author"] ?? '')), 'role' => trim((string) ($vars["testimonial_{$i}_role"] ?? ''))];
                        }
                    }
                    if ($items === []) continue 2;
                    $sec['items'] = $items;
                    break;
                case 'team':
                    $members = array_values(array_filter((array) ($sec['members'] ?? $sec['items'] ?? []), fn ($m) => is_array($m) && trim((string) ($m['name'] ?? '')) !== '' && ! preg_match(self::PLACEHOLDER, (string) ($m['name'] ?? ''))));
                    if ($members === []) {
                        for ($i = 1; $i <= 8; $i++) {
                            $nm = trim((string) ($vars["team_{$i}_name"] ?? $vars["member_{$i}_name"] ?? ''));
                            if ($nm === '') continue;
                            $members[] = ['name' => $nm, 'role' => trim((string) ($vars["team_{$i}_role"] ?? $vars["member_{$i}_role"] ?? '')), 'bio' => trim((string) ($vars["team_{$i}_bio"] ?? '')), 'photo' => trim((string) ($vars["team_{$i}_image"] ?? $vars["team_{$i}_photo"] ?? ''))];
                        }
                    }
                    if ($members === []) continue 2;
                    unset($sec['items']);
                    $sec['members'] = $members;
                    break;
                case 'gallery':
                    $imgs = array_values(array_filter((array) ($sec['images'] ?? $sec['items'] ?? []), fn ($g) => (is_array($g) ? (string) ($g['url'] ?? $g['src'] ?? '') : (string) $g) !== ''));
                    if ($imgs === []) {
                        for ($i = 1; $i <= 12 && count($imgs) < 9; $i++) {
                            $u = trim((string) ($vars["gallery_{$i}"] ?? $vars["gallery_{$i}_image"] ?? $vars["gallery_image_{$i}"] ?? ''));
                            if ($u === '' || ! preg_match('#^(/|https?://)[^\s"]+\.(jpe?g|png|webp|avif)(\?.*)?$#i', $u)) continue;
                            $imgs[] = ['url' => $u, 'caption' => trim((string) ($vars["gallery_cap_{$i}"] ?? $vars["gallery_{$i}_caption"] ?? ''))];
                        }
                    }
                    if ($imgs === []) continue 2;
                    unset($sec['items']);
                    $sec['images'] = $imgs;
                    break;
                case 'grid':
                    // a portfolio of "Project one / Category · 2026": the site's own photographs are the work it shows
                    $real = array_values(array_filter((array) ($sec['items'] ?? []), fn ($it) => is_array($it) && ! preg_match(self::SAMPLE_TITLE, trim((string) ($it['title'] ?? ''))) && ! preg_match(self::PLACEHOLDER, (string) ($it['title'] ?? ''))));
                    if ($real === [] && in_array($slug, ['portfolio', 'before_after'], true)) {
                        $svc = array_values(array_filter(array_map('strval', (array) ($id['services'] ?? []))));
                        for ($i = 1, $n = 0; $i <= 12 && $n < 6; $i++) {
                            $u = trim((string) ($vars["gallery_{$i}"] ?? $vars["gallery_{$i}_image"] ?? $vars["gallery_image_{$i}"] ?? ''));
                            if ($u === '' || ! preg_match('#^(/|https?://)[^\s"]+\.(jpe?g|png|webp|avif)(\?.*)?$#i', $u)) continue;
                            $cap = trim((string) ($vars["gallery_cap_{$i}"] ?? $vars["gallery_{$i}_caption"] ?? ''));
                            $real[] = ['title' => $cap !== '' ? $cap : ($svc[$n % max(1, count($svc))] ?? 'Recent work'), 'subtitle' => $name, 'image' => $u];
                            $n++;
                        }
                    }
                    if ($real === []) continue 2;
                    $sec['items'] = $real;
                    break;
                case 'map':
                    $addr = trim((string) ($vars['contact_address'] ?? $vars['address'] ?? ''));
                    $hours = trim((string) ($vars['contact_hours'] ?? $vars['hours'] ?? ''));
                    $phone = trim((string) ($vars['contact_phone'] ?? $id['phone'] ?? ''));
                    $locs = [];
                    foreach ((array) ($sec['locations'] ?? []) as $l) {
                        if (! is_array($l)) continue;
                        if (preg_match(self::PLACEHOLDER, (string) ($l['address'] ?? ''))) $l['address'] = $addr;
                        if ($hours !== '') $l['hours'] = $hours;
                        if (trim((string) ($l['phone'] ?? '')) === '' && $phone !== '') $l['phone'] = $phone;
                        if ($name !== '' && in_array(trim((string) ($l['name'] ?? '')), ['', 'Main branch'], true)) $l['name'] = $name;
                        if (trim((string) ($l['address'] ?? '')) === '' && trim((string) ($l['phone'] ?? '')) === '') continue;
                        $locs[] = $l;
                    }
                    if ($locs === [] && $addr !== '') $locs[] = ['name' => $name, 'address' => $addr, 'phone' => $phone, 'hours' => $hours];
                    if ($locs === []) continue 2;
                    $sec['locations'] = $locs;
                    if ($addr !== '' && empty($sec['embed_url'])) $sec['embed_url'] = 'https://maps.google.com/maps?q=' . rawurlencode($addr) . '&output=embed';
                    break;
                case 'footer':
                    if (empty($sec['components']) && $name !== '') {
                        $sec['components'] = [
                            ['type' => 'heading', 'text' => $name],
                            ['type' => 'text', 'text' => trim(implode(' · ', array_filter([(string) ($id['core_service'] ?? ''), (string) ($id['location'] ?? '')])))],
                            ['type' => 'text', 'text' => 'Home · About · Services · Contact'],
                            ['type' => 'text', 'text' => '© ' . date('Y') . ' ' . $name . '. All rights reserved.'],
                        ];
                    }
                    break;
            }
            if ($slug === 'listing_detail' && $websiteId > 0 && in_array($type, ['hero', 'features'], true)) $sec = self::listingFromCatalogue($sec, $websiteId);
            // whatever still carries sample copy: optional sections go, the rest lose the sample strings
            $sec = self::scrub($sec);
            if (in_array($type, self::OPTIONAL, true)) {
                $list = $sec['items'] ?? $sec['members'] ?? $sec['images'] ?? null;
                if (is_array($list) && $list === []) continue;
            }
            $out[] = $sec;
        }
        return $out;
    }

    /** listing_detail: the first real listing stands in for "Listing title / Detail one...". */
    private static function listingFromCatalogue(array $sec, int $websiteId): array
    {
        try {
            $it = DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', 'listing')->whereNull('deleted_at')
                ->where('source', '!=', 'seed')->orderBy('sort_order')->orderBy('id')->first();
        } catch (\Throwable $e) { $it = null; }
        if (! $it) return $sec;
        if (($sec['type'] ?? '') === 'hero') {
            $sec['heading'] = (string) $it->title;
            $sec['subheading'] = trim((string) ($it->price_label ?: (($it->currency ? $it->currency . ' ' : '') . ($it->price ?? ''))));
            $sec['body'] = (string) ($it->summary ?: mb_substr(strip_tags((string) $it->description), 0, 220));
            $ph = json_decode((string) ($it->photos_json ?: '[]'), true) ?: [];
            if (! empty($ph[0])) $sec['background_image'] = is_array($ph[0]) ? (string) ($ph[0]['url'] ?? '') : (string) $ph[0];
        } else {
            $items = [];
            foreach ((json_decode((string) ($it->features_json ?: '[]'), true) ?: []) as $x) {
                $t = is_array($x) ? (string) ($x['label'] ?? $x['title'] ?? '') : (string) $x;
                if ($t !== '') $items[] = ['title' => $t, 'body' => is_array($x) ? (string) ($x['value'] ?? '') : ''];
            }
            $sec['items'] = $items;
        }
        return $sec;
    }

    /** Drop sample entries from item lists and blank sample strings. */
    private static function scrub(array $sec): array
    {
        foreach (['items', 'members', 'tiers'] as $k) {
            if (! is_array($sec[$k] ?? null)) continue;
            $sec[$k] = array_values(array_filter($sec[$k], function ($it) {
                if (! is_array($it)) return ! preg_match(self::PLACEHOLDER, (string) $it);
                $t = trim((string) ($it['title'] ?? $it['name'] ?? ''));
                if ($t !== '' && preg_match(self::SAMPLE_TITLE, $t)) return false;
                foreach ($it as $v) { if (is_string($v) && preg_match(self::PLACEHOLDER, $v)) return false; }
                return true;
            }));
        }
        foreach (['heading', 'subheading', 'body', 'content'] as $k) {
            if (is_string($sec[$k] ?? null) && preg_match(self::PLACEHOLDER, $sec[$k])) $sec[$k] = '';
        }
        return $sec;
    }
}
