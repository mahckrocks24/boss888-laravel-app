<?php

namespace Tests\Unit;

use App\Http\Middleware\VendorSafeResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Owner rule 2026-09-14: no vendor ever reaches a customer-facing error; legitimate mentions are untouched. */
class VendorSafeResponseTest extends TestCase
{
    private function run_(string $path, array $payload): array
    {
        $req = Request::create('/' . $path, 'POST');
        $res = (new VendorSafeResponse())->handle($req, fn () => new JsonResponse($payload));
        return json_decode((string) $res->getContent(), true);
    }

    public function test_raw_provider_errors_are_replaced_in_error_like_fields(): void
    {
        $out = $this->run_('api/builder/pages/1/arthur-edit', [
            'success' => false,
            'error'   => 'OpenAI 429: {"error":{"message":"You have no credits remaining."}}',
            'reply'   => 'I could not do that — the image service declined (OpenAI 429). Nothing was charged.',
            'message' => 'MiniMax API error: 502',
            'errors'  => ['DeepSeek API error: 500', 'plain validation error'],
            'data'    => ['detail' => 'Runway not configured'],
        ]);
        $this->assertSame(VendorSafeResponse::GENERIC, $out['error']);
        $this->assertSame(VendorSafeResponse::GENERIC, $out['reply']);
        $this->assertSame(VendorSafeResponse::GENERIC, $out['message']);
        $this->assertSame(VendorSafeResponse::GENERIC, $out['errors'][0]);
        $this->assertSame('plain validation error', $out['errors'][1]);
        $this->assertSame(VendorSafeResponse::GENERIC, $out['data']['detail']);
        $this->assertStringNotContainsStringIgnoringCase('openai', json_encode($out));
        $this->assertStringNotContainsStringIgnoringCase('minimax', json_encode($out));
    }

    public function test_legitimate_mentions_and_content_fields_pass_through(): void
    {
        $out = $this->run_('api/write/articles/9', [
            'content' => 'OpenAI announced a new model; here is what it means for your café.',
            'reply'   => 'OpenAI is an AI research company; DeepSeek is another model provider.',
            'error'   => null,
        ]);
        $this->assertStringContainsString('OpenAI announced', $out['content']);
        $this->assertStringContainsString('OpenAI is an AI research company', $out['reply']);
    }

    public function test_admin_console_and_non_api_paths_are_exempt(): void
    {
        $payload = ['error' => 'OpenAI 429: no credits remaining'];
        $this->assertSame($payload['error'], $this->run_('api/admin/providers/status', $payload)['error']);
        $this->assertSame($payload['error'], $this->run_('storage/sites/1/index.html', $payload)['error']);
    }

    public function test_sanitize_helper_matches_the_guard(): void
    {
        $this->assertSame(VendorSafeResponse::GENERIC, VendorSafeResponse::sanitize('OpenAI 429: You have no credits remaining'));
        $this->assertSame('Photo replaced', VendorSafeResponse::sanitize('Photo replaced'));
    }
}
