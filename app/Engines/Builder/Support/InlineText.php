<?php

namespace App\Engines\Builder\Support;

/**
 * TEXTSAFE-1 (RFC-0021 wave 1, 2026-10-01) — one canonical form for text the owner types or pastes in the editor.
 *
 * REPORT-0068 #1/#2: the editor posted contentEditable innerHTML as-is, the renderer escaped it, and visitors read
 * "<div>Second paragraph</div>" and "<p><b>Fully insured</b> …</p>" as literal text on the live site. This class
 * turns that innerHTML into what the owner meant: plain text with line breaks for text-typed fields, the existing
 * inline whitelist (br em strong b i span[class]) for html-typed fields (HtmlTypedVariablesTest contract), never a
 * tag that the site will print. rendered() is the exact HTML the site shows for a canonical value, so the editor can
 * display it and the static export can carry it (writeInto).
 *
 * Kill switch: storage/app/textsafe.on (absent = the behaviour before this class existed, byte for byte).
 */
final class InlineText
{
    public const SWITCH = 'app/textsafe.on';

    private const BLOCK = 'p|div|li|ul|ol|h[1-6]|tr|td|th|blockquote|section|article|header|footer|pre|table|tbody|thead';

    public static function on(): bool
    {
        return is_file(storage_path(self::SWITCH));
    }

    /** Titles, labels, buttons and menu items never hold a line break; Enter commits them. */
    public static function isSingleLine(string $field): bool
    {
        $f = strtolower($field);
        return (bool) preg_match('/(^|_)(title|eyebrow|name|role|label|value|cta|cta_\d|q|legal|heading|tagline|kicker|button|link)$|^(nav_|business_name$|hero_cta|contact_cta|nav_cta)/', $f);
    }

    /** 'html' for a manifest variable declared type:"html", else 'text'. */
    public static function fieldType(int $websiteId, string $field): string
    {
        $m = LogoFieldSemantics::manifestFor($websiteId);
        $t = strtolower((string) ($m['variables'][$field]['type'] ?? 'text'));
        return $t === 'html' ? 'html' : 'text';
    }

    /**
     * contentEditable innerHTML (or anything pasted) -> canonical value.
     * text: plain text, "\n" between lines, at most one blank line, no tags, entities decoded.
     * html: the inline whitelist kept, every other tag dropped, text escaped, "<br>" between lines.
     */
    public static function canonical(string $raw, string $type = 'text', bool $multiline = true): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $raw);
        $b = self::BLOCK;
        $s = (string) preg_replace('#<br\s*/?>#i', "\n", $s);
        $s = (string) preg_replace('#</(?:' . $b . ')\s*>\s*<(?:' . $b . ')\b[^>]*>#i', "\n", $s);   // "</div><div>" = one break
        $s = (string) preg_replace('#</?(?:' . $b . ')\b[^>]*>#i', "\n", $s);
        $s = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $s);   // never keep their contents as text
        if ($type === 'html') {
            $s = TemplateVariableNormalizer::inlineMarkup($s);
        } else {
            $s = (string) preg_replace('/<[^>]*>?/', '', $s);
            $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $s = str_replace("\xC2\xA0", ' ', $s);
        $lines = array_map(fn ($l) => trim((string) preg_replace('/[ \t]+/', ' ', $l)), explode("\n", $s));
        $s = implode("\n", $lines);
        $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
        $s = trim($s, "\n ");
        if (! $multiline) $s = trim((string) preg_replace('/\s*\n+\s*/', ' ', $s));
        if ($type === 'html') $s = str_replace("\n", '<br>', $s);
        return $s;
    }

    /** The HTML the site shows for a canonical value — the renderer's escape, with the line breaks. */
    public static function rendered(string $canonical, string $type = 'text'): string
    {
        if ($type === 'html') return TemplateVariableNormalizer::inlineMarkup($canonical);
        return str_replace("\n", '<br>', htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'));
    }

    /** Put a canonical value into a static-export element exactly as rendered() shows it. */
    public static function writeInto(\DOMElement $el, string $canonical, string $type = 'text'): void
    {
        while ($el->firstChild) $el->removeChild($el->firstChild);
        $doc = $el->ownerDocument;
        if ($type === 'html') {
            $frag = $doc->createDocumentFragment();
            $xml = (string) preg_replace('#<br\s*>#i', '<br/>', self::rendered($canonical, 'html'));
            if (@$frag->appendXML($xml)) { $el->appendChild($frag); return; }
            $el->textContent = html_entity_decode(strip_tags($canonical), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return;
        }
        foreach (explode("\n", $canonical) as $i => $line) {
            if ($i > 0) $el->appendChild($doc->createElement('br'));
            if ($line !== '') $el->appendChild($doc->createTextNode($line));
        }
    }
}
