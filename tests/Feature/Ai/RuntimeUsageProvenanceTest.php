<?php

namespace Tests\Feature\Ai;

use App\Connectors\RuntimeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * D-01 / D-02 — AI execution provenance and exact usage.
 *
 * WHY THIS SUITE EXISTS
 * The 2026-07-29 forensic audit found 67 production rows recording OpenAI
 * gpt-4o-mini traffic as provider "deepseek", and output-token counts
 * understated by up to 801x because tokens were derived from strlen(text)/4
 * while the runtime was returning exact usage all along.
 *
 * There was NO existing test coverage of usage logging at all — which is why a
 * hardcoded provider survived in a hot path for months.
 *
 * The load-bearing assertion is `test_fallback_traffic_is_never_attributed_to_the_requested_provider`.
 */
class RuntimeUsageProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_provenance.enabled' => true]);

        // RuntimeClient reads env() DIRECTLY in its constructor — which is also
        // why config:cache must never be run on this app. Set both variables in
        // every place env() looks, before the container resolves the client.
        foreach ([
            'RUNTIME_URL'    => 'https://runtime.test',
            'RUNTIME_SECRET' => 'test-secret',
            'RUNTIME_TIMEOUT' => '30',
        ] as $k => $v) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    }

    /** A successful DeepSeek V4 Flash response, as the runtime really returns it. */
    private function flashResponse(array $overrides = []): array
    {
        return array_merge([
            'success'            => true,
            'output'             => '{"ok":true}',
            'model'              => 'deepseek-v4-flash',
            'duration_ms'        => 4941,
            'requested_provider' => 'deepseek',
            'actual_provider'    => 'deepseek',
            'requested_model'    => 'deepseek-v4-flash',
            'actual_model'       => 'deepseek-v4-flash',
            'fallback_used'      => false,
            'finish_reason'      => 'stop',
            'usage'              => [
                'input_tokens'        => 56,
                'completion_tokens'   => 801,
                'reasoning_tokens'    => 794,
                'final_output_tokens' => 7,
                'total_tokens'        => 857,
                'cached_tokens'       => 0,
            ],
        ], $overrides);
    }

    /** The runtime's real fallback shape, captured live on 2026-07-29. */
    private function fallbackResponse(): array
    {
        return [
            'success'            => true,
            'output'             => '{"ok":true}',
            'model'              => 'gpt-4o-mini',
            'duration_ms'        => 13417,
            'fallback'           => true,
            'fallback_used'      => true,
            'requested_provider' => 'deepseek',
            'actual_provider'    => 'openai',
            'requested_model'    => 'deepseek-v4-pro',
            'actual_model'       => 'gpt-4o-mini',
            'fallback_reason'    => 'DeepSeek returned empty final content despite HTTP 200.',
            'finish_reason'      => 'stop',
            'usage'              => [
                'input_tokens'      => 56,
                'completion_tokens' => 120,
                'reasoning_tokens'  => null,
                'total_tokens'      => 176,
                'cached_tokens'     => 0,
            ],
        ];
    }

    /** Signature is chatJson(string $system, string $userPrompt, array $context, int $maxTokens). */
    private function callChatJson(array $runtimeBody): void
    {
        Http::fake(['*' => Http::response($runtimeBody, 200)]);
        app(RuntimeClient::class)->chatJson('You are a test harness.', 'Return json.', []);
    }

    private function row(): object
    {
        $r = DB::table('api_usage_logs')->orderByDesc('id')->first();
        $this->assertNotNull($r, 'no api_usage_logs row was written');

        return $r;
    }

    // ── The defect that started this ────────────────────────────────────

    /**
     * THE load-bearing test. A fallback executed by OpenAI must never be
     * recorded as DeepSeek. This is the exact defect that produced 67 false
     * production rows and would let a total DeepSeek outage look healthy.
     */
    public function test_fallback_traffic_is_never_attributed_to_the_requested_provider(): void
    {
        $this->callChatJson($this->fallbackResponse());
        $r = $this->row();

        $this->assertSame('openai', $r->actual_provider);
        $this->assertSame('gpt-4o-mini', $r->actual_model);
        $this->assertSame('deepseek', $r->requested_provider);
        $this->assertSame('deepseek-v4-pro', $r->requested_model);

        // The legacy column is now defined as the ACTUAL execution.
        $this->assertSame('openai', $r->provider,
            'the legacy provider column must carry the actual executor, not the requested one');

        $this->assertEquals(1, $r->fallback_used);
        $this->assertStringContainsString('empty final content', (string) $r->fallback_reason);
    }

    public function test_a_normal_deepseek_call_is_attributed_to_deepseek(): void
    {
        $this->callChatJson($this->flashResponse());
        $r = $this->row();

        $this->assertSame('deepseek', $r->actual_provider);
        $this->assertSame('deepseek-v4-flash', $r->actual_model);
        $this->assertSame('deepseek', $r->provider);
        $this->assertEquals(0, $r->fallback_used);
        $this->assertNull($r->fallback_reason);
    }

    // ── Exact usage ─────────────────────────────────────────────────────

    /**
     * The audit measured completion_tokens=801 being persisted as 1. Reasoning
     * is billed inside completion_tokens on V4, so this is the figure that
     * matters and it must come from the runtime, not from strlen/4.
     */
    public function test_exact_usage_is_persisted_from_the_runtime_not_estimated(): void
    {
        $this->callChatJson($this->flashResponse());
        $r = $this->row();

        $this->assertSame('runtime', $r->usage_source);
        $this->assertEquals(56, $r->tokens_in);
        $this->assertEquals(801, $r->tokens_out, 'billable output must be completion_tokens, not the visible text length');
        $this->assertEquals(857, $r->total_tokens);
        $this->assertEquals(56, $r->prompt_tokens);
        $this->assertEquals(801, $r->completion_tokens);
        $this->assertEquals(794, $r->reasoning_tokens);

        // The old behaviour would have logged ~3 output tokens for '{"ok":true}'.
        $this->assertGreaterThan(100, $r->tokens_out);
    }

    /** reasoning_tokens is INSIDE completion_tokens and must not be double-counted. */
    public function test_reasoning_tokens_are_not_added_on_top_of_completion_tokens(): void
    {
        $this->callChatJson($this->flashResponse());
        $r = $this->row();

        $this->assertEquals(801, $r->tokens_out);
        $this->assertNotEquals(801 + 794, $r->tokens_out);
    }

    /** No usage object: still record, but say plainly that it was estimated. */
    public function test_a_missing_usage_object_is_labelled_estimated(): void
    {
        $body = $this->flashResponse();
        unset($body['usage']);
        $this->callChatJson($body);

        $this->assertSame('estimated', $this->row()->usage_source);
    }

    // ── Pricing by actual model ─────────────────────────────────────────

    public function test_flash_is_priced_at_the_official_flash_rate(): void
    {
        $this->callChatJson($this->flashResponse());
        $r = $this->row();

        // 56 in @ $0.14/1M + 801 out @ $0.28/1M
        $expected = (56 * 0.14 + 801 * 0.28) / 1000000;
        $this->assertEqualsWithDelta($expected, (float) $r->cost_usd, 0.0000005);
        $this->assertSame('deepseek-v4-flash', $r->pricing_source);
    }

    /** Pro is 3.1x Flash. The old flat rate understated every Pro call. */
    public function test_pro_is_priced_higher_than_flash_for_identical_usage(): void
    {
        $this->callChatJson($this->flashResponse([
            'model' => 'deepseek-v4-pro', 'actual_model' => 'deepseek-v4-pro', 'requested_model' => 'deepseek-v4-pro',
        ]));
        $r = $this->row();

        $expected = (56 * 0.435 + 801 * 0.87) / 1000000;
        $this->assertEqualsWithDelta($expected, (float) $r->cost_usd, 0.0000005);
        $this->assertSame('deepseek-v4-pro', $r->pricing_source);

        $flashCost = (56 * 0.14 + 801 * 0.28) / 1000000;
        $this->assertGreaterThan($flashCost, (float) $r->cost_usd);
    }

    /** A fallback must be priced as OpenAI, not at DeepSeek rates. */
    public function test_fallback_is_priced_against_the_actual_provider(): void
    {
        $this->callChatJson($this->fallbackResponse());
        $r = $this->row();

        $expected = (56 * 0.15 + 120 * 0.60) / 1000000;
        $this->assertEqualsWithDelta($expected, (float) $r->cost_usd, 0.0000005);
        $this->assertSame('gpt-4o-mini', $r->pricing_source);
    }

    /** An unknown model must be visible, never silently free. */
    public function test_an_unknown_model_is_labelled_rather_than_priced_at_zero(): void
    {
        $this->callChatJson($this->flashResponse([
            'model' => 'deepseek-v5-unknown', 'actual_model' => 'deepseek-v5-unknown',
        ]));
        $this->assertStringStartsWith('legacy:', (string) $this->row()->pricing_source);
    }

    /** Cached input is a subset of input, never an addition. */
    public function test_cached_tokens_are_billed_at_the_cache_rate_and_not_double_counted(): void
    {
        $body = $this->flashResponse();
        $body['usage']['cached_tokens'] = 50;   // of 56 input
        $this->callChatJson($body);
        $r = $this->row();

        $expected = ((56 - 50) * 0.14 + 50 * 0.0028 + 801 * 0.28) / 1000000;
        $this->assertEqualsWithDelta($expected, (float) $r->cost_usd, 0.0000005);
        $this->assertEquals(50, $r->cached_tokens);
    }

    // ── The flag ────────────────────────────────────────────────────────

    /**
     * With the flag OFF the old path must run unchanged, so the change can land
     * on production without altering behaviour until it is reviewed.
     */
    public function test_with_the_flag_off_the_legacy_behaviour_is_preserved_exactly(): void
    {
        config(['ai_provenance.enabled' => false]);
        $this->callChatJson($this->fallbackResponse());
        $r = $this->row();

        // The legacy defect, deliberately intact behind the flag.
        $this->assertSame('deepseek', $r->provider);
        $this->assertNull($r->actual_provider);
        $this->assertNull($r->usage_source);
    }

    /** Logging must never break the caller. */
    public function test_a_logging_failure_does_not_break_the_request(): void
    {
        config(['ai_provenance.pricing' => 'not-an-array']);
        Http::fake(['*' => Http::response($this->flashResponse(), 200)]);

        $out = app(RuntimeClient::class)->chatJson('You are a test harness.', 'Return json.', []);
        $this->assertIsArray($out);
    }
}
