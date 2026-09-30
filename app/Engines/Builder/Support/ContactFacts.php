<?php

namespace App\Engines\Builder\Support;

use Illuminate\Support\Facades\DB;

/**
 * CONTACT-1 (RFC-0021 wave 2, 2026-10-01) — the business's contact facts have one home and are never invented.
 *
 * REPORT-0067 #4/#6 and REPORT-0068 #6: Arthur's build data never asked for a phone, email, address or hours, so the
 * ones the owner typed were dropped (contact_phone/contact_email empty, the row stripped from the page), the copy
 * model invented hours for a shoe shop, the build's defaults invented "info@<slug>.com", and hours/phone lines were
 * parsed as services. This class holds the fact keys, the verbatim rule, the services scrub, the currency guess, the
 * business-profile record and the structured-data extras. Kill switch: storage/app/contactfields.on.
 */
final class ContactFacts
{
    public const SWITCH = 'app/contactfields.on';
    public const KINDS = ['phone', 'email', 'address', 'hours', 'whatsapp', 'website'];
    private const NOT_A_SERVICE = '/(\b\d{1,2}\s*(?:am|pm)\b|\b\d{1,2}[:.]\d{2}\b|\bopen(?:ing)?\b|\bhours?\b|\bclosed\b|\bmon(?:day)?\b|\btue(?:sday)?\b|\bwed(?:nesday)?\b|\bthu(?:rsday)?\b|\bfri(?:day)?\b|\bsat(?:urday)?\b|\bsun(?:day)?\b|\bphone\b|\bcall\b|\btel\b|\bwhatsapp\b|\bemail\b|@|\bpages?\s*:|\bwww\.|https?:\/\/|(?:\+?\d[\d\s().-]{7,}\d)|\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b|\bstreet\b|\broad\b|\bavenue\b|\bpostcode\b|\bzip\b)/iu';

    public static function on(): bool
    {
        return is_file(storage_path(self::SWITCH));
    }

    /** The facts the owner gave, trimmed, verbatim; absent keys are absent (never ''). */
    public static function fromBuildData(array $data): array
    {
        $out = [];
        foreach (self::KINDS as $k) {
            $v = $data[$k] ?? $data['contact_' . $k] ?? null;
            if (is_array($v)) $v = implode(', ', array_filter(array_map('strval', $v)));
            $v = trim((string) $v);
            if ($v === '' || strtolower($v) === 'null' || strtolower($v) === 'n/a') continue;
            if ($k === 'email' && ! filter_var($v, FILTER_VALIDATE_EMAIL)) continue;
            $out[$k] = mb_substr($v, 0, $k === 'hours' || $k === 'address' ? 300 : 160);
        }
        return $out;
    }

    /** Hours, phone numbers, addresses, emails and page lists are never services. */
    public static function scrubServices(array $services): array
    {
        return array_values(array_filter($services, fn ($s) => is_string($s) && trim($s) !== '' && ! preg_match(self::NOT_A_SERVICE, $s)));
    }

    /** Which template variable kind a fact key is (contact_phone -> phone), or null for a label/icon/link. */
    public static function kindOfKey(string $key): ?string
    {
        if (preg_match('/(_label|_title|_display|_icon|_link|_color|_green|_placeholder)$/i', $key)) return null;
        $k = strtolower($key);
        if (preg_match('/whatsapp/', $k)) return 'whatsapp';
        if (preg_match('/(^|_)(phone|tel|mobile)(_|$)/', $k)) return 'phone';
        if (preg_match('/(^|_)e?mail(_|$)/', $k)) return 'email';
        if (preg_match('/(^|_)address(_|$)/', $k)) return 'address';
        if (preg_match('/(^|_)(hours|opening)(_|$)/', $k)) return 'hours';
        if (preg_match('/(^|_)website(_|$)/', $k)) return 'website';
        return null;
    }

    /**
     * A fact on the site comes from the owner or not at all: every fact-kind variable is the given value verbatim,
     * or '' (never the copy model's guess, never a template sample, never an invented "info@slug.com").
     */
    public static function enforce(array $variables, array $varSpecs, array $given): array
    {
        foreach (array_keys($varSpecs + $variables) as $key) {
            $kind = self::kindOfKey((string) $key);
            if ($kind === null) continue;
            if (preg_match('/^(service|menu|listing|product|room|dish|item|vehicle|plan)_\d+_/i', (string) $key)) continue;   // catalogue slots are their own truth
            $variables[$key] = (string) ($given[$kind] ?? '');
        }
        return $variables;
    }

