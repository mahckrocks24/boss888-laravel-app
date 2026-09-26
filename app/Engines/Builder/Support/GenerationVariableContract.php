<?php

namespace App\Engines\Builder\Support;

/**
 * BUILDER888 P1-8B (2026-08-10) — the boundary between provider output and the
 * Builder domain.
 *
 * Evidence that shaped this contract:
 *
 *  - The 31 templates declare 1,890 distinct placeholders and **every one of
 *    them is scalar**. There is no list-valued placeholder anywhere. Repeating
 *    content is expressed as indexed families the template already lays out:
 *    service_1_title / service_2_title …, testimonial_1_quote …, amenity_3_text.
 *  - ArthurService::overlayUserServices() already demonstrates the intended
 *    pattern: take the customer's services array, expand it into
 *    service_{N}_title / service_{N}_text, toggle service_{N}_display.
 *
 * So the canonical shape of a Builder template variable map is
 * `array<string, string>` — flat, scalar, provider-neutral. The job of this
 * class is to turn an untrusted provider map into exactly that, expanding
 * lists into the indexed families the template actually declares, and
 * refusing anything it cannot represent instead of guessing.
 *
 * Before this existed, ArthurService merged raw model JSON straight into the
 * variable map, so the first array-valued key the model returned reached
 * str_replace() and killed the customer's build (frozen Journey A, P1-8).
 */
final class GenerationVariableContract
{
    /** Indexed families a list may legitimately expand into: base => [suffixes]. */
    private const FAMILIES = [
        'service'     => ['title', 'text'],
        'feature'     => ['title', 'text'],
        'amenity'     => ['title', 'text'],
        'testimonial' => ['quote', 'author'],
        'stat'        => ['value', 'label'],
        'plan'        => ['name', 'price'],
        'faq'         => ['q', 'a'],
        'step'        => ['title', 'text'],
        'award'       => ['name', 'issuer'],
    ];

    /** How many slots the templates lay out for an indexed family. */
    private const MAX_SLOTS = 6;

    /**
     * @param  array<string,mixed>  $raw            untrusted provider output
     * @param  array<string,bool>   $placeholders   placeholder set the template declares
     * @return array{variables: array<string,string>, expanded: array<int,string>, rejected: array<int,string>}
     */
    public static function fromProvider(array $raw, array $placeholders = []): array
    {
        $out      = [];
        $expanded = [];
        $rejected = [];

        // LLM JSON-mode artifact (2026-09-04): providers that require the word "json" in the
        // prompt to enable JSON mode (DeepSeek/OpenAI json_object) sometimes wrap the ENTIRE
        // payload in a single {"json": {...}} envelope (also seen: data/result/output/...).
        // A single top-level key whose value is an associative map is never valid template
        // output (every placeholder is scalar), so peel recognised envelopes first — otherwise
        // the whole build silently falls back to template defaults with no real content.
        $raw = self::unwrapEnvelope($raw);

        foreach ($raw as $key => $value) {
            if (! is_string($key) || $key === '') {
                $rejected[] = '(non-string key)';
                continue;
            }

            // Scalars, null and Stringable already have unambiguous meaning.
            if (! is_array($value) && ! is_object($value)) {
                $n = TemplateVariableNormalizer::normalize($value);
                if ($n['value'] !== '') {
                    $out[$key] = $n['value'];
                }
                continue;
            }

            if (is_object($value)) {
                $n = TemplateVariableNormalizer::normalize($value);
                if ($n['disposition'] === TemplateVariableNormalizer::RENDERED) {
                    if ($n['value'] !== '') { $out[$key] = $n['value']; }
                } else {
                    $rejected[] = $key . ' (object)';
                }
                continue;
            }

            if ($value === []) {
                continue;
            }

            // A list — the shape the model actually returns for repeating content.
            if (array_is_list($value)) {
                $base = self::familyFor($key);

                if ($base !== null) {
                    $n = self::expandList($base, $value, $placeholders, $out);
                    if ($n > 0) {
                        $expanded[] = $key . ' -> ' . $base . '_{1..' . $n . '}_*';
                        continue;
                    }
                }

                // No indexed family the template understands. A flat scalar list
                // still has an unambiguous text reading; anything else does not.
                $n = TemplateVariableNormalizer::normalize($value);
                if ($n['disposition'] === TemplateVariableNormalizer::RENDERED) {
                    if ($n['value'] !== '') { $out[$key] = $n['value']; }
                } else {
                    $rejected[] = $key . ' (list of structures, no indexed family)';
                }
                continue;
            }

            // Associative map: no scalar reading, no family. Refuse.
            $rejected[] = $key . ' (associative map)';
        }

        return ['variables' => $out, 'expanded' => $expanded, 'rejected' => $rejected];
    }

