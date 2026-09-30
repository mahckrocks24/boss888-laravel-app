<?php

namespace App\Engines\Builder\Support;

/**
 * EDITOR-3 (RFC-0021 wave 3, 2026-10-01) — the editor lets the owner finish what Arthur drafted.
 *
 * REPORT-0068 #4 #5 #7 #11 #13 #15 and REPORT-0067 #13/#14: sections could not be hidden or removed, a covered photo
 * could not be reached, the Back dialog claimed a draft on a published site, the page picker spoke in template words,
 * uploads had no alt text, the phone toolbar hid Publish off-screen, and an 11-step tour covered the editor. This class
 * holds the section-visibility helpers, the customer-language page copy and the alt-text rule. The editor pieces live
 * in builder.js and the preview script. Kill switch: storage/app/editor3.on.
 */
final class Editor3
{
    public const SWITCH = 'app/editor3.on';

    /** What each page in the catalogue is, in the owner's words (REPORT-0068 #11). */
    public const PAGE_COPY = [
        'about'           => 'Your story, what you stand for, the people behind the business, a few kind words and a way to get in touch.',
        'services'        => 'What you offer, as cards, with a quick quote form and a few customer words.',
        'pricing'         => 'Your plans side by side, the common questions and a closing invitation.',
        'contact'         => 'How to reach you, with a message form that lands in your Clients list.',
        'faq'             => 'The questions customers ask most, with your answers.',
        'legal'           => 'A privacy policy and terms page, ready for your details.',
        'booking'         => 'Pick a service, choose a date and time, leave contact details; plus reasons to book and common questions.',
        'events'          => 'Your upcoming sessions, classes or events in a calendar, with a way to join.',
        'listing_browser' => 'Everything you offer in one browsable grid with filters: properties, rooms, products, vehicles or courses.',
        'listing_detail'  => 'One item in full: key details, photos, trust signals, an enquiry form and related items.',
        'locations'       => 'Where to find you: a map, your branches and how to get in touch.',
        'before_after'    => 'Before-and-after pairs, customer words and the numbers you are proud of.',
        'menu'            => 'Your menu by section, with dietary filters and a table reservation button.',
        'portfolio'       => 'Your work in a filterable grid, with customer words and a way to start a project.',
        'cart'            => 'The shopping basket: items, tax, delivery and total.',
        'checkout'        => 'Contact, delivery and payment in one flow, with the order summary alongside.',
        'account'         => 'The customer area: orders, addresses, profile and wishlist.',
    ];

    public static function on(): bool
    {
        return is_file(storage_path(self::SWITCH));
    }

    /** Alt text for a picture the owner placed: the business name and the slot, never empty. */
    public static function altFor(string $business, string $field): string
    {
        $slot = strtolower((string) preg_replace('/_(url|src|image|img|photo|picture)$/i', '', $field));
        $slot = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[_\d]+/', ' ', $slot)));
        $business = trim($business);
        if ($business !== '' && $slot !== '') return $business . ' – ' . $slot;
        return $business !== '' ? $business : ($slot !== '' ? $slot : 'photo');
    }

    /** Mark the named sections hidden in a page: an attribute the editor reads and an inline display:none visitors get. */
    public static function applyHiddenBlocks(string $html, array $blocks): string
    {
        foreach ($blocks as $block) {
            $b = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $block); if ($b === '') continue;
            $html = (string) preg_replace_callback('/<([a-z][a-z0-9]*)\b([^>]*\bdata-block="' . preg_quote($b, '/') . '"[^>]*)>/i', function ($m) {
                $attrs = $m[2];
                if (stripos($attrs, 'data-lu-hidden=') !== false) return $m[0];
                if (preg_match('/\bstyle="([^"]*)"/i', $attrs, $sm)) { $attrs = str_replace($sm[0], 'style="' . rtrim($sm[1], '; ') . ($sm[1] !== '' ? ';' : '') . 'display:none/*lu-hidden*/"', $attrs); }
                else { $attrs .= ' style="display:none/*lu-hidden*/"'; }
                return '<' . $m[1] . $attrs . ' data-lu-hidden="1">';
            }, $html);
        }
        return $html;
    }

    /** The menu links that point at a removed section go with it (reversible: they are marked, not deleted). */
    public static function hideNavLinks(string $html, string $sectionId, string $block): string
    {
        $id = (string) preg_replace('/[^a-z0-9_\-]/i', '', $sectionId); if ($id === '') return $html;
        return (string) preg_replace_callback('/<a\b([^>]*\bhref="#' . preg_quote($id, '/') . '"[^>]*)>/i', function ($m) use ($block) {
            $attrs = $m[1]; if (stripos($attrs, 'data-lu-hidden-link=') !== false) return $m[0];
            if (preg_match('/\bstyle="([^"]*)"/i', $attrs, $sm)) { $attrs = str_replace($sm[0], 'style="' . rtrim($sm[1], '; ') . ($sm[1] !== '' ? ';' : '') . 'display:none/*lu-hidden*/"', $attrs); }
            else { $attrs .= ' style="display:none/*lu-hidden*/"'; }
            return '<a' . $attrs . ' data-lu-hidden-link="' . htmlspecialchars($block, ENT_QUOTES, 'UTF-8') . '">';
        }, $html);
    }

    /** Show a hidden section (and its menu links) again. */
    public static function unhide(string $html, string $block): string
    {
        $b = (string) preg_replace('/[^a-z0-9_\-]/i', '', $block); if ($b === '') return $html;
        $strip = function (string $attrs): string {
            $attrs = (string) preg_replace('/\s*data-lu-hidden(?:-link)?="[^"]*"/i', '', $attrs);
            $attrs = (string) preg_replace_callback('/\bstyle="([^"]*)"/i', function ($sm) { $s = trim((string) preg_replace('/;?\s*display:none\/\*lu-hidden\*\/;?/i', ';', $sm[1]), '; '); return $s === '' ? '' : 'style="' . $s . '"'; }, $attrs);
            return (string) preg_replace('/\s{2,}/', ' ', $attrs);
        };
        $html = (string) preg_replace_callback('/<([a-z][a-z0-9]*)\b([^>]*\bdata-block="' . preg_quote($b, '/') . '"[^>]*\bdata-lu-hidden="1"[^>]*)>/i', fn ($m) => '<' . $m[1] . rtrim($strip($m[2])) . '>', $html);
        $html = (string) preg_replace_callback('/<a\b([^>]*\bdata-lu-hidden-link="' . preg_quote($b, '/') . '"[^>]*)>/i', fn ($m) => '<a' . rtrim($strip($m[1])) . '>', $html);
        return $html;
    }

    /** The id attribute of the named section, if it has one (menu links point at it). */
    public static function sectionId(string $html, string $block): ?string
    {
        $b = (string) preg_replace('/[^a-z0-9_\-]/i', '', $block);
        if ($b === '' || ! preg_match('/<[a-z][a-z0-9]*\b[^>]*\bdata-block="' . preg_quote($b, '/') . '"[^>]*>/i', $html, $m)) return null;
        return preg_match('/\bid="([^"]+)"/i', $m[0], $im) ? $im[1] : null;
    }
}
