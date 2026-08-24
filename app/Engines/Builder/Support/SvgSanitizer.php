<?php

namespace App\Engines\Builder\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * BUILDER888 P0-4 (2026-08-10) — SVG upload sanitiser.
 *
 * Uploaded logos are served from the application origin as image/svg+xml with
 * no Content-Disposition, so navigating directly to one executes any script it
 * contains in the levelupgrowth.io origin — where the SPA keeps its bearer
 * token in localStorage. Proven executable during the BUILDER888 audit.
 *
 * SVG stays a supported logo format (it is the right format for a logo); the
 * active content is removed instead. Allowlist, not denylist: anything not
 * explicitly permitted is dropped.
 */
final class SvgSanitizer
{
    /** Elements permitted in an uploaded logo. */
    private const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath',
        'linearGradient', 'radialGradient', 'stop', 'pattern',
        'clipPath', 'mask', 'filter',
        // 'style' REMOVED (P1-7 Family 4): only attribute values were
        // inspected, so @import / expression() inside a <style> body passed
        // through and could load an external stylesheet. Logos do not need a
        // <style> element — inline style attributes remain allowed and ARE
        // checked. Reversible: restore with text-content sanitisation.
        'feGaussianBlur', 'feOffset', 'feBlend', 'feColorMatrix', 'feMerge',
        'feMergeNode', 'feFlood', 'feComposite', 'feDropShadow',
    ];

    /** Never allowed, whatever else is true. */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'style', 'foreignobject', 'iframe', 'embed', 'object', 'audio',
        'video', 'animate', 'animatetransform', 'animatemotion', 'set',
        'handler', 'listener', 'image',
    ];

    /**
     * @return string|null sanitised SVG, or null if it is not usable as one
     */
    public static function sanitize(string $svg): ?string
    {
        if (trim($svg) === '' || ! str_contains(strtolower($svg), '<svg')) {
            return null;
        }

        // Block entity-expansion and external-entity attacks before parsing.
        //
        // P1-7 Family 4: the original pattern required an internal subset "[",
        // so an EXTERNAL DTD reference — <!DOCTYPE svg SYSTEM "http://…"> —
        // passed straight through. LIBXML_NONET blocks the fetch, but a
        // document declaring an external DTD has no business being accepted as
        // a logo. Refuse any DOCTYPE carrying SYSTEM/PUBLIC, and any ENTITY.
        if (preg_match('/<!ENTITY/i', $svg)) {
            return null;
        }
        if (preg_match('/<!DOCTYPE[^>]*(\[|\bSYSTEM\b|\bPUBLIC\b)/i', $svg)) {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        $doc->preserveWhiteSpace = false;

        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok || ! $doc->documentElement) {
            return null;
        }

        if (strtolower($doc->documentElement->localName) !== 'svg') {
            return null;
        }

        self::strip($doc->documentElement);

        // Drop any processing instructions or doctype that survived.
        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE || $node->nodeType === XML_DOCUMENT_TYPE_NODE) {
                $doc->removeChild($node);
            }
        }

        $out = $doc->saveXML($doc->documentElement);

        return $out === false ? null : $out;
    }

    private static function strip(DOMElement $el): void
    {
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $name = strtolower($child->localName);

                if (in_array($name, self::FORBIDDEN_ELEMENTS, true)
                    || ! in_array($name, array_map('strtolower', self::ALLOWED_ELEMENTS), true)) {
                    $el->removeChild($child);
                    continue;
                }

                self::strip($child);
            }
        }

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            /** @var DOMAttr $attr */
            $n = strtolower($attr->nodeName);
            $v = $attr->nodeValue ?? '';

            // Every event handler.
            if (str_starts_with($n, 'on')) {
                $el->removeAttributeNode($attr);
                continue;
            }

            // Script-bearing or remote URLs in any href/src-like attribute.
            if (in_array($n, ['href', 'xlink:href', 'src', 'from', 'to', 'values', 'begin'], true)) {
                $probe = strtolower(preg_replace('/[\x00-\x20]|&#[0-9a-fx]+;?/i', '', $v));
                $isSafeFragment = str_starts_with($probe, '#');
                $isSafeData     = str_starts_with($probe, 'data:image/png')
                               || str_starts_with($probe, 'data:image/jpeg');
                if (! $isSafeFragment && ! $isSafeData) {
                    $el->removeAttributeNode($attr);
                    continue;
                }
            }

            // url(javascript:…) and expression() smuggled through style/filter.
            if (preg_match('/javascript:|expression\s*\(|behaviou?r\s*:|@import/i', $v)) {
                $el->removeAttributeNode($attr);
            }
        }
    }
}
