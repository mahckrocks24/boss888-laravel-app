<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * What happened when Engineer888 asked for reasoning.
 *
 * Five outcomes, kept distinct on purpose. DISABLED and UNAVAILABLE are not
 * failures — no key configured is a configuration fact, and the workflow should
 * say so and stop rather than pretend. PROVIDER_ERROR is the model failing to
 * answer. REJECTED is the model answering badly, which is the one case where
 * Engineer888 has an opinion, and it is recorded with the violations that
 * produced it so the next attempt is informed rather than repeated.
 */
final class ReasoningOutcome
{
    public const VALIDATED = 'VALIDATED';
    public const REJECTED = 'REJECTED';
    public const PROVIDER_ERROR = 'PROVIDER_ERROR';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const DISABLED = 'DISABLED';

    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?CandidateImplementation $candidate = null,
        public readonly array $violations = [],
        public readonly ?ReasoningRequest $request = null,
        public readonly ?ProviderResponse $response = null,
        public readonly ?int $candidateId = null,
        public readonly ?string $candidateUuid = null,
    ) {}

    public static function validated(
        CandidateImplementation $candidate,
        ReasoningRequest $request,
        ProviderResponse $response,
        ?int $candidateId = null,
        ?string $candidateUuid = null,
    ): self {
        return new self(self::VALIDATED,
            $candidate->provider . '/' . $candidate->model . ' proposed '
            . count($candidate->fileChanges()) . ' file change(s), confidence ' . $candidate->confidence(),
            $candidate, [], $request, $response, $candidateId, $candidateUuid);
    }

    public static function rejected(
        array $violations,
        ReasoningRequest $request,
        ProviderResponse $response,
        ?int $candidateId = null,
        ?string $candidateUuid = null,
    ): self {
        return new self(self::REJECTED,
            count($violations) . ' contract violation(s): ' . implode('; ', array_column($violations, 'detail')),
            null, $violations, $request, $response, $candidateId, $candidateUuid);
    }

    public static function providerError(string $error, ReasoningRequest $request, ProviderResponse $response): self
    {
        return new self(self::PROVIDER_ERROR, $error, null, [], $request, $response);
    }

    public static function unavailable(string $reason, ?ReasoningRequest $request = null): self
    {
        return new self(self::UNAVAILABLE, $reason, null, [], $request);
    }

    public static function disabled(string $reason): self
    {
        return new self(self::DISABLED, $reason);
    }

    public function isValidated(): bool
    {
        return $this->status === self::VALIDATED;
    }

    public function toArray(): array
    {
        return [
            'status'         => $this->status,
            'reason'         => $this->reason,
            'candidate_id'   => $this->candidateId,
            'candidate_uuid' => $this->candidateUuid,
            'violations'     => $this->violations,
            'provider'       => $this->response?->provider,
            'model'          => $this->response?->model,
            'context'        => $this->request?->contextManifest(),
            'excluded'       => $this->request === null ? [] : count($this->request->excluded),
            'candidate'      => $this->candidate?->toArray(),
        ];
    }
}
