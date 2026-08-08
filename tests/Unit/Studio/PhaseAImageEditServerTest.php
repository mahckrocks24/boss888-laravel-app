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
 * STUDIO888 · Phase A1-A3 — AI Image Editing (background removal / object removal / replace).
 *
 * Pure unit test (NO database, NO app boot). The edit itself runs through the COMMITTED
 * edit_image capability (kernel -> Creative -> ImageEditService -> gpt-image-1 /v1/images/edits,
 * Laravel-direct) and is proven end-to-end by browser QA in the report. The part that lives in
 * Studio and is unit-testable is: placing the edited child asset's URL on the image field via the
 * committed `src` projection (verified + atomic), the safety gate on the URL, and the field guards.
 */
final class PhaseAImageEditServerTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head></head><body>'
        . '<img data-field="hero_image" src="https://cdn.example.com/old.jpg">'
        . '<h1 data-field="headline">Title</h1>'
        . '</body></html>';

    private static function structured(): array
    {
        return ['template_slug' => 'restaurant', 'fields' => ['headline' => 'Old']];
    }

    private static function srcReq(string $t, string $url): ProjectionRequest
    {
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'chat-edit-img', 'document_id' => 'd',
            'target_id' => $t, 'changed_fields' => ['src'], 'desired_after_state' => ['src' => $url],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    private static function place(HtmlProjectionAdapter $a, ProjectionRequest $req)
    {
        return $a->projectBatch(new ProjectionBatch('b', 'd', [$req], ProjectionTransactionBoundary::atomic(), null, 'c'));
    }

    /** 1. The edited child asset URL places on the image field and verifies. */
    public function test_edited_url_places_and_verifies(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::place($a, self::srcReq('hero_image', 'https://app.example.com/storage/ai-images/2/edited.png'));
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(1, $res->appliedCount());
        $this->assertTrue(($res->results[0]->verification['verified'] ?? false) === true);
        $this->assertStringContainsString('edited.png', (string) $a->payload());
        $this->assertStringNotContainsString('old.jpg', (string) $a->payload());
    }

    /** 2. Placing an image src on a non-image field fails atomically (nothing changes). */
    public function test_placement_on_non_image_field_rolls_back(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $before = (string) $a->payload();
        $res = self::place($a, self::srcReq('headline', 'https://app.example.com/storage/x.png'));
        $this->assertNotSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame($before, (string) $a->payload());
    }

    /** 3. Structured template declines src (engine is text-only there). */
    public function test_structured_declines_src(): void
    {
        $a = HtmlProjectionAdapter::forStructured(self::structured());
        $before = $a->payload();
        $res = self::place($a, self::srcReq('hero_image', 'https://app.example.com/storage/x.png'));
        $this->assertSame(ProjectionStatus::FAILED, $res->status);
        $this->assertSame($before, $a->payload());
    }

    /** 4. supports('src') is true only for a unique image field. */
    public function test_supports_src_only_for_image_field(): void
    {
        $doc = HtmlProjectionAdapter::forRawHtml(self::RAW)->document();
        $this->assertTrue($doc->supports('hero_image', 'src'));
        $this->assertFalse($doc->supports('headline', 'src'));       // not an <img>
        $this->assertFalse($doc->supports('missing_image', 'src'));  // absent
    }

    /** 5. The URL safety gate accepts a same-origin edited asset and rejects unsafe schemes. */
    public function test_url_safety_gate(): void
    {
        $sec = new HtmlProjectionSecurityPolicy();
        $this->assertTrue($sec->isSafeImageUrl('https://app.example.com/storage/ai-images/2/edited.png'));
        $this->assertFalse($sec->isSafeImageUrl('javascript:alert(1)'));
        $this->assertFalse($sec->isSafeImageUrl('data:image/png;base64,AAAA'));
    }
}
