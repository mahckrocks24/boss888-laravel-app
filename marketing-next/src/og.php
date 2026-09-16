<?php
declare(strict_types=1);
/**
 * Social preview images (1200×630) generated at build time on the brand frame: paper ground, ink title,
 * accent rule, mono footer. One per route, so a shared link never shows the 180px app icon again.
 */
function og_image(string $distDir, string $route, string $title, string $kicker = ''): string
{
    $slug = $route === '/' ? 'home' : trim(preg_replace('#[^a-z0-9]+#', '-', strtolower($route)), '-');
    $rel = "/assets/og/$slug.png";
    $out = $distDir . $rel;
    @mkdir(dirname($out), 0775, true);

    $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $mono = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
    if (! is_file($font)) { return ''; }

    $w = 1200; $h = 630;
    $im = imagecreatetruecolor($w, $h);
    $paper = imagecolorallocate($im, 0xF9, 0xF8, 0xF7);
    $ink = imagecolorallocate($im, 0x05, 0x05, 0x05);
    $ink2 = imagecolorallocate($im, 0x55, 0x55, 0x55);
    $accent = imagecolorallocate($im, 0x7C, 0x3A, 0xED);
    imagefilledrectangle($im, 0, 0, $w, $h, $paper);
    imagefilledrectangle($im, 0, 0, 14, $h, $accent);

    // wordmark
    imagettftext($im, 26, 0, 80, 92, $ink, $font, 'LevelUpGrowth');
    if ($kicker !== '') { imagettftext($im, 20, 0, 80, 150, $accent, $mono, strtoupper($kicker)); }

    // title, wrapped to the frame
    $size = mb_strlen($title) > 70 ? 44 : (mb_strlen($title) > 40 ? 52 : 60);
    $lines = []; $line = '';
    foreach (preg_split('/\s+/', trim($title)) as $word) {
        $try = trim("$line $word");
        $box = imagettfbbox($size, 0, $font, $try);
        if (($box[2] - $box[0]) > $w - 160 && $line !== '') { $lines[] = $line; $line = $word; } else { $line = $try; }
        if (count($lines) === 4) { break; }
    }
    if ($line !== '' && count($lines) < 4) { $lines[] = $line; }
    $y = $kicker !== '' ? 230 : 200;
    foreach ($lines as $l) { imagettftext($im, $size, 0, 80, $y, $ink, $font, $l); $y += (int) ($size * 1.35); }

    // footer
    imagefilledrectangle($im, 80, $h - 110, $w - 80, $h - 108, imagecolorallocate($im, 0xDD, 0xDD, 0xDD));
    imagettftext($im, 20, 0, 80, $h - 60, $ink2, $mono, 'levelupgrowth.io');
    imagettftext($im, 18, 0, 700, $h - 60, $ink2, $mono, 'The business you own, run by AI');

    imagepng($im, $out, 6);
    imagedestroy($im);

    return '/next' . $rel;
}
