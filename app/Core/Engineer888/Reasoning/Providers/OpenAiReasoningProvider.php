<?php

namespace App\Core\Engineer888\Reasoning\Providers;

use App\Core\Engineer888\Reasoning\ProviderResponse;
use App\Core\Engineer888\Reasoning\ReasoningRequest;
use App\Core\Engineer888\Reasoning\ReasoningProvider;
use Illuminate\Support\Facades\Http;

/**
 * A second real provider, so "replaceable" is demonstrated rather than asserted.
 *
 * Written from the same interface as DeepSeek and nothing else. It shares no
 * base class with it and knows nothing about it; if the two had to cooperate,
 * the abstraction would only be hiding a shared assumption.
 */
final class OpenAiReasoningProvider implements ReasoningProvider
{
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'openai';
    }

    public function describe(): array
    {
        return [
            'model'         => (string) ($this->config['model'] ?? config('llm.openai.model', 'gpt-4o')),
            'endpoint'      => (string) config('llm.openai.base_url', 'https://api.openai.com'),
            'deterministic' => false,
            'notes'         => 'chat completions with a json_object response format',
        ];
    }

    private function key(): string
    {
        return (string) ($this->config['api_key'] ?? config('llm.openai.api_key', ''));
    }

    public function isAvailable(): bool
    {
        return trim($this->key()) !== '';
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'OPENAI_API_KEY is not configured in this environment';
    }

    public function propose(ReasoningRequest $request): ProviderResponse
    {
        $model = $this->describe()['model'];
        $started = microtime(true);

        try {
            $response = Http::timeout((int) ($this->config['timeout'] ?? 180))
                ->withHeaders(['Authorization' => 'Bearer ' . $this->key()])
                ->post(rtrim($this->describe()['endpoint'], '/') . '/v1/chat/completions', [
                    'model'           => $model,
                    'temperature'     => (float) ($this->config['temperature'] ?? 0.2),
                    'max_tokens'      => (int) ($this->config['max_tokens'] ?? 8000),
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' =>
                            'You contribute engineering reasoning to a governed workflow. Return only the '
                            . 'JSON object requested. Every value you cannot justify from the supplied '
                            . 'context must be the string UNKNOWN.'],
                        ['role' => 'user', 'content' => $request->renderText()],
                    ],
                ]);
        } catch (\Throwable $e) {
            return ProviderResponse::failure($this->name(), $model, get_class($e) . ': ' . $e->getMessage(),
                (int) round((microtime(true) - $started) * 1000));
        }

        $latency = (int) round((microtime(true) - $started) * 1000);

        if ($response->failed()) {
            // The status is reported; the body is not, because provider error
            // bodies have been observed to echo request content.
            return ProviderResponse::failure($this->name(), $model,
                'HTTP ' . $response->status(), $latency);
        }

        $data = $response->json();
        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        $payload = json_decode($content, true);

        if (! is_array($payload)) {
            return ProviderResponse::failure($this->name(), $model,
                'response was not a JSON object', $latency, substr($content, 0, 500));
        }

        return ProviderResponse::success($this->name(), (string) ($data['model'] ?? $model),
            $payload, $latency, (array) ($data['usage'] ?? []), substr($content, 0, 500));
    }
}