    /** A guess at the trading currency from where the business is; USD when nothing says otherwise. */
    public static function currencyFor(string $location): string
    {
        $l = strtolower($location);
        $map = [
            'GBP' => '/\b(uk|u\.k\.|united kingdom|england|scotland|wales|london|manchester|salford|birmingham|leeds|glasgow|edinburgh|bristol|norwich|liverpool|cardiff|belfast)\b|\b[a-z]{1,2}\d[a-z\d]?\s*\d[a-z]{2}\b/',
            'AED' => '/\b(uae|u\.a\.e\.|united arab emirates|dubai|abu dhabi|sharjah|ajman)\b|\bدبي\b|\bالإمارات\b|\bأبوظبي\b/u',
            'EUR' => '/\b(ireland|dublin|germany|berlin|munich|france|paris|spain|madrid|barcelona|italy|rome|milan|netherlands|amsterdam|belgium|brussels|austria|vienna|portugal|lisbon|finland|greece|athens)\b/',
            'AUD' => '/\b(australia|sydney|melbourne|brisbane|perth|adelaide)\b/',
            'CAD' => '/\b(canada|toronto|vancouver|montreal|calgary|ottawa)\b/',
            'SGD' => '/\bsingapore\b/', 'PHP' => '/\b(philippines|manila|cebu|davao|quezon)\b/', 'INR' => '/\b(india|mumbai|delhi|bangalore|bengaluru|chennai|hyderabad|pune)\b/',
            'SAR' => '/\b(saudi|riyadh|jeddah)\b|\bالرياض\b|\bجدة\b/u', 'QAR' => '/\b(qatar|doha)\b|\bالدوحة\b/u', 'NZD' => '/\b(new zealand|auckland|wellington)\b/',
            'ZAR' => '/\b(south africa|johannesburg|cape town|durban)\b/', 'JPY' => '/\b(japan|tokyo|osaka)\b/', 'CHF' => '/\b(switzerland|zurich|geneva)\b/',
        ];
        foreach ($map as $cur => $re) if (preg_match($re, $l)) return $cur;
        return 'USD';
    }

