<?php

namespace App\Core\Engineer888\Reasoning\Providers;

use App\Connectors\DeepSeekConnector;
use App\Core\Engineer888\Reasoning\ProviderResponse;
use App\Core\Engineer888\Reasoning\ReasoningRequest;
use App\Core\Engineer888\Reasoning\ReasoningProvider;

/**
 * DeepSeek, through the connector the platform already uses.
 *
 * Reusing DeepSeekConnector rather than writing a second HTTP client is the
 * point: when the V4 migration changed model behaviour it changed in one place.
 * This class contributes what the connector does not have — the engineering
 * question, and the refusal to interpret its own answer.
 */
final class DeepSeekReasoningProvider implements ReasoningProvider
{
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'deepseek';
    }

    public function describe(): array
    {
        return [
            'model'         => (string) ($this->config['model'] ?? config('llm.deepseek.model', 'deepseek-chat')),
            'endpoint'      => (string) config('llm.deepseek.base_url', 'https://api.deepseek.com'),
            'deterministic' => false,
            'notes'         => 'V4 reasoning is always on and consumes the max_tokens budget, so the '
                             . 'ceiling here is deliberately generous; a truncated JSON body fails '
                             . 'validation rather than installing half a file.',
        ];
    }

    public function isAvailable(): bool
    {
        return (new DeepSeekConnector())->isConfigured();
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null : 'DEEPSEEK_API_KEY is not configured in this environment';
    }

    public function propose(ReasoningRequest $request): ProviderResponse
    {
        $model = $this->describe()['model'];
        $started = microtime(true);

        // The shared connector reads its timeout once, in its constructor, and
        // defaults to 30s — right for the short completions the rest of the
        // platform makes, and far too short for a full engineering proposal now
        // that V4 reasoning is always on. Raised for this process only; no other
        // engine's calls are affected, and nothing is written to configuration.
        config(['llm.deepseek.timeout' => (int) ($this->config['timeout'] ?? 240)]);

        $result = (new DeepSeekConnector())->chatJson([
            ['role' => 'system', 'content' =>
                'You contribute engineering reasoning to a governed workflow. Return only the JSON '
                . 'object requested. Every value you cannot justify from the supplied context must be '
                . 'the string UNKNOWN.'],
            ['role' => 'user', 'content' => $request->renderText()],
        ], [
            'model'       => $model,
            'temperature' => (float) ($this->config['temperature'] ?? 0.2),
            'max_tokens'  => (int) ($this->config['max_tokens'] ?? 8000),
        ]);

        $latency = (int) round((microtime(true) - $started) * 1000);

        if (! ($result['success'] ?? false)) {
            return ProviderResponse::failure($this->name(), $model,
                (string) ($result['error'] ?? 'request failed'), $latency);
        }

        $payload = $result['parsed'] ?? null;
        if (! is_array($payload)) {
            // Unparseable output is a provider failure, not something to repair.
            // Repairing it here would mean guessing what the model meant.
            return ProviderResponse::failure($this->name(), $model,
                (string) ($result['parse_error'] ?? 'response was not a JSON object'),
                $latency, substr((string) ($result['content'] ?? ''), 0, 500));
        }

        return ProviderResponse::success($this->name(), (string) ($result['model'] ?? $model),
            $payload, $latency, (array) ($result['usage'] ?? []),
            substr((string) ($result['content'] ?? ''), 0, 500));
    }
}
