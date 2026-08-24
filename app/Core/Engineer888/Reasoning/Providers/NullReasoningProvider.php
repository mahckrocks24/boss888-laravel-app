<?php

namespace App\Core\Engineer888\Reasoning\Providers;

use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\ProviderResponse;
use App\Core\Engineer888\Reasoning\ReasoningRequest;
use App\Core\Engineer888\Reasoning\ReasoningProvider;

/**
 * The provider that knows nothing, and says so.
 *
 * Every value UNKNOWN, no file changes, confidence unknown. Its output is
 * structurally valid, so it proves something a broken provider cannot: that a
 * well-formed proposal containing no knowledge still cannot reach the
 * repository. PLAN has nothing to plan and blocks.
 *
 * This is the safe default when no provider is configured. The alternative —
 * defaulting to a real model — would mean a fresh install starts making network
 * calls on somebody's credentials before anyone chose to.
 */
final class NullReasoningProvider implements ReasoningProvider
{
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'null';
    }

    public function describe(): array
    {
        return [
            'model'         => 'none',
            'endpoint'      => 'none',
            'deterministic' => true,
            'notes'         => 'declares everything UNKNOWN; proposes nothing',
        ];
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function propose(ReasoningRequest $request): ProviderResponse
    {
        $unknown = CandidateImplementation::UNKNOWN;

        return ProviderResponse::success($this->name(), 'none', [
            'problem_understanding'   => $unknown,
            'assumptions'             => [],
            'unknowns'                => [[
                'question'       => 'everything: no reasoning provider is configured',
                'why_it_matters' => 'without reasoning this task cannot be planned automatically',
                'how_to_resolve' => 'configure a provider, or supply a change set on the task',
            ]],
            'implementation_strategy' => $unknown,
            'files_affected'          => [],
            'migrations'              => ['required' => $unknown, 'detail' => $unknown],
            'risks'                   => [],
            'testing_strategy'        => $unknown,
            'rollback'                => $unknown,
            'file_changes'            => [],
            'confidence'              => 'unknown',
            'confidence_basis'        => 'no reasoning was performed',
        ], 0);
    }
}
