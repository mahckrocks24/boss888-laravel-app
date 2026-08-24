<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * What a provider said, before anybody decided whether it was any good.
 *
 * Deliberately dumb. `payload` is the decoded structure exactly as returned;
 * it is not normalised, not defaulted and not repaired here, because a repair
 * performed silently would hide the fact that the provider did not answer the
 * question asked. CandidateValidator judges it afterwards.
 */
final class ProviderResponse
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $payload,
        public readonly ?string $error,
        public readonly int $latencyMs,
        public readonly array $usage,
        public readonly ?string $rawExcerpt,
    ) {}

    public static function success(
        string $provider,
        string $model,
        array $payload,
        int $latencyMs,
        array $usage = [],
        ?string $rawExcerpt = null,
    ): self {
        return new self(true, $provider, $model, $payload, null, $latencyMs, $usage, $rawExcerpt);
    }

    /**
     * The provider could not answer.
     *
     * A failed call is recorded, never substituted. There is no fallback that
     * quietly produces a plausible plan when the model was unreachable.
     */
    public static function failure(
        string $provider,
        string $model,
        string $error,
        int $latencyMs = 0,
        ?string $rawExcerpt = null,
    ): self {
        return new self(false, $provider, $model, [], $error, $latencyMs, [], $rawExcerpt);
    }

    public function toArray(): array
    {
        return [
            'ok'          => $this->ok,
            'provider'    => $this->provider,
            'model'       => $this->model,
            'error'       => $this->error,
            'latency_ms'  => $this->latencyMs,
            'usage'       => $this->usage,
            'raw_excerpt' => $this->rawExcerpt,
        ];
    }
}
