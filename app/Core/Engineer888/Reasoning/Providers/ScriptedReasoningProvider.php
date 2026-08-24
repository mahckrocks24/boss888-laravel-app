<?php

namespace App\Core\Engineer888\Reasoning\Providers;

use App\Core\Engineer888\Reasoning\ProviderResponse;
use App\Core\Engineer888\Reasoning\ReasoningRequest;
use App\Core\Engineer888\Reasoning\ReasoningProvider;

/**
 * A deterministic provider that replays an authored response.
 *
 * IT IS NOT A MODEL, and describe() says so, because a fixture that looks like
 * reasoning in the audit trail would let a demonstration pass for a capability.
 *
 * It exists for two honest reasons. The test suite must prove the governance
 * path without a network call or a bill — phpunit.e888.xml blanks every API key
 * deliberately. And the provider-replacement experiment needs one side that
 * returns the same bytes every time, or "the workflow was unchanged" cannot be
 * distinguished from "the second model happened to agree".
 */
final class ScriptedReasoningProvider implements ReasoningProvider
{
    /** Fixtures seen by this instance, for assertions about what was asked. */
    private ?ReasoningRequest $lastRequest = null;

    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'scripted';
    }

    public function describe(): array
    {
        return [
            'model'         => (string) ($this->config['label'] ?? 'scripted-fixture'),
            'endpoint'      => 'none — no network call is made',
            'deterministic' => true,
            'notes'         => 'replays an authored response; this is a fixture, not a reasoning model',
        ];
    }

    public function isAvailable(): bool
    {
        return $this->fixture() !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable() ? null
            : 'no scripted response configured (set engineer888_reasoning.scripted.response or E888_REASONING_FIXTURE)';
    }

    public function propose(ReasoningRequest $request): ProviderResponse
    {
        $this->lastRequest = $request;
        $payload = $this->fixture();

        if ($payload === null) {
            return ProviderResponse::failure($this->name(), $this->describe()['model'],
                (string) $this->unavailableReason());
        }

        return ProviderResponse::success($this->name(), $this->describe()['model'], $payload, 0,
            ['prompt_bytes' => $request->sizeBytes()]);
    }

    /** What the provider was actually told — used by tests about context selection. */
    public function lastRequest(): ?ReasoningRequest
    {
        return $this->lastRequest;
    }

    /** @return array<string,mixed>|null */
    private function fixture(): ?array
    {
        $inline = $this->config['response'] ?? null;
        if (is_array($inline)) { return $inline; }

        $path = $this->config['fixture'] ?? env('E888_REASONING_FIXTURE');
        if (! is_string($path) || $path === '' || ! is_file($path)) { return null; }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
