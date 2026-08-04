<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\Html\Contracts\HtmlProjectionDocument;
use App\Engines\Studio\Projection\Html\HtmlProjectionReason;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/** Pure - structured template documents: mutate fields, preserve identity, never collapse. */
final class StructuredFieldsProjectionTest extends TestCase
{
    public function test_text_mutates_the_field(): void
    {
        $a = HtmlProjectionFixture::structuredAdapter();
        $r = $a->project(HtmlProjectionFixture::textReq('headline', 'Mega Sale'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('Mega Sale', $r->actualAfterState['text']);

        $payload = $a->payload();
        $this->assertIsArray($payload); // NOT collapsed to raw HTML
        $this->assertSame('Mega Sale', $payload['fields']['headline']);
    }

    public function test_preserves_template_slug_fields_and_unknown_metadata(): void
    {
        $a = HtmlProjectionFixture::structuredAdapter();
        $a->project(HtmlProjectionFixture::textReq('headline', 'Mega Sale'));
        $payload = $a->payload();

        $this->assertSame('promo_a', $payload['template_slug']);          // slug preserved
        $this->assertSame('Today', $payload['fields']['sub']);            // unrelated field preserved
        $this->assertSame('#FFD60A', $payload['fields']['primary']);      // unrelated field preserved
        $this->assertSame(['author' => 'system', 'rev' => 3], $payload['meta']); // unknown metadata preserved
    }

    public function test_form_is_structured_and_version_token_is_structured(): void
    {
        $a = HtmlProjectionFixture::structuredAdapter();
        $this->assertSame(HtmlProjectionDocument::FORM_STRUCTURED, $a->document()->form());
        $this->assertStringStartsWith('s:', $a->currentVersion()->token);
    }

    public function test_style_is_unsupported_for_structured_documents(): void
    {
        $a = HtmlProjectionFixture::structuredAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red'));
        $this->assertSame(ProjectionStatus::UNSUPPORTED, $r->status);
        $this->assertSame(HtmlProjectionReason::STRUCTURED_STYLE_UNSUPPORTED, $r->errorReason);
    }

    public function test_missing_field_is_target_missing(): void
    {
        $a = HtmlProjectionFixture::structuredAdapter();
        $r = $a->project(HtmlProjectionFixture::textReq('nope', 'x'));
        $this->assertSame(ProjectionStatus::TARGET_MISSING, $r->status);
    }
}
