<?php

namespace App\Engines\Builder\Support;

use Stringable;

/**
 * BUILDER888 P1-8A (2026-08-10) — containment for template variable values.
 *
 * `TemplateService::render()` substitutes variables with str_replace(), which
 * accepts only strings. Everything that reaches it used to be guarded by a
 * bare `?? ''` — that covers null and nothing else, so the first array-valued
 * variable produced by the model killed the whole customer creation journey:
 *
 *   str_replace(): Argument #2 ($replace) must be of type string
 *                  when argument #1 ($search) is a string
 *
 * This class is deliberately a SEAM, not the answer. P1-8B replaces the
 * DEFERRED disposition with a typed Builder generation contract that renders
 * structured fields as real markup. Until then a structured value is omitted
 * and reported — never guessed at, never flattened into meaningless text, and
 * never dumped as "Array" or raw JSON into a customer's page.
 */
final class TemplateVariableNormalizer
{
    /** Value became a string safe to substitute. */
    public const RENDERED = 'rendered';

    /** Value is structured; omitted here and owed a real renderer by P1-8B. */
    public const DEFERRED = 'deferred';

    /**
     * @return array{value: string, disposition: string}
     */
    public static function normalize(mixed $value): array
    {
        // Scalars keep exactly the semantics they had before P1-8, so no
        // already-published site changes appearance. PHP cast, unchanged:
        // true => "1", false => "", 12 => "12".
        if ($value === null) {
            return self::rendered('');
        }

        if (is_string($value)) {
            return self::rendered($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return self::rendered((string) $value);
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return self::rendered((string) $value);
        }

        if (is_array($value)) {
            if ($value === []) {
                return self::rendered('');
            }

            // A flat list of scalars is the one shape whose text meaning is
            // unambiguous: ["A","B","C"] reads as "A, B, C".
            if (array_is_list($value) && self::allScalar($value)) {
                $parts = [];
                foreach ($value as $item) {
                    $s = trim((string) (is_bool($item) ? ($item ? '1' : '') : $item));
                    if ($s !== '') {
                        $parts[] = $s;
                    }
                }

                return self::rendered(implode(', ', $parts));
            }

            // Associative maps and nested lists carry structure that a comma
            // join would destroy (["monday" => "9-5", ...] is not "9-5, 9-5").
            return self::deferred();
        }

        // stdClass from json_decode(), resources, closures, enums without a
        // string form — all structured or non-representable.
        return self::deferred();
    }

    /**
     * G-SEC2 (2026-08-25) — the render-boundary XSS defence.
     * Every template variable is AI- or client-supplied and is substituted into
     * served HTML. HTML-escape it by default (ENT_QUOTES covers both element and
     * attribute context). URL-shaped keys additionally get scheme-validated so a
     * javascript:/data:/vbscript: URL cannot execute from an href/src; the value
     * is still escaped afterwards so a quote cannot break out of the attribute.
     * The template variable inventory (99 files) is entirely text + URL keys —
     * none carry intentional markup — so escaping introduces no visible change
     * for legitimate content while neutralising stored XSS.
     */
    public static function forHtml(string $key, string $value): string
    {
        $k = strtolower($key);
        $isUrl = str_ends_with($k, '_url') || str_ends_with($k, '_image')
              || str_starts_with($k, 'social_')
              || in_array($k, ['canonical_url', 'og_image', 'hero_image', 'footer_url', 'logo_url'], true);

        if ($isUrl) {
            $v = trim($value);
            // Drop dangerous URL schemes; allow http(s), protocol-relative, root/relative, mailto, tel.
            if ($v !== '' && preg_match('#^\s*(javascript|data|vbscript|file)\s*:#i', $v)) {
                $v = '';
            }
            return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }

        // CSS-context keys (colors/fonts) land inside <style> blocks. htmlspecialchars
        // neutralises </style> (no XSS) but leaves { } ; which break out of a CSS rule/
        // declaration -> CSS injection (defacement / CSS-exfil). Strip the rule-breakout
        // chars while preserving hex / rgb() / gradients / named colors.
        $isCss = str_ends_with($k, '_color') || str_ends_with($k, '_muted')
              || str_starts_with($k, 'font_')
              || in_array($k, ['hero_cta_primary', 'hero_cta_secondary'], true);
        if ($isCss) {
            $v = preg_replace('/[{};"\'\\<>]/', '', $value) ?? '';
            return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function allScalar(array $list): bool
    {
        foreach ($list as $item) {
            if (! is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    private static function rendered(string $value): array
    {
        return ['value' => $value, 'disposition' => self::RENDERED];
    }

    private static function deferred(): array
    {
        return ['value' => '', 'disposition' => self::DEFERRED];
    }
}
