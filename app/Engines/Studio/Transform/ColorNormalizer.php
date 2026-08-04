<?php

namespace App\Engines\Studio\Transform;

use App\Engines\Studio\Transform\Contracts\ColorNormalizerInterface;

/**
 * STUDIO888 · AI Transformation Engine — default Colour Normalizer.
 *
 * Accepts hex (#rgb / #rgba / #rrggbb / #rrggbbaa), rgb()/rgba(), and CSS
 * named colours; emits canonical lower-case hex. Anything else — CSS
 * functions, var(), url(), javascript:, calc/expression, markup — returns
 * null. This is a security boundary: it never passes an unrecognised string
 * through, so no colour value can smuggle in a URL, selector, or script.
 */
final class ColorNormalizer implements ColorNormalizerInterface
{
    /** Substrings that make a value categorically unsafe as a colour. */
    private const UNSAFE = ['javascript:', 'url(', 'expression(', 'calc(', 'var(', '<', '>', ';', '{', '}', '/*', '\\'];

    public function isValid(string $value): bool
    {
        return $this->normalize($value) !== null;
    }

    public function normalize(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $lower = strtolower($raw);

        foreach (self::UNSAFE as $bad) {
            if (str_contains($lower, $bad)) {
                return null;
            }
        }

        // #hex
        if ($lower[0] === '#') {
            return $this->normalizeHex($lower);
        }

        // rgb() / rgba()
        if (str_starts_with($lower, 'rgb')) {
            return $this->normalizeRgb($lower);
        }

        // named colour
        return self::NAMED[$lower] ?? null;
    }

    private function normalizeHex(string $h): ?string
    {
        $hex = ltrim($h, '#');
        if (! ctype_xdigit($hex)) {
            return null;
        }

        switch (strlen($hex)) {
            case 3: // rgb -> rrggbb
                return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            case 4: // rgba -> rrggbbaa
                return '#' . $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
            case 6:
                return '#' . $hex;
            case 8:
                return '#' . $hex;
            default:
                return null;
        }
    }

    private function normalizeRgb(string $v): ?string
    {
        // rgb(r,g,b) or rgba(r,g,b,a) — integers 0..255, alpha 0..1
        if (! preg_match('/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*(?:,\s*(0|1|0?\.\d+)\s*)?\)$/', $v, $m)) {
            return null;
        }

        $r = (int) $m[1];
        $g = (int) $m[2];
        $b = (int) $m[3];
        if ($r > 255 || $g > 255 || $b > 255) {
            return null;
        }

        $hex = sprintf('#%02x%02x%02x', $r, $g, $b);

        if (isset($m[4]) && $m[4] !== '') {
            $a = (float) $m[4];
            if ($a < 0 || $a > 1) {
                return null;
            }
            $hex .= sprintf('%02x', (int) round($a * 255));
        }

        return $hex;
    }

    /**
     * CSS named colours (canonical hex). A pragmatic, extensible subset covering
     * the CSS basics plus common brand tones. "red" => "#ff0000" (acceptance).
     */
    private const NAMED = [
        'black' => '#000000', 'white' => '#ffffff', 'red' => '#ff0000', 'green' => '#008000',
        'lime' => '#00ff00', 'blue' => '#0000ff', 'yellow' => '#ffff00', 'cyan' => '#00ffff',
        'aqua' => '#00ffff', 'magenta' => '#ff00ff', 'fuchsia' => '#ff00ff', 'silver' => '#c0c0c0',
        'gray' => '#808080', 'grey' => '#808080', 'maroon' => '#800000', 'olive' => '#808000',
        'purple' => '#800080', 'teal' => '#008080', 'navy' => '#000080', 'orange' => '#ffa500',
        'gold' => '#ffd700', 'pink' => '#ffc0cb', 'brown' => '#a52a2a', 'coral' => '#ff7f50',
        'crimson' => '#dc143c', 'indigo' => '#4b0082', 'violet' => '#ee82ee', 'turquoise' => '#40e0d0',
        'salmon' => '#fa8072', 'khaki' => '#f0e68c', 'lavender' => '#e6e6fa', 'beige' => '#f5f5dc',
        'ivory' => '#fffff0', 'tan' => '#d2b48c', 'skyblue' => '#87ceeb', 'royalblue' => '#4169e1',
        'steelblue' => '#4682b4', 'slategray' => '#708090', 'slategrey' => '#708090',
        'darkred' => '#8b0000', 'darkgreen' => '#006400', 'darkblue' => '#00008b',
        'lightgray' => '#d3d3d3', 'lightgrey' => '#d3d3d3', 'transparent' => '#00000000',
    ];
}
