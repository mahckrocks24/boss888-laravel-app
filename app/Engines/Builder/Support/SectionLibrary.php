<?php

namespace App\Engines\Builder\Support;

/**
 * SectionLibrary — bespoke, theme-aware section blocks injected into templates
 * that lack a proper industry section (P2b-full). Blocks REUSE each template's
 * own design-system classes (.services / .service-grid / .service-card /
 * .section-h2 / .eyebrow) plus its CSS variables (--fh heading font, --carbon
 * text, --chalk card bg, --divider) so they inherit the theme automatically;
 * every var() has a safe literal fallback for templates that name vars
 * differently. Added 2026-07-24.
 */
final class SectionLibrary
{
    /** A restaurant/catering MENU: dish name · dotted leader · price + description, 2-col. */
    public static function menuSection(array $items, array $labels): string
    {
        return self::pricedGrid('menu', $items, $labels, 2);
    }

    /**
     * A resort/short-term-rental ROOMS/UNITS grid: room name · rate + a capacity
     * meta line (Sleeps · size · view) + description. Photo cards when $images
     * are supplied (room photos fit generically, like travel), else text cards.
     */
    public static function unitsSection(array $items, array $labels, array $images = []): string
    {
        $images = array_values(array_filter($images, fn($u) => is_string($u) && $u !== ''));
        return empty($images)
            ? self::pricedGrid('rooms', $items, $labels, 3)
            : self::imageCatalog($items, $labels, $images);
    }

    /**
     * A retail/ecommerce CATALOG or travel DESTINATIONS grid. When $images are
     * supplied each card gets a photo (image-card lookbook style); otherwise it
     * falls back to the text priced-card grid.
     */
    public static function catalogSection(array $items, array $labels, array $images = []): string
    {
        $images = array_values(array_filter($images, fn($u) => is_string($u) && $u !== ''));
        if (empty($images)) {
            return self::pricedGrid('catalog', $items, $labels, 3);
        }
        return self::imageCatalog($items, $labels, $images);
    }

    private static function imageCatalog(array $items, array $labels, array $images): string
    {
        $eyebrow = e($labels['eyebrow'] ?? '');
        $title   = e($labels['title'] ?? '');
        $intro   = e($labels['intro'] ?? '');

        $cards = '';
        $n = count($images);
        $idx = 0;
        foreach ($items as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') continue;
            $price = trim((string) ($it['price'] ?? ''));
            $desc  = trim((string) ($it['desc'] ?? ''));
            $img   = $images[$idx % $n];
            $idx++;
            $meta = trim((string) ($it['meta'] ?? ''));
            $cards .= '<div class="service-card lu-card-img reveal">'
                . '<div class="lu-card-photo" style="background-image:url(\'' . e($img) . '\')"></div>'
                . '<div class="lu-card-body">'
                . '<div class="lu-item-row"><span class="lu-item-name">' . e($name) . '</span>'
                . ($price !== '' ? '<span class="lu-item-price">' . e($price) . '</span>' : '')
                . '</div>'
                . ($meta !== '' ? '<div class="lu-unit-meta">' . e($meta) . '</div>' : '')
                . ($desc !== '' ? '<div class="lu-item-desc">' . e($desc) . '</div>' : '')
                . '</div></div>';
        }
        if ($cards === '') return '';

        $style = '<style>'
            . '.lu-cat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:1.6rem}'
            . '@media(max-width:860px){.lu-cat-grid{grid-template-columns:1fr 1fr}}'
            . '@media(max-width:560px){.lu-cat-grid{grid-template-columns:1fr}}'
            . '.lu-card-img{padding:0;overflow:hidden;display:flex;flex-direction:column}'
            . '.lu-card-photo{aspect-ratio:4/3;width:100%;background-size:cover;background-position:center;background-color:var(--divider,#eee)}'
            . '.lu-card-body{padding:1.05rem 1.25rem 1.25rem}'
            . '.lu-item-row{display:flex;align-items:baseline;justify-content:space-between;gap:.6rem}'
            . '.lu-item-name{font-family:var(--fh,Georgia,serif);font-weight:600;font-size:1.08rem;color:var(--carbon,#1a1a1a)}'
            . '.lu-item-price{font-family:var(--fh,Georgia,serif);font-weight:700;white-space:nowrap;color:var(--carbon,#1a1a1a)}'
            . '.lu-unit-meta{font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;opacity:.6;margin-top:.3rem;color:var(--carbon,#333)}'
            . '.lu-item-desc{opacity:.72;font-size:.88rem;margin-top:.35rem;line-height:1.5;color:var(--carbon,#333)}'
            . '</style>';

        return '<section class="services" id="catalog" data-block="services">'
            . '<div class="services-hdr">'
            . ($eyebrow !== '' ? '<div class="eyebrow reveal"><span>' . $eyebrow . '</span></div>' : '')
            . '<h2 class="section-h2 reveal reveal-delay-1">' . $title . '</h2>'
            . ($intro !== '' ? '<p class="services-intro reveal reveal-delay-2">' . $intro . '</p>' : '')
            . '</div>'
            . '<div class="service-grid lu-cat-grid">' . $cards . '</div>'
            . $style
            . '</section>';
    }

