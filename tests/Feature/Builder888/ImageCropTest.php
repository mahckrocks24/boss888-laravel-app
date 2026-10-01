<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\ImageCrop;
use Tests\TestCase;

/** CROP TOOL (2026-09-06): every placement has ONE fixed output size; crops always end at exactly that size. */
class ImageCropTest extends TestCase
{
    private function jpg(int $w, int $h): string { $p = sys_get_temp_dir() . '/lu_crop_' . uniqid() . '.jpg'; $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 200, 80, 47)); imagefilledrectangle($im, 0, 0, $w, (int) ($h * 0.2), imagecolorallocate($im, 27, 42, 73)); imagejpeg($im, $p, 90); return $p; }

    public function test_every_placement_has_a_fixed_size_and_field_rules_reach_it(): void
    {
        foreach (ImageCrop::TARGETS as $slot => $t) { $this->assertGreaterThan(0, $t['w']); $this->assertGreaterThan(0, $t['h']); $this->assertContains($t['mode'], ['crop', 'contain'], $slot); }
        $resolve = function (string $field) { foreach (ImageCrop::FIELD_RULES as [$re, $slot]) { if (preg_match('/' . $re . '/', $field)) return $slot; } return null; };
        $this->assertSame('hero', $resolve('hero_image')); $this->assertSame('avatar', $resolve('member_3_image')); $this->assertSame('gallery', $resolve('gallery_image_2'));
        $this->assertSame('gallery', $resolve('image_4')); $this->assertSame('logo', $resolve('logo_url')); $this->assertSame('og', $resolve('og_image')); $this->assertSame('section', $resolve('about_image'));
    }

    public function test_auto_crop_lands_on_the_exact_target_size(): void
    {
        $src = $this->jpg(4000, 3000); $dest = sys_get_temp_dir() . '/lu_crop_out_' . uniqid() . '.jpg';
        $r = ImageCrop::auto($src, 'hero', $dest);
        $this->assertSame([1920, 1080], [$r['width'], $r['height']]); [$w, $h] = getimagesize($dest); $this->assertSame([1920, 1080], [$w, $h]);
        $this->assertSame([0, 375, 4000, 2250], $r['frame'], 'centred 16:9 frame of a 4:3 source');
        $a = ImageCrop::auto($src, 'avatar', $dest); $this->assertSame([800, 800], [$a['width'], $a['height']]);
        $portrait = $this->jpg(1000, 2000); $p = ImageCrop::auto($portrait, 'avatar', $dest); $this->assertLessThan(500, $p['frame'][1], 'portrait in a person slot is top-weighted');
        @unlink($src); @unlink($portrait); @unlink($dest);
    }

    public function test_a_customer_frame_is_honoured_and_resampled_to_the_target(): void
    {
        $src = $this->jpg(3000, 2000); $dest = sys_get_temp_dir() . '/lu_crop_out_' . uniqid() . '.jpg';
        $r = ImageCrop::crop($src, 'gallery', 100, 200, 1200, 900, $dest);
        $this->assertSame([1600, 1200], [$r['width'], $r['height']]); $this->assertSame([100, 200, 1200, 900], $r['frame']);
        $c = ImageCrop::crop($src, 'gallery', 2900, 1900, 5000, 5000, $dest); $this->assertSame([2900, 1900, 100, 100], $c['frame'], 'frame is clamped to the source');
        @unlink($src); @unlink($dest);
    }

    public function test_logos_are_fitted_on_a_transparent_fixed_canvas_never_cut(): void
    {
        $src = sys_get_temp_dir() . '/lu_crop_logo_' . uniqid() . '.png'; $im = imagecreatetruecolor(400, 400); imagealphablending($im, false); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); imagefilledellipse($im, 200, 200, 380, 380, imagecolorallocate($im, 42, 157, 143)); imagepng($im, $src);
        $dest = sys_get_temp_dir() . '/lu_crop_logo_out_' . uniqid() . '.jpg';
        $r = ImageCrop::auto($src, 'logo', $dest);
        $this->assertSame('contain', $r['mode']); $this->assertSame([800, 260], [$r['width'], $r['height']]); $this->assertStringEndsWith('.png', $r['path'], 'logo output is PNG for transparency');
        $g = imagecreatefrompng($r['path']); $this->assertSame(127, (imagecolorat($g, 5, 5) >> 24) & 0x7F, 'padding is transparent'); $this->assertNotSame(127, (imagecolorat($g, 400, 130) >> 24) & 0x7F, 'the mark is in the middle');
        @unlink($src); @unlink($r['path']);
    }

    public function test_crop_api_and_editor_wiring_are_present(): void
    {
        $routes = (string) file_get_contents(base_path('routes/api.php'));
        $this->assertStringContainsString("Route::post('/media/crop', [\\App\\Http\\Controllers\\Api\\MediaCropController::class, 'crop'])", $routes);
        $this->assertStringContainsString("Route::get('/builder/image-policy'", $routes);
        $js = (string) file_get_contents(public_path('app/js/builder.js'));
        $this->assertStringContainsString('_t3CropForField(info, { url: url, media_id: file.id || null }', $js, 'picker result goes through the crop tool');
        $this->assertStringContainsString('window.luCrop.open(', $js);
        $this->assertFileExists(public_path('app/js/lu-crop.js'));
        // fix-all 2026-10-01: the crop tool ships inside the app bundle (/root/r190/build-bundle.sh) since r190
        $this->assertTrue(str_contains((string) file_get_contents(public_path('app/index.html')), 'lu-crop.js?v=') || in_array('lu-crop.js', array_map('trim', file(public_path('app/js/app.bundle.list')) ?: []), true), 'lu-crop.js is loaded by the page or by the bundle');
        $svc = (string) file_get_contents(base_path('app/Engines/Builder/Services/ArthurService.php'));
        $this->assertStringContainsString('ImageCrop::autoSafe($src, $slot, $dest)', $svc, 'hand-off files are cut to the placement size');
        $this->assertStringContainsString('self::cropPoolImageForSlot(', $svc, 'build-time photos are cut to the slot size');
    }
}