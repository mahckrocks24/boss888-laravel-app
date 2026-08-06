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

    /**
     * SAFETY gate for a palette colour VALUE (used by :root var writes). Accepts only
     * a hex colour, a single named-colour word, or a numeric rgb()/rgba(). Rejects
     * url()/var()/expression()/gradients/raw CSS/selectors/scripts and anything that
     * could break out of a `--var: VALUE;` declaration.
     */
    public function isSafeColorValue(string $value): bool
    {
        $v = trim($value);
        if ($v === '' || strlen($v) > 64) {
            return false;
        }
        $lower = strtolower($v);
        foreach (['url(', 'var(', 'expression(', 'javascript:', 'gradient', '@', '/*', '*/', ';', '{', '}', '<', '>', '"', "'", '`', '!'] as $bad) {
            if (str_contains($lower, $bad)) {
                return false;
            }
        }
        if (strpos($v, chr(92)) !== false) {   // backslash
            return false;
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $v)) {
            return true;
        }
        if (preg_match('/^[a-z]{1,24}$/i', $v)) {          // named colour word
            return true;
        }
        if (preg_match('/^rgba?\([0-9,.\s%]+\)$/i', $v)) {  // numeric rgb/rgba only
            return true;
        }

        return false;
    }

    /**
     * Provenance-agnostic SAFETY gate for an image `src` value: rejects any scheme,
     * host, or format that could execute script or reach internal networks. It does
     * NOT check host allow-listing (approved provenance) — the caller enforces that.
     * Pure syntax validation; NEVER fetches the URL (SSRF-safe).
     */
    public function isSafeImageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }
        if (preg_match('/[\s"\'<>`]/', $url) || strpos($url, chr(92)) !== false || preg_match('/[\x00-\x1f\x7f]/', $url)) {
            return false;
        }
        $lower = strtolower($url);
        foreach (['javascript:', 'data:', 'file:', 'blob:', 'vbscript:', 'about:', 'mailto:', 'tel:'] as $bad) {
            if (str_starts_with($lower, $bad)) {
                return false;
            }
        }
        // Same-origin relative path (inherits the app scheme + host); reject protocol-relative.
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return ! str_ends_with($lower, '.svg');
        }
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return false;
        }
        if (strtolower($p['scheme']) !== 'https') {
            return false;
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            return false;
        }
        $host = strtolower($p['host']);
        if (in_array($host, ['localhost', 'ip6-localhost'], true)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;   // loopback / private / link-local (169.254.169.254) / reserved
            }
        }
        $path = strtolower((string) ($p['path'] ?? ''));
        if (str_ends_with($path, '.svg')) {
            return false;       // script-capable format
        }

        return true;
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
