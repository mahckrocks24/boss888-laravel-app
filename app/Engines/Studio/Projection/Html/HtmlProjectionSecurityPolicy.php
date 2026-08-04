<?php

namespace App\Engines\Studio\Projection\Html;

/**
 * STUDIO888 · Projection/Html — the markup security boundary.
 *
 * Everything markup-aware and dangerous is centralised here: text is written
 * with text-content semantics (escaped), style is confined to an allow-list,
 * and output is sanitised by DELETION ONLY (edit-only attributes, event
 * handlers, and <script> blocks are removed) so the rest of the document stays
 * byte-identical — no reformatting, no unrelated mutation.
 */
final class HtmlProjectionSecurityPolicy
{
    /** The only element-scoped style properties this adapter will ever write. */
    public const ALLOWED_STYLE_PROPERTIES = [
        'color', 'background-color', 'opacity', 'font-size', 'font-weight', 'text-align', 'display',
    ];

    public function isAllowedProperty(string $property): bool
    {
        return in_array($property, self::ALLOWED_STYLE_PROPERTIES, true);
    }

    public function escapeText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function decodeText(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array<string,string> ordered property => value */
    public function parseStyle(string $style): array
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            $decl = trim($decl);
            if ($decl === '' || ! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $value] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            if ($prop !== '') {
                $out[$prop] = trim($value);
            }
        }

        return $out;
    }

    /** @param array<string,string> $decls */
    public function serializeStyle(array $decls): string
    {
        $parts = [];
        foreach ($decls as $prop => $value) {
            $parts[] = "{$prop}:{$value}";
        }

        return implode('; ', $parts);
    }

    /**
     * Output sanitisation: delete edit-only attributes, event handlers, and
     * script blocks document-wide. Deletion only — no other bytes change.
     */
    public function sanitizeOutput(string $html): string
    {
        $patterns = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/\s+contenteditable(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/i',
            '/\s+spellcheck(\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/i',
            '/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '/\s+data-lu-edit[\w-]*\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
        ];

        return (string) preg_replace($patterns, '', $html);
    }
}
