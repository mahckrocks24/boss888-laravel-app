<?php

namespace Tests\Unit\Studio;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\Html\HtmlProjectionSecurityPolicy;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/**
 * STUDIO888 · Feature Sprint 2 — verified image replacement (projection `src` field).
 * Pure unit test (no DB/app): exercises exactly the committed projection calls the
 * /studio/chat image block makes, plus the URL security policy. Generation glue + route
 * wiring are proven by browser QA in the report.
 */
final class ImageEditServerTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head><style>:root{--primary:#FFD60A}</style></head><body>'
        . '<img data-field="hero_image" src="https://staging.levelupgrowth.io/storage/old.png" alt="Hero" crossorigin="anonymous">'
        . '<div data-field="logo_box"><img src="/storage/logo-a.png"></div>'
        . '<h1 data-field="headline" style="color:#111111">Big Sale</h1>'
        . '<img data-field="dup" src="/1.png"><img data-field="dup" src="/2.png">'
        . '</body></html>';

    private static function req(string $target, string $url): ProjectionRequest
    {
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'i', 'document_id' => 'd', 'target_id' => $target,
            'changed_fields' => ['src'], 'desired_after_state' => ['src' => $url],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    // ---- projection `src` field ----

    public function test_self_img_src_replaced_and_verified(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $url = 'https://staging.levelupgrowth.io/storage/ai-images/2/new.png';
        $r = $a->project(self::req('hero_image', $url));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertTrue(($r->verification['verified'] ?? false) === true);
        $this->assertSame($url, $r->actualAfterState['src']);              // actual read-back
        $html = (string) $a->payload();
        $this->assertStringContainsString('src="' . $url . '"', $html);
        $this->assertStringNotContainsString('storage/old.png', $html);
        $this->assertStringContainsString('alt="Hero"', $html);            // unrelated attrs preserved
        $this->assertStringContainsString('crossorigin="anonymous"', $html);
        $this->assertStringContainsString('Big Sale', $html);              // text unchanged
        $this->assertStringContainsString('--primary:#FFD60A', $html);     // palette unchanged
    }

    public function test_child_img_src_replaced(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req('logo_box', '/storage/logo-b.png'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('/storage/logo-b.png', $r->actualAfterState['src']);
    }

    public function test_missing_target_rejected(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $this->assertNotSame(ProjectionStatus::APPLIED, $a->project(self::req('nope', '/x.png'))->status);
    }

    public function test_duplicate_target_rejected(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $this->assertNotSame(ProjectionStatus::APPLIED, $a->project(self::req('dup', '/x.png'))->status);
    }

    public function test_non_image_target_rejected(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req('headline', '/x.png'));   // headline is <h1> text, not image
        $this->assertNotSame(ProjectionStatus::APPLIED, $r->status);
    }

    public function test_same_url_is_noop_failure(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req('hero_image', 'https://staging.levelupgrowth.io/storage/old.png'));
        $this->assertNotSame(ProjectionStatus::APPLIED, $r->status);
    }

    public function test_unsafe_url_does_not_apply(): void
    {
        foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,x', 'file:///etc/passwd',
                  'https://169.254.169.254/latest', 'https://evil.test/x.svg', 'not a url'] as $bad) {
            $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
            $before = (string) $a->payload();
            $r = $a->project(self::req('hero_image', $bad));
            $this->assertNotSame(ProjectionStatus::APPLIED, $r->status, "unsafe: {$bad}");
            $this->assertSame($before, (string) $a->payload(), "unchanged: {$bad}");   // no injection/mutation
        }
    }

    // ---- URL security policy ----

    public function test_url_policy_accepts_safe(): void
    {
        $p = new HtmlProjectionSecurityPolicy();
        foreach ([
            'https://staging.levelupgrowth.io/storage/ai-images/2/x.png',
            '/storage/media/y.jpg',
            'https://cdn.example.com/a.webp',
        ] as $ok) {
            $this->assertTrue($p->isSafeImageUrl($ok), "should accept {$ok}");
        }
    }

    public function test_url_policy_rejects_unsafe(): void
    {
        $p = new HtmlProjectionSecurityPolicy();
        foreach ([
            'javascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', 'blob:xyz',
            'http://staging.levelupgrowth.io/x.png',        // not https
            'https://user:pass@host/x.png',                 // userinfo
            'https://127.0.0.1/x.png', 'https://10.0.0.5/x.png', 'https://192.168.1.9/x.png',
            'https://169.254.169.254/latest/meta-data',     // cloud metadata
            'https://localhost/x.png', 'https://host:8080/x.png',
            'https://host/logo.svg',                        // script-capable format
            'not-a-url', '//protocol-relative.test/x.png', 'https://has space/x.png',
        ] as $bad) {
            $this->assertFalse($p->isSafeImageUrl($bad), "should reject {$bad}");
        }
    }
}
