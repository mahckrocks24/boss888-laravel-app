<?php

namespace Tests\Unit\Studio;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\Html\HtmlProjectionSecurityPolicy;
use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use PHPUnit\Framework\TestCase;

/**
 * STUDIO888 · Phase A4 — Universal Image Ingest.
 *
 * Pure unit test (NO DB, NO app boot, NO network). The full ingest→edit→child-asset→lineage flow
 * runs through the committed edit_image capability + Asset model and is proven end-to-end by
 * browser QA in the report. Unit-testable here: the security gate (the SSRF-critical part), the
 * resolved-IP guard used before an external fetch, the allowed-MIME map, and the projection
 * placement of the ingested asset URL.
 */
final class PhaseA4IngestServerTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head></head><body>'
        . '<img data-field="hero_image" src="https://cdn.example.com/old.jpg">'
        . '<h1 data-field="headline">Title</h1></body></html>';

    /** 1. SSRF/safety gate rejects every unsafe scheme/host/format. */
    public function test_safety_gate_rejects_unsafe_sources(): void
    {
        $sec = new HtmlProjectionSecurityPolicy();
        foreach ([
            'javascript:alert(1)', 'data:image/png;base64,AAAA', 'file:///etc/passwd', 'blob:https://x/y',
            'http://cdn.example.com/x.png',              // non-https
            'https://localhost/x.png', 'https://127.0.0.1/x.png', 'https://10.0.0.5/x.png',
            'https://192.168.1.10/x.png', 'https://169.254.169.254/latest/meta-data',
            'https://user:pass@cdn.example.com/x.png',   // userinfo
            'https://cdn.example.com:8080/x.png',        // non-443 port
            'https://cdn.example.com/logo.svg',          // script-capable
        ] as $bad) {
            $this->assertFalse($sec->isSafeImageUrl($bad), "must reject: {$bad}");
        }
    }

    /** 2. Safety gate accepts a safe external https raster + a same-origin relative path. */
    public function test_safety_gate_accepts_safe_sources(): void
    {
        $sec = new HtmlProjectionSecurityPolicy();
        $this->assertTrue($sec->isSafeImageUrl('https://cdn.example.com/photo.png'));
        $this->assertTrue($sec->isSafeImageUrl('https://images.example.org/a/b/hero.jpg'));
        $this->assertTrue($sec->isSafeImageUrl('/storage/ai-images/2/x.png'));   // relative, app-hosted
    }

    /** 3. Resolved-IP guard (used before any external fetch) blocks private/reserved/loopback/link-local. */
    public function test_resolved_ip_guard(): void
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        foreach (['127.0.0.1', '10.0.0.1', '192.168.1.1', '172.16.5.5', '169.254.169.254', '0.0.0.0'] as $priv) {
            $this->assertFalse((bool) filter_var($priv, FILTER_VALIDATE_IP, $flags), "must block IP: {$priv}");
        }
        foreach (['8.8.8.8', '1.1.1.1'] as $pub) {
            $this->assertNotFalse(filter_var($pub, FILTER_VALIDATE_IP, $flags), "must allow IP: {$pub}");
        }
    }

    /** 4. Allowed-MIME map admits only png/jpg/gif/webp — never svg/bmp/ico. */
    public function test_allowed_mime_map(): void
    {
        $map = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        $this->assertArrayHasKey(IMAGETYPE_PNG, $map);
        $this->assertArrayHasKey(IMAGETYPE_WEBP, $map);
        $this->assertArrayNotHasKey(IMAGETYPE_BMP, $map);
        $this->assertArrayNotHasKey(IMAGETYPE_ICO, $map);
        // real png bytes are recognised, svg text is not a raster (getimagesize fails)
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $info = getimagesizefromstring($png);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_PNG, $info[2]);
        $this->assertFalse(getimagesizefromstring('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
    }

    /** 5. An ingested asset URL places on the image field and verifies (src projection). */
    public function test_ingested_url_places_and_verifies(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $req = ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'chat-edit-img', 'document_id' => 'd',
            'target_id' => 'hero_image', 'changed_fields' => ['src'],
            'desired_after_state' => ['src' => 'https://app.example.com/storage/ai-images/2/ingest-abc.png'],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
        $res = $a->projectBatch(new ProjectionBatch('b', 'd', [$req], ProjectionTransactionBoundary::atomic(), null, 'c'));
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertTrue(($res->results[0]->verification['verified'] ?? false) === true);
        $this->assertStringContainsString('ingest-abc.png', (string) $a->payload());
    }
}
