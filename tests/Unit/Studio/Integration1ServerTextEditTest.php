<?php

namespace Tests\Unit\Studio;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionResult;
use PHPUnit\Framework\TestCase;

/**
 * STUDIO888 · Integration Milestone 1 — server-verified single text edit.
 *
 * Pure unit test (NO database, NO app boot): it exercises the exact committed
 * engine calls the /studio/chat closure makes, for every required case. The DB
 * compare-and-swap, flag-OFF legacy path, and browser no-op are proven by code
 * structure + live browser QA (see the milestone report).
 */
final class Integration1ServerTextEditTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head><style>:root{--primary:#FFD60A;--accent:#FFD60A}</style></head><body>'
        . '<span data-field="headline">Big Sale</span>'
        . '<span data-field="stat_2_val">98%</span>'
        . '<span data-field="dup">A</span><span data-field="dup">B</span>'
        . '</body></html>';

    private static function structured(): array
    {
        return ['template_slug' => 'restaurant', 'fields' => ['headline' => 'Old Title', 'sub' => 'keep-me']];
    }

    /** Mirrors exactly how the closure builds the request from a trusted update_field. */
    private static function req(HtmlProjectionAdapter $a, string $target, string $value, array $extra = []): ProjectionRequest
    {
        $before = $a->document()->get($target, 'text');
        return ProjectionRequest::fromArray(array_merge([
            'schema_version'            => 1,
            'operation_id'              => 'studio-chat-text',
            'document_id'               => 'd1',
            'target_id'                 => $target,
            'changed_fields'            => ['text'],
            'desired_after_state'       => ['text' => $value],
            'expected_document_version' => $a->currentVersion()->token,
            'before_snapshot_hash'      => ProjectionRequest::hashState(['text' => $before]),
            'correlation_id'            => 'c',
            'idempotency_key'           => null,
            'batch_id'                  => null,
            'projection_meta'           => [],
        ], $extra));
    }

    /** The closure's verification gate, replicated. */
    private static function verified(ProjectionResult $r): bool
    {
        return $r->status === ProjectionStatus::APPLIED
            && (($r->verification['verified'] ?? false) === true)
            && in_array('text', $r->appliedFields, true)
            && array_key_exists('text', $r->actualAfterState);
    }

    /** The closure's eligibility predicate, replicated. */
    private static function eligible(array $actions): bool
    {
        return count($actions) === 1
            && is_array($actions[0] ?? null)
            && (($actions[0]['type'] ?? null) === 'update_field')
            && is_string($actions[0]['name'] ?? null) && ($actions[0]['name'] !== '')
            && array_key_exists('value', $actions[0])
            && (is_string($actions[0]['value']) || is_numeric($actions[0]['value']));
    }

    public function test_raw_text_edit_applies_and_verifies(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $this->assertStringStartsWith('h:', $a->currentVersion()->token);
        $r = $a->project(self::req($a, 'headline', 'Huge Sale'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertTrue(self::verified($r));
        $this->assertSame('Huge Sale', $r->actualAfterState['text']);           // truthful value source
        $html = (string) $a->payload();
        $this->assertStringContainsString('Huge Sale', $html);
        $this->assertStringNotContainsString('Big Sale', $html);
        $this->assertStringContainsString('--primary:#FFD60A', $html);           // palette untouched
    }

    public function test_reply_is_built_from_actual_after_state_not_llm(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $before = $a->document()->get('stat_2_val', 'text');
        $r = $a->project(self::req($a, 'stat_2_val', '600+'));
        $this->assertTrue(self::verified($r));
        $reply = 'Changed "' . $before . '" to "' . $r->actualAfterState['text'] . '" and verified the saved result.';
        $this->assertSame('Changed "98%" to "600+" and verified the saved result.', $reply);
    }

    public function test_missing_target_does_not_verify(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req($a, 'no_such_field', 'x'));
        $this->assertSame(ProjectionStatus::TARGET_MISSING, $r->status);
        $this->assertFalse(self::verified($r));
    }

    public function test_ambiguous_target_does_not_verify(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req($a, 'dup', 'x'));
        $this->assertNotSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertFalse(self::verified($r));
    }

    public function test_stale_version_does_not_verify(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req($a, 'headline', 'x', ['expected_document_version' => 'h:deadbeefdeadbeef']));
        $this->assertSame(ProjectionStatus::STALE_VERSION, $r->status);
        $this->assertFalse(self::verified($r));
    }

    public function test_snapshot_mismatch_does_not_verify(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req($a, 'headline', 'x', ['before_snapshot_hash' => 'deadbeefdeadbeef']));
        $this->assertSame(ProjectionResult::R_SNAPSHOT_MISMATCH, $r->errorReason);
        $this->assertFalse(self::verified($r));
    }

    public function test_noop_same_text_does_not_report_success(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req($a, 'headline', 'Big Sale'));   // identical to current
        $this->assertFalse(self::verified($r));
        $this->assertSame(ProjectionResult::R_NO_CHANGE, $r->errorReason);
    }

    public function test_structured_template_field_stays_structured(): void
    {
        $a = HtmlProjectionAdapter::forStructured(self::structured());
        $this->assertStringStartsWith('s:', $a->currentVersion()->token);
        $r = $a->project(self::req($a, 'headline', 'New Title'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertTrue(self::verified($r));
        $payload = $a->payload();
        $this->assertIsArray($payload);
        $this->assertSame('restaurant', $payload['template_slug']);            // slug preserved
        $this->assertSame('New Title', $payload['fields']['headline']);        // field changed
        $this->assertSame('keep-me', $payload['fields']['sub']);              // unrelated field preserved
    }

    public function test_eligibility_predicate(): void
    {
        $this->assertTrue(self::eligible([['type' => 'update_field', 'name' => 'headline', 'value' => 'x']]));
        $this->assertTrue(self::eligible([['type' => 'update_field', 'name' => 'stat', 'value' => 600]]));   // numeric scalar
        $this->assertFalse(self::eligible([]));                                                                // zero
        $this->assertFalse(self::eligible([                                                                    // multiple
            ['type' => 'update_field', 'name' => 'a', 'value' => '1'],
            ['type' => 'update_field', 'name' => 'b', 'value' => '2'],
        ]));
        $this->assertFalse(self::eligible([['type' => 'apply_palette', 'vars' => ['--primary' => '#000']]]));  // palette
        $this->assertFalse(self::eligible([['type' => 'update_image', 'name' => 'hero', 'url' => 'x']]));       // image
        $this->assertFalse(self::eligible([['type' => 'update_field', 'name' => '', 'value' => 'x']]));         // empty name
        $this->assertFalse(self::eligible([['type' => 'update_field', 'name' => 'a']]));                        // no value
        $this->assertFalse(self::eligible([['type' => 'update_field', 'name' => 'a', 'value' => ['x']]]));      // non-scalar
    }

    public function test_idempotent_replay_does_not_double_apply(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $first = $a->project(self::req($a, 'headline', 'Huge Sale', ['idempotency_key' => 'k1']));
        $this->assertSame(ProjectionStatus::APPLIED, $first->status);
        $replay = $a->project(self::req($a, 'headline', 'Huge Sale', ['idempotency_key' => 'k1']));
        $this->assertSame('completed', $replay->replay);
    }
}
