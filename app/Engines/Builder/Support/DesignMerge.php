<?php

namespace App\Engines\Builder\Support;

/**
 * DESIGN-UPDATES-1 — the three-way merge behind a design update. Inputs are three finished home pages:
 *   base     the design version the site was built on, rendered from the site's own variables
 *   new      the design as it is now, rendered from the same variables
 *   current  the page the owner has today (their edits, moves, added sections, uploads)
 * Per top-level section (data-block):
 *   unchanged by the owner (same structure as base)  → the new design's section, with the owner's words and pictures put back
 *   restructured by the owner, untouched by the fix  → the owner's section exactly as it is
 *   restructured by the owner AND changed by the fix → the owner's section exactly as it is, reported ("kept as you have it")
 *   removed by the owner                              → stays removed
 *   added by the owner                                → carried across as it is
 *   dropped by the new design                         → not shown, reported (the snapshot keeps it)
 *   new in the design                                 → added, reported
 * The head (styles, fonts, scripts) comes from the new design; the owner's title, description, sharing tags and site icon are
 * carried over. Pure string work: nothing here reads or writes a file.
 */
final class DesignMerge
{
    private const INLINE = ['br', 'b', 'i', 'em', 'strong', 'u', 'small', 'option', 'sup', 'sub', 'mark', 'wbr'];
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** Top-level sections: [['id','key','start','end','html']…] in page order, plus 'ok' false when the page can't be read. */
    public static function blocks(string $html): array
    {
        $masked = self::maskScripts($html);
        if (! preg_match_all('/<([a-z][a-z0-9]*)\b[^>]*\bdata-block="([a-z0-9_\-]+)"[^>]*>/i', $masked, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return ['ok' => true, 'list' => []];
        $out = []; $lastEnd = -1; $seen = [];
        foreach ($m as $hit) {
            $start = $hit[0][1]; if ($start < $lastEnd) continue;   // nested inside the previous section
            $tag = strtolower($hit[1][0]); $id = $hit[2][0];
            $end = self::closeOf($masked, $tag, $start);
            if ($end === null) return ['ok' => false, 'list' => [], 'why' => "section {$id} has no end"];
            $seen[$id] = ($seen[$id] ?? 0) + 1;
            $out[] = ['id' => $id, 'key' => $seen[$id] > 1 ? $id . '#' . $seen[$id] : $id, 'start' => $start, 'end' => $end, 'html' => substr($html, $start, $end - $start)];
            $lastEnd = $end;
        }
        return ['ok' => true, 'list' => $out];
    }

    /** The end offset (after the closing tag) of the element that opens at $start. */
    private static function closeOf(string $html, string $tag, int $start): ?int
    {
        if (in_array($tag, self::VOID, true)) { $gt = strpos($html, '>', $start); return $gt === false ? null : $gt + 1; }
        $depth = 0; $pos = $start; $len = strlen($html);
        $re = '/<(\/?)' . preg_quote($tag, '/') . '\b[^>]*>/i';
        while ($pos < $len && preg_match($re, $html, $t, PREG_OFFSET_CAPTURE, $pos)) {
            $at = $t[0][1]; $txt = $t[0][0];
            if ($t[1][0] === '/') { $depth--; if ($depth === 0) return $at + strlen($txt); }
            elseif (! str_ends_with($txt, '/>')) { $depth++; }
            $pos = $at + strlen($txt);
        }
        return null;
    }

    /** Script and style bodies blanked to spaces (same length), so markup inside them is never read as page structure. */
    private static function maskScripts(string $html): string
    {
        return (string) preg_replace_callback('#(<(script|style|template|textarea)\b[^>]*>)(.*?)(</\2>)#is', fn ($m) => $m[1] . str_repeat(' ', strlen($m[3])) . $m[4], $html);
    }

    /** The structure of a section: tags, classes, fields and sections — never the words, pictures or inline styles. */
    /**
     * The menu links of the owner's added pages (data-page, the "More" menu): [the block without them, the items]. The deploy
     * re-adds them after any re-render (reapplyPageNavLinks), so they never make a menu count as restructured.
     */
    public static function pageLinks(string $block): array
    {
        $items = [];
        $strip = function (string $re) use (&$block, &$items) { $block = (string) preg_replace_callback($re, function ($m) use (&$items) { $items[] = $m[0]; return ''; }, $block); };
        if (($p = strpos($block, '<div class="lu-more">')) !== false && ($e = self::closeOf($block, 'div', $p)) !== null) { $items[] = substr($block, $p, $e - $p); $block = substr($block, 0, $p) . substr($block, $e); }
        $strip('#<li style="list-style:none">\s*<a\b[^>]*\bdata-page="[^"]*"[^>]*>.*?</a>\s*</li>#is');
        $strip('#<a\b[^>]*\bdata-page="[^"]*"[^>]*>.*?</a>#is');
        return [$block, $items];
    }

    /** Put the owner's page links into a menu that lacks them: before the menu's call-to-action, else at the end of its links. */
    private static function addPageLinks(string $nav, array $items): string
    {
        $items = array_values(array_filter($items, function ($it) use ($nav) { return ! preg_match('/data-page="([^"]+)"/', $it, $m) || ! str_contains($nav, 'data-page="' . $m[1] . '"'); }));
        if (! $items) return $nav;
        $ins = implode('', $items);
        if (preg_match('/<a\b[^>]*class="[^"]*\bnav-cta\b[^"]*"[^>]*>/i', $nav, $cta, PREG_OFFSET_CAPTURE)) return substr($nav, 0, $cta[0][1]) . $ins . substr($nav, $cta[0][1]);
        if (($p = strpos($nav, 'nav-links')) !== false && preg_match('/<\/(?:ul|div)>/i', $nav, $end, PREG_OFFSET_CAPTURE, $p)) return substr($nav, 0, $end[0][1]) . $ins . substr($nav, $end[0][1]);
        return $nav;
    }

    public static function signature(string $block): string
    {
        $h = self::maskScripts(self::pageLinks($block)[0]);
        $h = (string) preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', '', $h);
        $h = (string) preg_replace('/<!--.*?-->/s', '', $h);
        preg_match_all('/<(\/?)([a-z][a-z0-9]*)\b([^>]*)>/i', $h, $m, PREG_SET_ORDER);
        $tok = []; $skip = null; $skipDepth = 0;
        foreach ($m as $t) {
            $close = $t[1] === '/'; $name = strtolower($t[2]); $attrs = $t[3];
            if ($skip !== null) {
                if ($name === $skip) { if ($close) { if (--$skipDepth === 0) { $skip = null; $tok[] = '/' . $name; } } elseif (! in_array($name, self::VOID, true)) $skipDepth++; }
                continue;
            }
            if (in_array($name, self::INLINE, true)) continue;
            if ($close) { $tok[] = '/' . $name; continue; }
            $field = preg_match('/\bdata-field="([^"]+)"/i', $attrs, $f) ? $f[1] : '';
            $blk = preg_match('/\bdata-block="([^"]+)"/i', $attrs, $b) ? $b[1] : '';
            $cls = preg_match('/\bclass="([^"]*)"/i', $attrs, $c) ? preg_split('/\s+/', trim($c[1])) : [];
            $cls = array_values(array_filter($cls, fn ($x) => $x !== '' && ! in_array($x, ['in', 'open', 'active', 'is-active', 'visible', 'shown'], true)));
            sort($cls);
            $tok[] = $name . ($field !== '' ? '@' . $field : '') . ($blk !== '' ? '#' . $blk : '') . ($cls ? '.' . implode('.', $cls) : '');
            if ($field !== '' && ! in_array($name, self::VOID, true) && ! str_ends_with($t[0], '/>')) { $skip = $name; $skipDepth = 1; }
        }
        return md5(implode(' ', $tok));
    }

    /**
     * Merge. $labels maps block id → the section's name for the owner. Returns
     * ['ok', 'html', 'changes' => [['kind','block','label','text']…], 'why'?].
     */
    public static function merge(string $current, string $base, string $new, array $labels = []): array
    {
        $C = self::blocks($current); $B = self::blocks($base); $N = self::blocks($new);
        foreach (['current' => $C, 'base' => $B, 'new' => $N] as $k => $x) if (! $x['ok']) return ['ok' => false, 'why' => "{$k}: " . ($x['why'] ?? 'unreadable'), 'html' => '', 'changes' => []];
        if (! $N['list']) return ['ok' => false, 'why' => 'the new design has no sections', 'html' => '', 'changes' => []];
        $byKey = fn (array $l) => array_column($l, null, 'key');
        $cb = $byKey($C['list']); $bb = $byKey($B['list']); $nb = $byKey($N['list']);
        $label = fn (string $id) => $labels[$id] ?? ucwords(str_replace(['_', '-', 'added '], [' ', ' ', ''], $id));
        $changes = []; $merged = [];   // [key => html]
        foreach ($C['list'] as $c) {
            $k = $c['key']; $inBase = isset($bb[$k]); $inNew = isset($nb[$k]);
            if (! $inBase) { $merged[$k] = $c['html']; $changes[] = ['kind' => 'added_kept', 'block' => $c['id'], 'label' => $label($c['id']), 'text' => $label($c['id']) . ': a section you added. It is carried across as it is.']; continue; }
            if (! $inNew) { $changes[] = ['kind' => 'removed_by_design', 'block' => $c['id'], 'label' => $label($c['id']), 'text' => $label($c['id']) . ': the new design no longer has this section, so it will not show. Its words and pictures stay in your saved copy and come back if you revert.']; continue; }
            $sc = self::signature($c['html']); $sb = self::signature($bb[$k]['html']); $sn = self::signature($nb[$k]['html']);
            if ($sc === $sb || $sc === $sn) { $merged[$k] = self::addPageLinks(self::transplant($nb[$k]['html'], $c['html']), self::pageLinks($c['html'])[1]); continue; }
            $merged[$k] = $c['html'];
            if ($sn !== $sb || self::norm($nb[$k]['html']) !== self::norm($bb[$k]['html'])) {
                $changes[] = ['kind' => 'kept_custom', 'block' => $c['id'], 'label' => $label($c['id']), 'text' => $label($c['id']) . ': you changed how this section is laid out, so it stays exactly as you have it. The design\'s improvement to this section is not applied here.'];
            }
        }
        // sections the owner removed stay removed; sections new in the design are added after the section they follow
        $order = array_keys($merged);
        $prev = null;
        foreach ($N['list'] as $n) {
            $k = $n['key'];
            if (isset($merged[$k]) || isset($bb[$k])) { if (isset($merged[$k])) $prev = $k; continue; }
            $pos = $prev !== null ? array_search($prev, $order, true) + 1 : 0;
            if ($prev === null) { $fi = array_search('footer', $order, true); $pos = $fi === false ? count($order) : $fi; }
            array_splice($order, $pos, 0, [$k]); $merged[$k] = $n['html']; $prev = $k;
            $changes[] = ['kind' => 'new_section', 'block' => $n['id'], 'label' => $label($n['id']), 'text' => $label($n['id']) . ': a new section from the design, with starter text you can edit or hide.'];
        }
        // the new page's own chrome around its sections: what sits before the first, between them, after the last
        $first = $N['list'][0]['start']; $last = end($N['list'])['end'];
        // content between sections (a ticker between the hero and the menu) belongs to the section before it; its fields keep the owner's words
        $gaps = function (array $list, string $html) { $g = []; foreach ($list as $i => $x) { $next = $list[$i + 1] ?? null; if ($next) $g[$x['key']] = substr($html, $x['end'], $next['start'] - $x['end']); } return $g; };
        $gapN = $gaps($N['list'], $new); $gapC = $gaps($C['list'], $current);
        $body = '';
        foreach ($order as $i => $k) {
            $body .= $merged[$k];
            if ($i === count($order) - 1) break;
            $g = $gapN[$k] ?? ($gapC[$k] ?? "\n");
            if (isset($gapN[$k], $gapC[$k]) && str_contains($g, 'data-field=')) $g = self::transplant($g, $gapC[$k]);
            $body .= $g;
        }
        $html = substr($new, 0, $first) . $body . substr($new, $last);
        $html = self::carryHead($current, $html);
        return ['ok' => true, 'html' => $html, 'changes' => $changes];
    }

    private static function norm(string $h): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $h));
    }

    /** The owner's title, description, sharing tags and site icon replace the new head's own. */
    public static function carryHead(string $from, string $into): string
    {
        if (! preg_match('#<head\b[^>]*>(.*?)</head>#is', $from, $fh) || ! preg_match('#<head\b[^>]*>(.*?)</head>#is', $into, $ih, PREG_OFFSET_CAPTURE)) return $into;
        $pats = ['title' => '#<title\b[^>]*>.*?</title>#is', 'desc' => '#<meta\b[^>]*\bname="description"[^>]*>#i', 'icon' => '#<link\b[^>]*\brel="(?:shortcut )?icon"[^>]*>#i', 'apple' => '#<link\b[^>]*\brel="apple-touch-icon"[^>]*>#i', 'canon' => '#<link\b[^>]*\brel="canonical"[^>]*>#i'];
        $head = $ih[1][0];
        $pats['og'] = '#<meta\b[^>]*\bproperty="og:[^"]+"[^>]*>(\s*<meta\b[^>]*\bproperty="og:[^"]+"[^>]*>)*#i';
        foreach ($pats as $p) {
            if (! preg_match_all($p, $fh[1], $mine)) continue;
            $mineHtml = implode("\n", $mine[0]);
            if (preg_match($p, $head, $at, PREG_OFFSET_CAPTURE)) {   // in place of the new head's own (the order of the head stays the design's)
                $rest = (string) preg_replace($p, '', substr($head, $at[0][1] + strlen($at[0][0])));
                $head = substr($head, 0, $at[0][1]) . $mineHtml . $rest;
            } else {
                $head = (string) preg_replace('#(<meta\b[^>]*charset[^>]*>)#i', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $mineHtml), $head, 1, $n);
                if (! $n) $head = $mineHtml . $head;
            }
        }
        if (preg_match('#<style id="lu-more-css">.*?</style>#is', $fh[1], $mc) && ! str_contains($head, 'id="lu-more-css"')) $head .= $mc[0];
        return substr($into, 0, $ih[1][1]) . $head . substr($into, $ih[1][1] + strlen($ih[1][0]));
    }

    /**
     * The new section, with the owner's words, pictures and links from their section put back field by field. String work on
     * the field elements only, so every byte the owner did not write is the new design's own.
     */
    public static function transplant(string $newBlock, string $curBlock): string
    {
        if (! str_contains($curBlock, 'data-field=')) return $newBlock;
        $cur = [];
        foreach (self::fieldEls($curBlock) as $f) $cur[$f['field']][] = $f;
        $els = self::fieldEls($newBlock); $seen = [];
        foreach ($els as $i => $e) { $seen[$e['field']] = ($seen[$e['field']] ?? -1) + 1; $els[$i]['n'] = $seen[$e['field']]; }
        $out = $newBlock;
        foreach (array_reverse($els) as $e) {   // last first: an edit never moves an element still to be edited
            $src = $cur[$e['field']][$e['n']] ?? ($cur[$e['field']][0] ?? null); if (! $src) continue;
            $gt = strpos($out, '>', $e['start']); if ($gt === false) continue;
            $open = substr($out, $e['start'], $gt + 1 - $e['start']); $o2 = $open;
            if ($e['tag'] === 'img') { foreach (['src', 'srcset', 'alt', 'style', 'data-lu-hidden-img', 'data-lu-empty'] as $a) $o2 = self::setAttr($o2, $a, self::attr($src['open'], $a)); }
            else {
                $srcStyle = html_entity_decode((string) self::attr($src['open'], 'style'), ENT_QUOTES);
                if (preg_match('/background-image\s*:\s*[^;]*url\([^;]*/i', $srcStyle, $bm)) {
                    $st = trim((string) preg_replace('/background-image\s*:\s*[^;]*;?/i', '', html_entity_decode((string) self::attr($o2, 'style'), ENT_QUOTES)));
                    $o2 = self::setAttr($o2, 'style', htmlspecialchars(($st !== '' ? rtrim($st, '; ') . ';' : '') . $bm[0], ENT_QUOTES));
                }
                if ($e['tag'] === 'a') foreach (['href', 'target', 'rel'] as $a) { $v = self::attr($src['open'], $a); if ($v !== null) $o2 = self::setAttr($o2, $a, $v); }
            }
            if (! $e['void'] && ! $e['wrapper'] && ! $src['wrapper'] && $src['inner'] !== null) {   // a wrapper's own fields are handled one by one
                $end = self::closeOf(self::maskScripts($out), $e['tag'], $e['start']);
                $innerEnd = $end === null ? null : $end - strlen('</' . $e['tag'] . '>');
                if ($innerEnd !== null && $innerEnd >= $gt + 1) $out = substr($out, 0, $gt + 1) . $src['inner'] . substr($out, $innerEnd);
            }
            if ($o2 !== $open) $out = substr($out, 0, $e['start']) . $o2 . substr($out, $gt + 1);
        }
        return $out;
    }

    /** Every field element of a fragment: field, tag, start, opening tag, inner html (null when void), whether it wraps other fields. */
    private static function fieldEls(string $html): array
    {
        $masked = self::maskScripts($html); $out = [];
        if (! preg_match_all('/<([a-z][a-z0-9]*)\b[^>]*\bdata-field="([a-z0-9_\-]+)"[^>]*>/i', $masked, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [];
        foreach ($m as $hit) {
            $tag = strtolower($hit[1][0]); $start = $hit[0][1]; $len = strlen($hit[0][0]);
            $void = in_array($tag, self::VOID, true) || str_ends_with($hit[0][0], '/>');
            $inner = null; $wrapper = false;
            if (! $void && ($end = self::closeOf($masked, $tag, $start)) !== null) {
                $inner = substr($html, $start + $len, $end - strlen('</' . $tag . '>') - $start - $len);
                $wrapper = (bool) preg_match('/\bdata-field="/', self::maskScripts($inner));
            }
            $out[] = ['field' => $hit[2][0], 'tag' => $tag, 'start' => $start, 'open' => substr($html, $start, $len), 'inner' => $inner, 'void' => $void, 'wrapper' => $wrapper];
        }
        return $out;
    }

    private static function attr(string $tag, string $a): ?string
    {
        return preg_match('/\s' . preg_quote($a, '/') . '="([^"]*)"/i', $tag, $m) ? $m[1] : null;
    }

    /** Set an attribute on an opening tag (null removes it). */
    private static function setAttr(string $tag, string $a, ?string $v): string
    {
        $re = '/\s' . preg_quote($a, '/') . '="[^"]*"/i';
        if ($v === null) return (string) preg_replace($re, '', $tag);
        $kv = ' ' . $a . '="' . $v . '"';
        if (preg_match($re, $tag)) return (string) preg_replace_callback($re, fn () => $kv, $tag, 1);
        return (string) preg_replace_callback('#\s*(/?)>$#', fn ($m) => $kv . $m[1] . '>', $tag, 1);
    }

    /** Every owner value the page carries, by field (the same reading as the editor's change list). */
    public static function values(string $html): array
    {
        // pictures by their src wherever the attribute sits (DraftEdits::fieldTexts only reads data-field before src), texts as
        // shown, a background photo when the element carries no text; plus each section's shown / hidden state
        $out = [];
        $body = preg_match('#<body\b.*#is', $html, $bm) ? $bm[0] : $html;
        foreach (self::fieldEls($body) as $e) {
            if (isset($out[$e['field']])) continue;
            if ($e['tag'] === 'img') { $out[$e['field']] = html_entity_decode((string) self::attr($e['open'], 'src'), ENT_QUOTES | ENT_HTML5, 'UTF-8'); continue; }
            $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', '', (string) $e['inner'])), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($t === '' && preg_match('/background-image\s*:\s*[^;"]*url\(([^)]*)\)/i', html_entity_decode((string) self::attr($e['open'], 'style'), ENT_QUOTES), $bg)) $t = trim($bg[1], "'\" ");
            $out[$e['field']] = mb_substr($t, 0, 200);
        }
        foreach (self::blocks($body)['list'] as $b) { $open = substr($b['html'], 0, (int) strpos($b['html'], '>')); $out['section:' . $b['key']] = stripos($open, 'data-lu-hidden="1"') !== false ? 'hidden' : 'shown'; }
        return $out;
    }
}
