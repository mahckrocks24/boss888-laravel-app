<?php

namespace App\Engines\Builder\Support;

/**
 * REPORT-0062 U3 (2026-09-20) — stable field identities for Arthur-added sections.
 *
 * BuilderRenderer's output carried no data-field, so an added section was a block a customer could not touch:
 * no double-click edit, no Arthur copy edit, no element ops. This gives every text leaf of a rendered section a
 * stable id — `added_{type}_{n}`, n in document order — and returns the texts so they can be mirrored into
 * template_variables, exactly what the template's own fields do. From then on the existing paths apply unchanged:
 * `TemplateService::updateField` (inline edit, XPath by data-field, textContent), `ArthurService::editStaticCopy`
 * (Arthur's copy edits, fields filtered by presence on the page), ELEMENT888 ops (data-field elements).
 *
 * Idempotent: a fragment that already carries an `added_*` data-field is returned as it is — numbering never shifts
 * under an existing section. Text inside <script>, <style>, <select>, <option>, <textarea> and <input> is never a
 * field; neither are elements with element children (an edit sets textContent, so only leaves are safe).
 */
final class AddedSectionFields
{
    private const LEAF_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'span', 'a', 'button', 'figcaption', 'strong', 'em', 'b', 'i', 'small', 'label', 'dt', 'dd', 'td', 'th', 'blockquote', 'cite', 'div'];
    private const SKIP_INSIDE = ['script', 'style', 'select', 'option', 'textarea', 'input', 'svg', 'noscript', 'template'];

    /**
     * @return array{html:string, values:array<string,string>, count:int}
     */
    public static function assign(string $html, string $type): array
    {
        $type = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($type));
        if ($html === '' || $type === '' || preg_match('/data-field="added_' . preg_quote($type, '/') . '_\d+"/', $html)) {
            return ['html' => $html, 'values' => [], 'count' => 0];
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $ok = $dom->loadHTML('<?xml encoding="UTF-8"?><div id="lu-asf-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $dom->getElementById('lu-asf-root');
        if (! $ok || ! $root) return ['html' => $html, 'values' => [], 'count' => 0];

        $values = []; $n = 0;
        $walk = function (\DOMNode $node) use (&$walk, &$values, &$n, $type) {
            if (! ($node instanceof \DOMElement)) return;
            $tag = strtolower($node->tagName);
            if (in_array($tag, self::SKIP_INSIDE, true)) return;
            $hasElementChild = false;
            foreach ($node->childNodes as $c) { if ($c instanceof \DOMElement) { $hasElementChild = true; break; } }
            if (! $hasElementChild && in_array($tag, self::LEAF_TAGS, true) && ! $node->hasAttribute('data-field')) {
                $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?? '');
                // a leaf worth editing: real words, not a glyph, a number strip value or a link with no text
                if ($text !== '' && mb_strlen($text) <= 600 && preg_match('/\p{L}{2,}|\p{N}/u', $text)) {
                    $n++;
                    $key = "added_{$type}_{$n}";
                    $node->setAttribute('data-field', $key);
                    $values[$key] = $text;
                }
            }
            foreach (iterator_to_array($node->childNodes) as $c) { $walk($c); }
        };
        foreach (iterator_to_array($root->childNodes) as $c) { $walk($c); }
        if ($n === 0) return ['html' => $html, 'values' => [], 'count' => 0];

        $out = '';
        foreach ($root->childNodes as $c) { $out .= $dom->saveHTML($c); }
        // the same UTF-8 entity restore the field editor uses, so a re-serialised fragment matches its siblings
        $out = self::restoreUtf8($out);
        return ['html' => $out, 'values' => $values, 'count' => $n];
    }

    /** libxml writes non-ASCII as numeric entities; the export keeps UTF-8 (TemplateService::restoreUtf8Entities does the same). */
    public static function restoreUtf8(string $html): string
    {
        return preg_replace_callback('/&#(\d+);/', fn ($m) => mb_chr((int) $m[1], 'UTF-8') ?: $m[0], $html) ?? $html;
    }

    /** The wrapper block for one added type, as it currently sits in an export (balanced <section> matching). */
    public static function extractBlock(string $doc, string $type): ?string
    {
        $type = (string) preg_replace('/[^a-z0-9_]/', '', strtolower($type));
        if (! preg_match('/<section\b[^>]*\bdata-block="added_' . preg_quote($type, '/') . '"[^>]*>/i', $doc, $m, PREG_OFFSET_CAPTURE)) return null;
        $start = $m[0][1]; $pos = $start + strlen($m[0][0]); $depth = 1;
        while (preg_match('/<(\/?)section\b[^>]*>/i', $doc, $t, PREG_OFFSET_CAPTURE, $pos)) {
            $pos = $t[0][1] + strlen($t[0][0]);
            $depth += $t[1][0] === '/' ? -1 : 1;
            if ($depth === 0) return substr($doc, $start, $pos - $start);
        }
        return null;
    }
}
