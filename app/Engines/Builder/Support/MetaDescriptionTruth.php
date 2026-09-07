<?php

namespace App\Engines\Builder\Support;

/**
 * RISK-0128 (2026-09-07, DEC-0041). Two freshly built UK sites were published with "…artisan cafe in Dubai…" as their
 * meta description, og:description and JSON-LD description (EV-0920): the copy prompt told the model it was writing for
 * a business in Dubai/UAE, and the template defaults carry the same origin. The description that feeds those three
 * carriers must be truthful to the customer's own brief for ANY geography — no hardcoded city, no special-cased one.
 *
 * Rule: a generated description is kept only when it is non-empty, is not the template default, and names no place
 * outside the brief's location (or the business name). Otherwise the description is derived from the brief itself.
 */
final class MetaDescriptionTruth
{
    public const MAX_LENGTH = 160;

    /** Phrases that introduce a place: "in Dubai", "across Greater Manchester", "based in the heart of Toronto". */
    private const PLACE_PHRASE = '/\b(?:in|across|throughout|near|around|serving|from|based\s+in|located\s+in)\s+(?:the\s+(?:heart|centre|center|middle)\s+of\s+)?((?:[A-Z][\p{L}\'’.-]*)(?:\s+(?:of\s+|&\s+|and\s+)?[A-Z][\p{L}\'’.-]*){0,3})/u';

    public static function resolve(string $generated, string $templateDefault, string $name, string $industry, string $services, string $location): string
    {
        $g = trim(preg_replace('/\s+/u', ' ', $generated) ?? '');
        $d = trim(preg_replace('/\s+/u', ' ', $templateDefault) ?? '');
        $keep = $g !== ''
            && mb_strlen($g) <= 320
            && ($d === '' || mb_strtolower($g) !== mb_strtolower($d))
            && self::placeTruthful($g, $location, $name);
        return $keep ? $g : self::derive($name, $industry, $services, $location);
    }

    /** True when every place the text names belongs to the brief (its location words or the business name). */
    public static function placeTruthful(string $text, string $location, string $name = ''): bool
    {
        $allowed = self::tokens($location . ' ' . $name);
        if (!preg_match_all(self::PLACE_PHRASE, $text, $m)) return true;
        foreach ($m[1] as $phrase) {
            $words = self::tokens($phrase);
            if ($words === []) continue;
            if (trim($location) === '') return false;          // no known location: any named place is invented
            foreach ($words as $w) {
                if (in_array($w, ['of', 'and', 'the'], true)) continue;
                if (!in_array($w, $allowed, true)) return false;
            }
        }
        return true;
    }

    /** "{Name} — {services or industry} in {location}." capped to MAX_LENGTH, never a dangling " in ." */
    public static function derive(string $name, string $industry, string $services, string $location): string
    {
        $name = trim($name); $location = trim($location);
        $what = trim(preg_replace('/\s+/u', ' ', $services) ?? '');
        if ($what === '' || mb_strlen($what) > 90) $what = self::humanIndustry($industry);
        $what = rtrim($what, " .;,");
        $core = $name !== '' ? $name . ' — ' . $what : ucfirst($what);
        $out = $location !== '' ? "{$core} in {$location}." : "{$core}.";
        if (mb_strlen($out) > self::MAX_LENGTH) {
            $room = self::MAX_LENGTH - mb_strlen($location !== '' ? " in {$location}." : '.') - mb_strlen($name . ' — ');
            $what = $room > 12 ? rtrim(mb_substr($what, 0, $room - 1), " ,;") . '…' : self::humanIndustry($industry);
            $core = $name !== '' ? $name . ' — ' . $what : ucfirst($what);
            $out = $location !== '' ? "{$core} in {$location}." : "{$core}.";
        }
        return $out;
    }

    /** First comma-separated part of the brief's location, or '' — never a default city. */
    public static function cityOf(string $location): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $location)), fn($p) => $p !== ''));
        return $parts[0] ?? '';
    }

    /** Last comma-separated part when the location has at least two parts, else '' — never a hardcoded code. */
    public static function countryOf(string $location): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $location)), fn($p) => $p !== ''));
        return count($parts) >= 2 ? $parts[count($parts) - 1] : '';
    }

    private static function humanIndustry(string $industry): string
    {
        $h = trim(str_replace(['_', '-'], ' ', $industry));
        return $h === '' ? 'business' : $h;
    }

    /** Lower-cased word tokens (letters only), two or more characters. */
    private static function tokens(string $s): array
    {
        preg_match_all('/\p{L}{2,}/u', mb_strtolower($s), $m);
        return array_values(array_unique($m[0]));
    }
}