    private static function pricedGrid(string $kind, array $items, array $labels, int $cols): string
    {
        $eyebrow = e($labels['eyebrow'] ?? '');
        $title   = e($labels['title'] ?? '');
        $intro   = e($labels['intro'] ?? '');

        $cards = '';
        foreach ($items as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') continue;
            $price = trim((string) ($it['price'] ?? ''));
            $desc  = trim((string) ($it['desc'] ?? ''));
            $meta  = trim((string) ($it['meta'] ?? ''));
            $cards .= '<div class="service-card lu-item-card reveal">'
                . '<div class="lu-item-row"><span class="lu-item-name">' . e($name) . '</span>'
                . ($price !== '' ? '<span class="lu-item-dots"></span><span class="lu-item-price">' . e($price) . '</span>' : '')
                . '</div>'
                . ($meta !== '' ? '<div class="lu-unit-meta">' . e($meta) . '</div>' : '')
                . ($desc !== '' ? '<div class="lu-item-desc">' . e($desc) . '</div>' : '')
                . '</div>';
        }
        if ($cards === '') return ''; // nothing to inject → caller keeps original block

        $style = '<style>'
            . '.lu-grid{display:grid;grid-template-columns:repeat(' . $cols . ',1fr);gap:1.1rem 2.2rem}'
            . '@media(max-width:860px){.lu-grid{grid-template-columns:1fr 1fr}}'
            . '@media(max-width:560px){.lu-grid{grid-template-columns:1fr}}'
            . '.lu-item-card{padding:1.2rem 1.45rem}'
            . '.lu-item-row{display:flex;align-items:baseline;gap:.55rem}'
            . '.lu-item-name{font-family:var(--fh,Georgia,serif);font-weight:600;font-size:1.12rem;color:var(--carbon,#1a1a1a)}'
            . '.lu-item-dots{flex:1;border-bottom:1px dotted var(--divider,#d8d8d8);transform:translateY(-3px)}'
            . '.lu-item-price{font-family:var(--fh,Georgia,serif);font-weight:700;white-space:nowrap;color:var(--carbon,#1a1a1a)}'
            . '.lu-unit-meta{font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;opacity:.6;margin-top:.35rem;color:var(--carbon,#333)}'
            . '.lu-item-desc{opacity:.72;font-size:.9rem;margin-top:.4rem;line-height:1.5;color:var(--carbon,#333)}'
            . '</style>';

        return '<section class="services" id="' . e($kind) . '" data-block="services">'
            . '<div class="services-hdr">'
            . ($eyebrow !== '' ? '<div class="eyebrow reveal"><span>' . $eyebrow . '</span></div>' : '')
            . '<h2 class="section-h2 reveal reveal-delay-1">' . $title . '</h2>'
            . ($intro !== '' ? '<p class="services-intro reveal reveal-delay-2">' . $intro . '</p>' : '')
            . '</div>'
            . '<div class="service-grid lu-grid">' . $cards . '</div>'
            . $style
            . '</section>';
    }

    /**
     * Replace a whole <section data-block="X"> … </section> with $newHtml.
     * Same anchor-boundary approach as TemplateArchetypes::removeBlocks — cut
     * from the target block's anchor to the next block's anchor. No-op if the
     * block isn't present or $newHtml is empty.
     */
    public static function replaceBlock(string $html, string $blockId, string $newHtml): string
    {
        if ($newHtml === '' || $html === '') return $html;
        if (!preg_match_all('/<(?:section|div|header|footer|aside|nav)\b[^>]*\bdata-block="([^"]+)"/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $n = count($m[0]);
        $target = strtolower($blockId);
        for ($i = 0; $i < $n; $i++) {
            if (strtolower($m[1][$i][0]) === $target) {
                $from = $m[0][$i][1];
                $to   = ($i + 1 < $n) ? $m[0][$i + 1][1] : strlen($html);
                return substr($html, 0, $from) . $newHtml . substr($html, $to);
            }
        }
        return $html;
    }
}