    /** Structured-data extras for a site's variables: telephone, email, address, openingHours — only what is present. */
    public static function jsonLdExtras(array $variables): array
    {
        $pick = function (string $kind) use ($variables): string {
            foreach ($variables as $k => $v) { if (is_string($v) && trim($v) !== '' && self::kindOfKey((string) $k) === $kind && ! preg_match('/^(service|menu|listing|product)_\d+_/i', (string) $k)) return trim($v); }
            return '';
        };
        $out = [];
        if (($p = $pick('phone')) !== '') $out['telephone'] = $p;
        if (($e = $pick('email')) !== '') $out['email'] = $e;
        if (($a = $pick('address')) !== '') $out['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $a];
        if (($h = $pick('hours')) !== '') $out['openingHours'] = $h;
        return $out;
    }

    /** Rewrite (or add) the LocalBusiness block of a static export so it carries the current facts. */
    public static function refreshJsonLd(string $html, array $variables): string
    {
        $extras = self::jsonLdExtras($variables);
        if (! preg_match('#<script type="application/ld\+json">(\{[^<]*"@type":"LocalBusiness"[^<]*\})</script>#', $html, $m)) return $html;
        $schema = json_decode($m[1], true);
        if (! is_array($schema)) return $html;
        foreach (['telephone', 'email', 'address', 'openingHours'] as $k) unset($schema[$k]);
        $schema = $schema + $extras;
        $json = json_encode($schema, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? $html : str_replace($m[0], '<script type="application/ld+json">' . $json . '</script>', $html);
    }

    /** The facts a workspace's business profile holds (the default business, or the website's own). */
    public static function forWorkspace(int $wsId, ?int $businessId = null): array
    {
        $q = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $row = $businessId ? (clone $q)->where('id', $businessId)->first() : null;
        $row = $row ?: (clone $q)->orderByDesc('is_default')->orderBy('id')->first();
        if (! $row) return [];
        $out = [];
        if (trim((string) $row->phone) !== '') $out['phone'] = trim((string) $row->phone);
        if (trim((string) $row->email) !== '') $out['email'] = trim((string) $row->email);
        $addr = json_decode((string) ($row->address_json ?: '{}'), true) ?: [];
        if (trim((string) ($addr['text'] ?? '')) !== '') $out['address'] = trim((string) $addr['text']);
        $hrs = json_decode((string) ($row->opening_hours_json ?: '{}'), true) ?: [];
        if (trim((string) ($hrs['text'] ?? '')) !== '') $out['hours'] = trim((string) $hrs['text']);
        return $out;
    }

    /** Record the facts on the business profile (the website's business, else the default). No row = nothing to do. */
    public static function recordOnBusiness(int $wsId, ?int $businessId, array $facts): bool
    {
        if ($facts === []) return false;
        $q = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $row = $businessId ? (clone $q)->where('id', $businessId)->first(['id', 'address_json', 'opening_hours_json']) : null;
        $row = $row ?: (clone $q)->orderByDesc('is_default')->orderBy('id')->first(['id', 'address_json', 'opening_hours_json']);
        if (! $row) return false;
        $upd = [];
        if (isset($facts['phone'])) $upd['phone'] = mb_substr($facts['phone'], 0, 60);
        if (isset($facts['email'])) $upd['email'] = mb_substr($facts['email'], 0, 190);
        if (isset($facts['address'])) { $a = json_decode((string) ($row->address_json ?: '{}'), true) ?: []; $a['text'] = $facts['address']; $upd['address_json'] = json_encode($a, JSON_UNESCAPED_UNICODE); }
        if (isset($facts['hours'])) { $h = json_decode((string) ($row->opening_hours_json ?: '{}'), true) ?: []; $h['text'] = $facts['hours']; $upd['opening_hours_json'] = json_encode($h, JSON_UNESCAPED_UNICODE); }
        if ($upd === []) return false;
        $upd['updated_at'] = now();
        DB::table('businesses')->where('id', (int) $row->id)->update($upd);
        return true;
    }


    /**
     * The owner's own words beat the model's JSON: an email or phone the model "tidied" (hello@x.com for a typed
     * hello@x.co.uk) is replaced by the one the owner actually typed in the conversation.
     */
    public static function reconcileWithConversation(array $buildData, array $history): array
    {
        $text = '';
        foreach ($history as $h) { if (is_array($h) && (($h['role'] ?? '') === 'user') && is_string($h['content'] ?? null)) $text .= "\n" . $h['content']; }
        if (trim($text) === '') return $buildData;
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', $text, $em); $emails = array_values(array_unique($em[0] ?? []));
        $given = trim((string) ($buildData['email'] ?? ''));
        if ($emails !== [] && ($given === '' || ! in_array($given, $emails, true))) { $buildData['email'] = count($emails) === 1 ? $emails[0] : ($given !== '' ? (self::closest($given, $emails) ?? $given) : $emails[0]); }
        preg_match_all('/(?<![\d])(?:\+?\d[\d\s().\-]{6,}\d)(?![\d])/u', $text, $ph);
        $phones = array_values(array_unique(array_filter(array_map('trim', $ph[0] ?? []), fn ($p) => strlen(preg_replace('/\D/', '', $p)) >= 7)));
        $gp = trim((string) ($buildData['phone'] ?? ''));
        if ($phones !== [] && $gp !== '') { $digits = preg_replace('/\D/', '', $gp); foreach ($phones as $p) { if (preg_replace('/\D/', '', $p) === $digits && $p !== $gp) { $buildData['phone'] = $p; break; } } }
        return $buildData;
    }

    private static function closest(string $needle, array $candidates): ?string
    {
        $best = null; $bestD = PHP_INT_MAX;
        foreach ($candidates as $c) { $d = levenshtein(strtolower($needle), strtolower($c)); if ($d < $bestD) { $bestD = $d; $best = $c; } }
        return $bestD <= 12 ? $best : null;
    }

    /**
     * A phone or email the design shows as plain text becomes a tap-to-call / mail link: the element becomes an <a>
     * with the same attributes and inherited colour, so the design does not change.
     */
    public static function linkFactElements(string $html): string
    {
        return (string) preg_replace_callback('#<(span|dd|div|p|b|strong|em|td|li)\b([^>]*\bdata-field="([a-z0-9_]*(?:phone|email|whatsapp|mobile)[a-z0-9_]*)"[^>]*)>([^<]{3,120})</\1>#i', function ($m) {
            $tag = $m[1]; $attrs = $m[2]; $key = $m[3]; $text = trim(html_entity_decode($m[4], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('/(_label|_title|_display|_icon|_link)$/i', $key)) return $m[0];
            $isMail = (bool) preg_match('/email/i', $key); $isWa = (bool) preg_match('/whatsapp/i', $key);
            if ($isMail) { if (! filter_var($text, FILTER_VALIDATE_EMAIL)) return $m[0]; $href = 'mailto:' . $text; }
            else { $digits = preg_replace('/[^\d+]/', '', $text); if (strlen(preg_replace('/\D/', '', $digits)) < 7) return $m[0]; $href = $isWa ? 'https://wa.me/' . preg_replace('/\D/', '', $digits) : 'tel:' . $digits; }
            $style = preg_match('/\bstyle="([^"]*)"/i', $attrs, $sm) ? $sm[1] : '';
            $attrs2 = preg_replace('/\s*\bstyle="[^"]*"/i', '', $attrs);
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $attrs2 . ' style="color:inherit;text-decoration:inherit;' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '">' . $m[4] . '</a>';
        }, $html);
    }

    /** Validate what the owner typed in the editor's Contact details panel. Returns [facts, errors]. */
    public static function validate(array $in): array
    {
        $facts = []; $errors = [];
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone !== '') { if (! preg_match('/^\+?[\d\s().\-]{6,30}$/', $phone)) $errors['phone'] = 'That does not look like a phone number.'; else $facts['phone'] = $phone; }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '') { if (! filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'That does not look like an email address.'; else $facts['email'] = $email; }
        $address = trim((string) ($in['address'] ?? ''));
        if ($address !== '') { if (mb_strlen($address) > 200) $errors['address'] = 'Keep the address under 200 characters.'; else $facts['address'] = $address; }
        $hours = trim((string) preg_replace('/\r\n?/', "\n", (string) ($in['hours'] ?? '')));
        if ($hours !== '') { if (mb_strlen($hours) > 300) $errors['hours'] = 'Keep the hours under 300 characters.'; else $facts['hours'] = $hours; }
        foreach (['phone', 'email', 'address', 'hours'] as $k) { if (array_key_exists($k, $in) && trim((string) $in[$k]) === '') $facts[$k] = ''; }   // an emptied box clears the fact
        return [$facts, $errors];
    }
}
