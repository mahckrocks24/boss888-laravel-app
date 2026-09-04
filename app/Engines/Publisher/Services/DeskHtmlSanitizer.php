<?php

namespace App\Engines\Publisher\Services;

/**
 * PUBLISHER888 Unit 2 — DOM-based allow-list HTML sanitiser for story and job bodies.
 * Regex sanitising is bypassable (malformed tags, entity tricks); this parses with libxml, walks the tree,
 * keeps only allowed elements/attributes, validates URL schemes, and re-serialises. Unknown elements are
 * unwrapped (their text survives), dangerous ones are dropped with their content.
 */
class DeskHtmlSanitizer
{
    private const ALLOWED = [
        'p' => [], 'br' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'sub' => [], 'sup' => [],
        'a' => ['href', 'title', 'target', 'rel'], 'ul' => [], 'ol' => ['start'], 'li' => [], 'blockquote' => ['cite'], 'figure' => [], 'figcaption' => [],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'], 'hr' => [], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'code' => [], 'pre' => [], 'span' => [], 'small' => [], 'mark' => [], 'div' => [],
    ];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'input', 'textarea', 'button', 'select', 'option', 'meta', 'link', 'base', 'svg', 'math', 'template', 'noscript', 'frame', 'frameset', 'video', 'audio', 'source', 'canvas', 'dialog'];

    public static function clean(string $html, int $maxBytes = 400000): string
    {
        $html = trim($html);
        if ($html === '') return '';
        if (strlen($html) > $maxBytes) $html = substr($html, 0, $maxBytes);
        if (!class_exists(\DOMDocument::class)) return self::fallback($html);
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="__root">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if (!$root) return self::fallback($html);
        self::walk($root, $doc);
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    private static function walk(\DOMNode $node, \DOMDocument $doc): void
    {
        $children = [];
        foreach ($node->childNodes as $c) $children[] = $c;
        foreach ($children as $c) {
            if ($c instanceof \DOMComment || $c instanceof \DOMProcessingInstruction || $c instanceof \DOMDocumentType) { $node->removeChild($c); continue; }
            if ($c instanceof \DOMText || $c instanceof \DOMCdataSection) continue;
            if (!($c instanceof \DOMElement)) { $node->removeChild($c); continue; }
            $tag = strtolower($c->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) { $node->removeChild($c); continue; }
            if (!isset(self::ALLOWED[$tag])) { // unwrap: keep children, drop the element
                self::walk($c, $doc);
                while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                $node->removeChild($c); continue;
            }
            $allowedAttrs = self::ALLOWED[$tag];
            $attrs = [];
            foreach ($c->attributes as $a) $attrs[] = $a;
            foreach ($attrs as $a) {
                $name = strtolower($a->name); $val = trim($a->value);
                if (!in_array($name, $allowedAttrs, true)) { $c->removeAttribute($a->name); continue; }
                if (in_array($name, ['href', 'src', 'cite'], true)) {
                    $ok = self::safeUrl($val, $name === 'src' && $tag === 'img');
                    if ($ok === null) { $c->removeAttribute($a->name); continue; }
                    $c->setAttribute($name, $ok);
                }
                if ($name === 'target') { if ($val !== '_blank') $c->removeAttribute('target'); else $c->setAttribute('rel', 'noopener noreferrer'); }
                if ($name === 'rel') $c->setAttribute('rel', 'noopener noreferrer' . (stripos($val, 'nofollow') !== false ? ' nofollow' : ''));
                if (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'start'], true) && !preg_match('/^\d{1,5}$/', $val)) $c->removeAttribute($name);
                if ($name === 'loading' && !in_array($val, ['lazy', 'eager'], true)) $c->removeAttribute('loading');
            }
            if ($tag === 'a' && $c->hasAttribute('target') && !$c->hasAttribute('rel')) $c->setAttribute('rel', 'noopener noreferrer');
            if ($tag === 'img' && !$c->hasAttribute('src')) { $node->removeChild($c); continue; }
            if ($tag === 'img' && !$c->hasAttribute('alt')) $c->setAttribute('alt', '');
            self::walk($c, $doc);
        }
    }

    /** Returns a normalised safe URL or null. Allows http(s), mailto, tel, relative paths, and data:image/* for images only. */
    public static function safeUrl(string $u, bool $imageDataOk = false): ?string
    {
        $u = trim(preg_replace('/[\x00-\x20\x7f]+/', '', $u) ?? '');
        if ($u === '') return null;
        if (strlen($u) > 2048) return null;
        if (preg_match('#^(https?:)?//#i', $u)) return preg_match('#^//#', $u) ? 'https:' . $u : $u;
        if (preg_match('#^(mailto:|tel:)#i', $u)) return $u;
        if (str_starts_with($u, '/') || str_starts_with($u, '#')) return $u;
        if ($imageDataOk && preg_match('#^data:image/(png|jpe?g|gif|webp|avif);base64,[A-Za-z0-9+/=]+$#i', $u)) return $u;
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u)) return null; // any other scheme (javascript:, vbscript:, data:text…)
        return $u; // bare relative path
    }

    private static function fallback(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|input|textarea|button|meta|link|svg|math)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? '';
        $html = strip_tags($html, '<p><br><h2><h3><h4><strong><b><em><i><u><s><a><ul><ol><li><blockquote><figure><figcaption><img><hr><table><thead><tbody><tr><th><td><code><pre><span>');
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        return preg_replace('/\s+(href|src)\s*=\s*("|\')\s*(javascript|vbscript|data):[^"\']*\2/i', ' $1=$2#$2', $html) ?? '';
    }
}
