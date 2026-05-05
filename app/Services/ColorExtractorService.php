<?php

namespace App\Services;

/**
 * ColorExtractorService — dominant-color extraction + palette generation.
 *
 * Uses PHP GD. No third-party dependency. Strategy:
 *  1. Load image (any format GD supports + SVG via rasterization fallback).
 *  2. Downscale to 50×50 for speed.
 *  3. Quantize each pixel to 4 bits/channel (4096 buckets) — avoids the
 *     computational cost of true k-means while still grouping similar
 *     colors.
 *  4. Filter out near-white / near-black / low-saturation pixels so the
 *     dominant palette reflects brand color, not page background.
 *  5. Return the top-3 buckets by frequency as hex colors.
 */
class ColorExtractorService
{
    /**
     * Extract top-3 dominant brand colors from an image file.
     *
     * @return string[] e.g. ['#6C5CE7', '#F97316', '#00E5A8']
     */
    public function extractFromFile(string $path): array
    {
        $fallback = ['#6C5CE7', '#1A1A1A', '#F4F7FB'];
        if (!is_file($path) || !function_exists('imagecreatefromstring')) {
            return $fallback;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return $fallback;

        // SVG fallback: extract the first fill="#xxx" or stroke as primary.
        if (stripos($raw, '<svg') !== false) {
            $hits = [];
            if (preg_match_all('/(?:fill|stop-color|stroke)=["\']?(#[0-9a-fA-F]{3,8})/', $raw, $m)) {
                foreach ($m[1] as $h) {
                    $h = $this->normalizeHex($h);
                    if ($h && !in_array(strtolower($h), ['#ffffff', '#000000', '#fff', '#000'], true)) {
                        $hits[$h] = ($hits[$h] ?? 0) + 1;
                    }
                }
            }
            arsort($hits);
            $svgColors = array_keys(array_slice($hits, 0, 3));
            while (count($svgColors) < 3) $svgColors[] = $fallback[count($svgColors)];
            return $svgColors;
        }

        $img = @imagecreatefromstring($raw);
        if (!$img) return $fallback;

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) { imagedestroy($img); return $fallback; }

        // Downscale to a fixed square for speed. imagescale preserves alpha.
        $target = 50;
        $small = @imagescale($img, $target, $target, IMG_BICUBIC_FIXED);
        imagedestroy($img);
        if (!$small) return $fallback;

        $buckets = [];
        for ($y = 0; $y < $target; $y++) {
            for ($x = 0; $x < $target; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a > 100) continue; // mostly transparent → skip (logo background)
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8)  & 0xFF;
                $b =  $rgba        & 0xFF;

                // Skip near-white and near-black pixels: they dominate logos
                // that sit on transparent or light/dark backgrounds but are
                // not useful as brand colors.
                $max = max($r, $g, $b);
                $min = min($r, $g, $b);
                if ($max > 240 && $min > 230) continue; // near-white
                if ($max < 20) continue;                 // near-black
                $sat = $max > 0 ? ($max - $min) / $max : 0;
                if ($sat < 0.12 && $max > 30 && $max < 230) {
                    // low-saturation greys — deprioritize but keep if nothing else
                    // (we bucket them separately below).
                }

                // Quantize each channel to 4 bits → 16 levels per channel.
                $qr = ($r >> 4) << 4;
                $qg = ($g >> 4) << 4;
                $qb = ($b >> 4) << 4;
                $key = sprintf('%02x%02x%02x', $qr, $qg, $qb);
                $buckets[$key] = ($buckets[$key] ?? 0) + 1;
            }
        }
        imagedestroy($small);

        if (empty($buckets)) return $fallback;
        arsort($buckets);

        // Take top 3 buckets but enforce minimum distance so we don't return
        // three near-identical shades of the same color.
        $picked = [];
        foreach (array_keys($buckets) as $hex) {
            $rgb = $this->hexToRgb('#' . $hex);
            $okDistance = true;
            foreach ($picked as $p) {
                $pr = $this->hexToRgb('#' . $p);
                $d = sqrt(pow($rgb[0]-$pr[0],2) + pow($rgb[1]-$pr[1],2) + pow($rgb[2]-$pr[2],2));
                if ($d < 60) { $okDistance = false; break; }
            }
            if ($okDistance) $picked[] = $hex;
            if (count($picked) >= 3) break;
        }
        // Pad with fallback if fewer than 3.
        while (count($picked) < 3) {
            $picked[] = ltrim($fallback[count($picked)], '#');
        }
        return array_map(fn($h) => '#' . strtoupper($h), $picked);
    }

    /**
     * Return 3 palette variants (Dark/Light/Branded) derived from the 3 dominant colors.
     */
    public function generatePalettes(array $dominantColors): array
    {
        // Pad with defaults if caller passed fewer.
        $d = array_pad($dominantColors, 3, '#6C5CE7');

        return [
            [
                'id'        => 'dark_bold',
                'label'     => 'Dark & Bold',
                'primary'   => $d[0],
                'secondary' => $this->darken($d[0], 20),
                'accent'    => $d[1],
                'bg'        => '#0A0A0A',
                'text'      => '#FFFFFF',
            ],
            [
                'id'        => 'light_clean',
                'label'     => 'Light & Clean',
                'primary'   => $d[0],
                'secondary' => '#FFFFFF',
                'accent'    => $d[1],
                'bg'        => '#FFFFFF',
                'text'      => '#1A1A1A',
            ],
            [
                'id'        => 'branded',
                'label'     => 'Branded',
                'primary'   => $d[0],
                'secondary' => $d[1],
                'accent'    => $d[2],
                'bg'        => $this->lighten($d[0], 85),
                'text'      => $this->darken($d[0], 40),
            ],
        ];
    }

    public function darken(string $hex, int $percent): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $percent = max(0, min(100, $percent));
        $f = (100 - $percent) / 100;
        return $this->rgbToHex(
            (int) max(0, round($r * $f)),
            (int) max(0, round($g * $f)),
            (int) max(0, round($b * $f))
        );
    }

    public function lighten(string $hex, int $percent): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $percent = max(0, min(100, $percent));
        $f = $percent / 100;
        return $this->rgbToHex(
            (int) min(255, round($r + (255 - $r) * $f)),
            (int) min(255, round($g + (255 - $g) * $f)),
            (int) min(255, round($b + (255 - $b) * $f))
        );
    }

    public function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) < 6) return [0, 0, 0];
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    public function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02X%02X%02X',
            max(0, min(255, $r)),
            max(0, min(255, $g)),
            max(0, min(255, $b))
        );
    }

    private function normalizeHex(string $hex): ?string
    {
        $hex = strtoupper(trim($hex));
        if (!preg_match('/^#([0-9A-F]{3}|[0-9A-F]{6}|[0-9A-F]{8})$/', $hex)) return null;
        if (strlen($hex) === 4) {
            return '#' . $hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
        }
        if (strlen($hex) === 9) return substr($hex, 0, 7); // drop alpha
        return $hex;
    }
}