    /**
     * Peel single-key envelope wrappers a provider may put around the real field map.
     * Conservative: only recognised envelope names, only when the sole key holds an
     * associative (non-list, non-empty) map, up to a small nesting depth.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    public static function unwrapEnvelope(array $raw): array
    {
        $names = ['json', 'data', 'result', 'output', 'content', 'response', 'fields', 'variables', 'payload'];
        for ($i = 0; $i < 3; $i++) {
            if (count($raw) !== 1) { break; }
            $only = array_key_first($raw);
            $val  = $raw[$only];
            if (! is_string($only) || ! is_array($val) || $val === [] || array_is_list($val)) { break; }
            if (! in_array(strtolower($only), $names, true)) { break; }
            $raw = $val;
        }
        return $raw;
    }

    /** Map a provider key such as "services"/"service_list" onto a family base. */
    private static function familyFor(string $key): ?string
    {
        $k = strtolower($key);

        foreach (array_keys(self::FAMILIES) as $base) {
            if ($k === $base || $k === $base . 's' || $k === $base . '_list' || $k === $base . 's_list') {
                return $base;
            }
        }

        // Irregular plurals the model tends to use.
        return match ($k) {
            'faqs', 'questions'      => 'faq',
            'statistics', 'metrics'  => 'stat',
            'pricing', 'pricing_plans', 'packages' => 'plan',
            'steps', 'process'       => 'step',
            'reviews'                => 'testimonial',
            default                  => null,
        };
    }

    /**
     * Expand a list into the indexed placeholders the template declares.
     *
     * @param  array<string,string>  $out  written by reference
     * @return int number of slots filled
     */
    private static function expandList(string $base, array $list, array $placeholders, array &$out): int
    {
        $suffixes = self::FAMILIES[$base];
        $filled   = 0;

        foreach (array_slice($list, 0, self::MAX_SLOTS) as $i => $item) {
            $slot = $i + 1;

            if (is_scalar($item) || $item === null) {
                $key = "{$base}_{$slot}_{$suffixes[0]}";
                if (self::declared($key, $placeholders)) {
                    $out[$key] = trim((string) $item);
                    $out["{$base}_{$slot}_display"] = '';
                    $filled++;
                }
                continue;
            }

            if (is_object($item)) { $item = (array) $item; }
            if (! is_array($item)) { continue; }

            $wroteAny = false;
            foreach ($item as $field => $v) {
                if (! is_scalar($v) && $v !== null) { continue; }
                $key = "{$base}_{$slot}_" . strtolower((string) $field);
                if (self::declared($key, $placeholders)) {
                    $out[$key] = trim((string) $v);
                    $wroteAny = true;
                }
            }

            if ($wroteAny) {
                $out["{$base}_{$slot}_display"] = '';
                $filled++;
            }
        }

        // Hide the slots the model did not fill, matching overlayUserServices().
        if ($filled > 0) {
            for ($s = $filled + 1; $s <= self::MAX_SLOTS; $s++) {
                if (self::declared("{$base}_{$s}_display", $placeholders)) {
                    $out["{$base}_{$s}_display"] = 'display:none';
                }
            }
        }

        return $filled;
    }

    /** With no placeholder set supplied, accept the family shape on faith. */
    private static function declared(string $key, array $placeholders): bool
    {
        return $placeholders === [] || isset($placeholders[$key]);
    }
}
