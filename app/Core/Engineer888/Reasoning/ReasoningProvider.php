<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * A source of engineering reasoning.
 *
 * DeepSeek is not Engineer888. Neither is Claude, GPT nor Gemini. A provider
 * contributes reasoning and nothing else: it receives structured engineering
 * context and returns a structured proposal.
 *
 * The interface is deliberately narrow. Everything a provider would need in
 * order to acquire authority — writing a file, marking its own output verified,
 * approving a task, recording its own promotion — is absent by construction
 * rather than forbidden by policy. A provider cannot bypass governance because
 * there is no method through which it could.
 *
 * @see WriteBoundary for the mechanical proof that no class in this namespace
 *      can reach the filesystem or a shell.
 */
interface ReasoningProvider
{
    /** The configuration key this provider is registered under. */
    public function name(): string;

    /**
     * What this provider is, recorded with every candidate so an audit can ask
     * "which reasoning produced this?" months later.
     *
     * @return array{model:string,endpoint:string,deterministic:bool,notes:string}
     */
    public function describe(): array;

    /**
     * Can it be called right now?
     *
     * A missing key is a normal, reportable state — not an error and not a
     * reason to invent an answer.
     */
    public function isAvailable(): bool;

    /** Why not, when isAvailable() is false. Never contains a secret. */
    public function unavailableReason(): ?string;

    /**
     * Turn engineering context into a proposal.
     *
     * Implementations return whatever the provider said, parsed but NOT judged.
     * Validation is Engineer888's job: a provider that could declare its own
     * output valid would be self-certifying, which this sprint exists to prevent.
     */
    public function propose(ReasoningRequest $request): ProviderResponse;
}
