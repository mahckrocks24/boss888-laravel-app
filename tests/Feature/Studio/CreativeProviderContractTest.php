<?php

namespace Tests\Feature\Studio;

use App\Connectors\CreativeConnector;
use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — CREATIVE PROVIDER CONTRACT CHARACTERIZATION (test-only).
 *
 * Pins the ACTUAL outbound provider contracts used by the current creative
 * execution paths, traced from production code (NOT from the stale 2026-05
 * CreativeExecutionTest, which fakes the dead creative888 v1/generate path).
 *
 * Confirmed boundaries:
 *   - Image  : RuntimeClient::imageGenerate → POST {RUNTIME_URL}/internal/image/generate
 *              header X-LevelUp-Secret; body {prompt, style?, size?};
 *              response {success, b64_json|url, revised_prompt, size, quality}.
 *   - Image wrap: CreativeConnector::generateImage → runtime + optional HEAD size probe,
 *              returns {success, url, width, height, file_size, storage_path, metadata{model,provider,revised_prompt}}.
 *   - Video  : CreativeConnector::minimaxGenerateVideo (via generateVideoViaProvider)
 *              → POST https://api.minimax.chat/v1/text/video_generation
 *              header Authorization: Bearer; body {model:'T2V-01', prompt}; response {task_id}.
 *   - Video poll: GET https://api.minimax.chat/v1/query/video_generation?task_id=...
 *
 * No real network, no provider credentials, no production writes.
 */
class CreativeProviderContractTest extends TestCase
{
    private function runtimeWith(string $url = 'https://runtime.test', string $secret = 'test-secret'): RuntimeClient
    {
        // RuntimeClient reads env() in its constructor; phpunit forces RUNTIME_URL=''.
        // Build a configured instance explicitly to characterize the wire contract.
        putenv("RUNTIME_URL={$url}");
        putenv("RUNTIME_SECRET={$secret}");
        $_ENV['RUNTIME_URL'] = $url;
        $_ENV['RUNTIME_SECRET'] = $secret;

        return new RuntimeClient();
    }

    protected function tearDown(): void
    {
        putenv('RUNTIME_URL=');
        putenv('RUNTIME_SECRET=');
        $_ENV['RUNTIME_URL'] = '';
        $_ENV['RUNTIME_SECRET'] = '';
        parent::tearDown();
    }

    // ── IMAGE: runtime wire contract ────────────────────────────────────

    /** @test */
    public function runtime_image_generate_posts_the_expected_request_and_parses_b64(): void
    {
        Storage::fake('public');

        $onePixelPng = base64_encode(hex2bin(
            '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4'
            . '890000000d49444154789c6360000002000100' . '05fe02fea7c1b2c40000000049454e44ae426082'
        ));

        Http::fake([
            '*/internal/image/generate' => Http::response([
                'success'        => true,
                'b64_json'       => $onePixelPng,
                'revised_prompt' => 'A serene sunset over Dubai Marina, editorial',
                'size'           => '1024x1024',
                'quality'        => 'low',
                'model'          => 'gpt-image-1',
                'provider'       => 'openai',
            ], 200),
            '*' => Http::response('', 200),
        ]);

        $runtime = $this->runtimeWith();
        $result  = $runtime->imageGenerate('A sunset over Dubai Marina', [
            'style' => 'natural', 'size' => '1024x1024',
        ]);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['url']);
        $this->assertSame('1024x1024', $result['size']);
        $this->assertSame('A serene sunset over Dubai Marina, editorial', $result['revised_prompt']);
        // b64 path persists to the public disk under ai-images/{ws}/
        $this->assertNotEmpty($result['storage_path']);
        $this->assertStringContainsString('ai-images/', $result['storage_path']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/internal/image/generate')
            && $request->method() === 'POST'
            && $request['prompt'] === 'A sunset over Dubai Marina'
            && $request['size'] === '1024x1024');

