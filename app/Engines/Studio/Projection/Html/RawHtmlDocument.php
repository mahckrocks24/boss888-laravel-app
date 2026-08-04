<?php

namespace App\Engines\Studio\Projection\Html;

use App\Engines\Studio\Projection\Html\Contracts\HtmlProjectionDocument;

/**
 * STUDIO888 · Projection/Html — a raw HTML document (string) the adapter mutates.
 *
 * Mutation is TARGETED: only the single `[data-field]` element's inner text or
 * opening-tag style attribute is spliced; every other byte is preserved. This
 * guarantees "no unrelated element mutation" and deterministic serialization —
 * far safer than a full DOM reserialize. No browser DOM, no persistence.
 *
 * Hide/show convention: hide -> element-scoped inline `display:none`;
 * show -> remove the `display:none` declaration (CSS then governs). Never
 * destroys the element, never infers an arbitrary display value.
 */
final class RawHtmlDocument implements HtmlProjectionDocument
{
    public function __construct(
        private string $html,
        private readonly HtmlProjectionSecurityPolicy $security = new HtmlProjectionSecurityPolicy(),
    ) {
    }

    public function form(): string
    {
        return self::FORM_RAW;
    }

    public function versionToken(): string
    {
        $canonical = $this->security->sanitizeOutput(str_replace("\r\n", "\n", trim($this->html)));

        return 'h:' . substr(hash('sha256', $canonical), 0, 16);
    }

    public function count(string $target): int
    {
        return (int) preg_match_all('/\bdata-field="' . preg_quote($target, '/') . '"/', $this->html);
    }

    public function supports(string $target, string $path): bool
    {
        if ($this->count($target) !== 1) {
            return false;
        }
        if ($path === 'text') {
            return $this->isLeafText($target);
        }
        if ($path === 'visible') {
            return true;
        }
        if (str_starts_with($path, 'style.')) {
            return $this->security->isAllowedProperty(substr($path, 6));
        }

        return false;
    }

    public function get(string $target, string $path): mixed
    {
        if ($path === 'text') {
            $inner = $this->innerText($target);

            return $inner === null ? null : $this->security->decodeText($inner);
        }
        if ($path === 'visible') {
            return $this->styleProp($target, 'display') !== 'none';
        }
        if (str_starts_with($path, 'style.')) {
            return $this->styleProp($target, substr($path, 6));
        }

        return null;
    }

    public function set(string $target, string $path, mixed $value): bool
    {
        if ($path === 'text') {
            return $this->setInnerText($target, (string) $value);
        }
        if ($path === 'visible') {
            return $this->setVisible($target, (bool) $value);
        }
        if (str_starts_with($path, 'style.')) {
            return $this->setStyleProp($target, substr($path, 6), (string) $value);
        }

        return false;
    }

    public function snapshotState(): mixed
    {
        return $this->html;
    }

    public function restoreState(mixed $state): void
    {
        $this->html = (string) $state;
    }

    public function payload(): mixed
    {
        return $this->security->sanitizeOutput($this->html);
    }

    // --- internal targeted-mutation helpers ---

    /** @return array{tag:string,attrs:string,start:int,end:int}|null */
    private function openingTag(string $target): ?array
    {
        $q = preg_quote($target, '/');
        if (! preg_match('/<([a-zA-Z][\w-]*)\b([^>]*\bdata-field="' . $q . '"[^>]*)>/', $this->html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [
            'tag'   => $m[1][0],
            'attrs' => $m[2][0],
            'start' => $m[0][1],
            'end'   => $m[0][1] + strlen($m[0][0]),
        ];
    }

    /** @return array{innerStart:int,innerEnd:int,inner:string}|null */
    private function innerRange(string $target): ?array
    {
        $ot = $this->openingTag($target);
        if ($ot === null) {
            return null;
        }
        $close = '</' . $ot['tag'];
        $pos = stripos($this->html, $close, $ot['end']);
        if ($pos === false) {
            return null;
        }

        return ['innerStart' => $ot['end'], 'innerEnd' => $pos, 'inner' => substr($this->html, $ot['end'], $pos - $ot['end'])];
    }

    private function innerText(string $target): ?string
    {
        $r = $this->innerRange($target);

        return $r === null ? null : $r['inner'];
    }

    private function isLeafText(string $target): bool
    {
        $inner = $this->innerText($target);

        return $inner !== null && ! str_contains($inner, '<');
    }

    private function setInnerText(string $target, string $text): bool
    {
        $r = $this->innerRange($target);
        if ($r === null || str_contains($r['inner'], '<')) {
            return false;
        }
        $escaped = $this->security->escapeText($text);
        if ($escaped === $r['inner']) {
            return false;
        }
        $this->html = substr($this->html, 0, $r['innerStart']) . $escaped . substr($this->html, $r['innerEnd']);

        return true;
    }

    private function styleAttr(string $target): string
    {
        $ot = $this->openingTag($target);
        if ($ot !== null && preg_match('/\bstyle="([^"]*)"/i', $ot['attrs'], $m)) {
            return $m[1];
        }

        return '';
    }

    private function styleProp(string $target, string $prop): ?string
    {
        $decls = $this->security->parseStyle($this->styleAttr($target));

        return $decls[strtolower($prop)] ?? null;
    }

    private function setStyleProp(string $target, string $prop, string $value): bool
    {
        $prop = strtolower($prop);
        $decls = $this->security->parseStyle($this->styleAttr($target));
        if (($decls[$prop] ?? null) === $value) {
            return false;
        }
        $decls[$prop] = $value;

        return $this->applyStyleDecls($target, $decls);
    }

    private function setVisible(string $target, bool $visible): bool
    {
        $current = $this->styleProp($target, 'display');
        if ($visible) {
            if ($current !== 'none') {
                return false;
            }
            $decls = $this->security->parseStyle($this->styleAttr($target));
            unset($decls['display']);

            return $this->applyStyleDecls($target, $decls);
        }
        if ($current === 'none') {
            return false;
        }

        return $this->setStyleProp($target, 'display', 'none');
    }

    /** @param array<string,string> $decls */
    private function applyStyleDecls(string $target, array $decls): bool
    {
        $ot = $this->openingTag($target);
        if ($ot === null) {
            return false;
        }
        $newStyle = $this->security->serializeStyle($decls);
        if ($newStyle === '') {
            $newAttrs = (string) preg_replace('/\s*\bstyle="[^"]*"/i', '', $ot['attrs']);
        } elseif (preg_match('/\bstyle="[^"]*"/i', $ot['attrs'])) {
            $newAttrs = (string) preg_replace('/\bstyle="[^"]*"/i', 'style="' . $newStyle . '"', $ot['attrs'], 1);
        } else {
            $newAttrs = rtrim($ot['attrs']) . ' style="' . $newStyle . '"';
        }
        $this->html = substr($this->html, 0, $ot['start']) . '<' . $ot['tag'] . $newAttrs . '>' . substr($this->html, $ot['end']);

        return true;
    }
}
