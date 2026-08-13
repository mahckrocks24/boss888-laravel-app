<?php

namespace Tests\Feature\Studio;

use App\Jobs\RenderHtmlAnimatedJob;
use Tests\TestCase;

/**
 * STUDIO888 — export field substitution regression (2026-08-13).
 *
 * The animated templates ship literal {{placeholder}} markup. The EDITOR fills
 * every field from the template's manifest.json and only then applies the
 * customer's edits, so a design persists ONLY the fields actually changed.
 * RenderHtmlAnimatedJob built its recorder field map from video_data alone, so
 * every untouched field reached the recorder unset and the raw "{{headline_1}}",
 * "{{cta_label}}", "{{stat_1_val}}" text was burned into the exported MP4 —
 * preview and export disagreed completely.
 *
 * No DB, no browser, no ffmpeg: this asserts the merge that produces the map.
 */
class VideoExportFieldsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/svexp_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function template(array $variables): string
    {
        file_put_contents($this->dir . '/manifest.json', json_encode(['variables' => $variables]));
        file_put_contents($this->dir . '/template.html', '<html></html>');
        return 'file://' . $this->dir . '/template.html';
    }

    /** @test */
    public function manifest_defaults_fill_every_field_the_customer_never_edited(): void
    {
        $url = $this->template([
            'headline_1' => ['type' => 'text', 'default' => 'WE'],
            'cta_label'  => ['type' => 'text', 'default' => 'Get a Quote'],
            'stat_1_val' => ['type' => 'text', 'default' => '500+'],
        ]);

        $fields = RenderHtmlAnimatedJob::buildExportFields($url, []);

        $this->assertSame('WE', $fields['headline_1']);
        $this->assertSame('Get a Quote', $fields['cta_label']);
        $this->assertSame('500+', $fields['stat_1_val']);

        // The literal placeholder must never survive into the recorder payload.
        foreach ($fields as $k => $v) {
            $this->assertStringNotContainsString('{{', $v, "field {$k} still holds a placeholder");
        }
    }

    /** @test */
    public function customer_edits_win_over_manifest_defaults(): void
    {
        $url = $this->template([
            'brand_name' => ['type' => 'text', 'default' => 'Logo Here'],
            'headline_1' => ['type' => 'text', 'default' => 'WE'],
        ]);

        $fields = RenderHtmlAnimatedJob::buildExportFields($url, [
            'fields' => ['brand_name' => 'BOSS888 QA 0813'],
        ]);

        $this->assertSame('BOSS888 QA 0813', $fields['brand_name'], 'customer edit must win');
        $this->assertSame('WE', $fields['headline_1'], 'untouched field must still get its default');
    }

    /** @test */
    public function every_manifest_variable_is_represented(): void
    {
        $vars = [];
        foreach (['a','b','c','d','e','f'] as $i => $k) {
            $vars[$k] = ['type' => 'text', 'default' => 'v' . $i];
        }
        $url = $this->template($vars);

        $fields = RenderHtmlAnimatedJob::buildExportFields($url, ['fields' => ['c' => 'edited']]);

        $this->assertCount(6, $fields, 'a partial map is what shipped placeholders');
        $this->assertSame('edited', $fields['c']);
    }

    /** @test */
    public function numeric_defaults_and_edits_are_coerced_to_strings(): void
    {
        $url = $this->template(['stat' => ['type' => 'text', 'default' => 500]]);

        $fields = RenderHtmlAnimatedJob::buildExportFields($url, ['fields' => ['other' => 25]]);

        $this->assertSame('500', $fields['stat']);
        $this->assertSame('25', $fields['other']);
    }

    /** @test */
    public function malformed_variables_are_skipped_without_throwing(): void
    {
        $url = $this->template([
            'ok'      => ['type' => 'text', 'default' => 'fine'],
            'nodef'   => ['type' => 'text'],
            'notarr'  => 'just-a-string',
            'arraydef'=> ['type' => 'text', 'default' => ['nested']],
        ]);

        $fields = RenderHtmlAnimatedJob::buildExportFields($url, []);

        $this->assertSame(['ok' => 'fine'], $fields);
    }

    /** @test */
    public function a_missing_manifest_still_yields_the_customer_overrides(): void
    {
        $url = 'file://' . $this->dir . '/does-not-exist/template.html';

        $fields = RenderHtmlAnimatedJob::buildExportFields($url, [
            'fields' => ['brand_name' => 'Only This'],
        ]);

        $this->assertSame(['brand_name' => 'Only This'], $fields);
    }

    /** @test */
    public function a_remote_https_template_does_not_break_the_merge(): void
    {
        $fields = RenderHtmlAnimatedJob::buildExportFields(
            'https://example.test/t/template.html',
            ['fields' => ['brand_name' => 'Remote']]
        );

        $this->assertSame(['brand_name' => 'Remote'], $fields);
    }

    /** @test */
    public function the_real_construction_manifest_yields_a_complete_map(): void
    {
        $tpl = storage_path('templates/studio/video/r01-construction/template.html');
        if (! is_file($tpl)) {
            $this->markTestSkipped('r01-construction template not present on this host');
        }

        $fields = RenderHtmlAnimatedJob::buildExportFields('file://' . $tpl, []);

        // Every data-field in the shipped template must be covered.
        foreach (['brand_name','tag','eyebrow','headline_1','headline_2','headline_accent',
                  'subheading','pill_1','pill_2','pill_3','cta_label',
                  'stat_1_val','stat_1_label','stat_2_val','stat_2_label','stat_3_val','stat_3_label',
                  'footer_brand','footer_url','footer_handle'] as $k) {
            $this->assertArrayHasKey($k, $fields, "manifest default missing for {$k}");
            $this->assertNotSame('', $fields[$k]);
        }
    }
}