        // The shared-secret auth header is present on the runtime call (value is env-derived).
        Http::assertSent(fn ($request) => str_contains($request->url(), '/internal/image/generate')
            && $request->hasHeader('X-LevelUp-Secret'));
    }

    /** @test */
    public function runtime_image_generate_returns_failure_on_non_2xx(): void
    {
        Http::fake([
            '*/internal/image/generate' => Http::response(['success' => false, 'error' => 'provider_overloaded'], 500),
            '*' => Http::response('', 200),
        ]);

        $result = $this->runtimeWith()->imageGenerate('anything', []);

        $this->assertFalse($result['success']);
        $this->assertSame('provider_overloaded', $result['error']);
    }

    // ── IMAGE: connector wrap contract ──────────────────────────────────

    /** @test */
    public function creative_connector_wraps_runtime_result_with_dimensions_and_metadata(): void
    {
        Http::fake([
            '*/internal/image/generate' => Http::response([
                'success'        => true,
                'url'            => 'https://cdn.test/generated/img.png', // legacy url path — no storage write
                'revised_prompt' => 'revised!',
                'size'           => '1792x1024',
                'quality'        => 'standard',
                'model'          => 'dall-e-3',
                'provider'       => 'openai',
            ], 200),
            // the connector's optional HEAD size probe
            'cdn.test/*' => Http::response('', 200, ['Content-Length' => '204800']),
            '*' => Http::response('', 200),
        ]);

        // Inject the configured runtime so the connector talks to the fake wire.
        $connector = new CreativeConnector($this->runtimeWith());
        $result = $connector->generateImage('A wide banner', ['aspect_ratio' => '16:9']);

        $this->assertTrue($result['success']);
        $this->assertSame('https://cdn.test/generated/img.png', $result['url']);
        $this->assertSame(1792, $result['width']);
        $this->assertSame(1024, $result['height']);
        $this->assertSame(204800, $result['file_size']);
        $this->assertSame('dall-e-3', $result['metadata']['model']);
        $this->assertSame('openai', $result['metadata']['provider']);
        $this->assertSame('revised!', $result['metadata']['revised_prompt']);
    }

    /** @test */
    public function creative_connector_rejects_empty_prompt_without_calling_provider(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $result = (new CreativeConnector($this->runtimeWith()))->generateImage('', []);

        $this->assertFalse($result['success']);
        $this->assertSame('prompt is required', $result['error']);
        Http::assertNothingSent();
    }

    // ── VIDEO: MiniMax wire contract ────────────────────────────────────

    /** @test */
    public function minimax_video_generate_absent_key_returns_failure_without_http(): void
    {
        // MINIMAX_API_KEY is unset in the test env.
        Http::fake(['*' => Http::response('', 200)]);

        $result = (new CreativeConnector($this->runtimeWith()))
            ->generateVideoViaProvider('a dancing robot', ['provider' => 'minimax']);

        $this->assertFalse($result['success']);
        $this->assertSame('MiniMax API key not configured', $result['error']);
        Http::assertNothingSent();
    }

    /** @test */
    public function minimax_video_generate_posts_t2v_contract_when_key_present(): void
    {
        // MiniMax reads config('services.minimax.api_key', env(...)) at call time;
        // config() is boot-cached so override it at runtime rather than env().
        config(['services.minimax.api_key' => 'mmx-test-key']);

        Http::fake([
            'api.minimax.chat/v1/text/video_generation' => Http::response(['task_id' => 'task-abc-123'], 200),
            '*' => Http::response('', 200),
        ]);

        $result = (new CreativeConnector($this->runtimeWith()))
            ->generateVideoViaProvider('a dancing robot', ['provider' => 'minimax']);

        $this->assertTrue($result['success']);
        $this->assertSame('task-abc-123', $result['job_id']);
        $this->assertSame('minimax', $result['provider']);
        $this->assertSame('T2V-01', $result['model']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.minimax.chat/v1/text/video_generation')
                && $request['model'] === 'T2V-01'
                && $request['prompt'] === 'a dancing robot'
                && $request->hasHeader('Authorization')
                && $request->header('Authorization')[0] === 'Bearer mmx-test-key';
        });
    }

    /** @test */
    public function video_mock_provider_and_poll_are_deterministic_fallbacks(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $connector = new CreativeConnector($this->runtimeWith());

        $gen = $connector->generateVideoViaProvider('scene', ['provider' => 'mock']);
        $this->assertTrue($gen['success']);
        $this->assertSame('mock', $gen['provider']);

        $poll = $connector->pollVideoJob($gen['job_id'], 'mock');
        $this->assertSame('completed', $poll['status']);
        $this->assertNotEmpty($poll['url']);
    }
}
