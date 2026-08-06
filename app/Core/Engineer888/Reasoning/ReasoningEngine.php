<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Approval\ApprovalLedger;

/**
 * The Engineering Reasoning Engine.
 *
 * Build context → ask a provider → judge the answer → store it. That is all.
 *
 * What it deliberately does NOT do is the design of Sprint 7: it does not write
 * a file, run a command, approve anything, mark anything verified, or decide
 * that a proposal is good enough to skip a gate.
 *
 * Sprint 8 adds one word to that list: it does not RE-REASON behind an
 * approval. `approvedCandidate()` reads the approval ledger, so what IMPLEMENT
 * receives is the candidate a human bound themselves to — not the newest one.
 *
 * The model proposes. Engineer888 verifies.
 */
final class ReasoningEngine
{
    /**
     * One revision per task, and no more.
     *
     * A human rejecting a proposal with instructions is engineering. A loop that
     * keeps asking until something passes the validator is not — it optimises
     * for getting past the gate rather than for being right, and the gate is
     * only a proxy for correctness.
     */
    public const MAX_REVISIONS = 1;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly CandidateValidator $validator,
        private readonly CandidateStore $store,
    ) {}

    public static function make(): self
    {
        $config = (array) config('engineer888_reasoning', []);
        $limits = (array) ($config['candidate'] ?? []);

        return new self(
            ProviderRegistry::fromConfig(),
            new CandidateValidator(
                (int) ($limits['max_files'] ?? 12),
                (int) ($limits['max_file_bytes'] ?? 60000),
                base_path(),
            ),
            new CandidateStore(),
        );
    }

    /**
     * Produce a candidate implementation for a task.
     *
     * @param object $project engineering_projects row
     * @param object $task    engineering_tasks row
     * @param array  $revision ['of' => int, 'instruction' => string] for a bounded revision
     */
    public function propose(
        object $project,
        object $task,
        ?string $providerName = null,
        array $revision = [],
    ): ReasoningOutcome {
        if (! $this->registry->enabled()) {
            return ReasoningOutcome::disabled(
                'reasoning is switched off; supply a change set on the task instead'
            );
        }

        try {
            $provider = $this->registry->make($providerName);
        } catch (\Throwable $e) {
            return ReasoningOutcome::unavailable($e->getMessage());
        }

        $request = $this->buildRequest($project, $task, $revision);

        if (! $provider->isAvailable()) {
            // Not an error. A provider with no credentials is a fact to report,
            // never a reason to substitute a plan from somewhere else.
            return ReasoningOutcome::unavailable(
                $provider->name() . ' is not available: ' . ($provider->unavailableReason() ?? 'no reason given'),
                $request
            );
        }

        $response = $provider->propose($request);

        if (! $response->ok) {
            // Recorded, not just returned. "This provider truncates on large
            // contexts" is a fact about the provider that only shows up as a
            // pattern if the failures are kept next to the successes.
            $this->store->record((int) $task->id, (int) $project->id, $request, $response,
                ReasoningOutcome::PROVIDER_ERROR, [], null, $revision);

            return ReasoningOutcome::providerError(
                $provider->name() . ' could not answer: ' . ($response->error ?? 'unknown error'),
                $request, $response
            );
        }

        $violations = $this->validator->violations($response->payload);

        if ($violations !== []) {
            $id = $this->store->record((int) $task->id, (int) $project->id, $request, $response,
                ReasoningOutcome::REJECTED, $violations, null, $revision);

            return ReasoningOutcome::rejected($violations, $request, $response, $id, $this->store->uuidFor($id));
        }

        $candidate = new CandidateImplementation(
            $response->payload, $provider->name(), $response->model, $request->fingerprint()
        );

        $id = $this->store->record((int) $task->id, (int) $project->id, $request, $response,
            ReasoningOutcome::VALIDATED, [], $candidate, $revision);

        return ReasoningOutcome::validated($candidate, $request, $response, $id, $this->store->uuidFor($id));
    }

    /**
     * Violations the provider can plausibly fix by itself, once.
     *
     * A missing test, an unowned test path, an invented method, a missing
     * rollback line: these are failures to follow a contract the provider
     * was given, not failures of judgement, and telling it exactly what it
     * broke is cheaper than telling a human. Anything else needs a person.
     */
    public const RETRYABLE_RULES = [
        'missing_test_coverage', 'no_coverage', 'vague_test_coverage', 'unowned_test_path',
        'placeholder_assertion', 'unrelated_test', 'invented_coverage', 'transient_state_test',
        'forbidden_test_database', 'unjustified_non_testable',
        'missing_section', 'unjustified_blank', 'wrong_type', 'bad_confidence',
        'unknown_leaked_into_code', 'not_php', 'empty_content', 'bad_action',
    ];

    /**
     * One violation-guided retry. Not a loop.
     *
     * The distinction from revise() is who is speaking: a revision answers a
     * human's judgement, this answers Engineer888's own contract check. Both
     * are capped at one, and they share the cap — two automatic attempts plus
     * a human revision would be a regeneration loop wearing a policy.
     */
    public function retryForViolations(
        object $project,
        object $task,
        string $rejectedCandidateUuid,
        array $violations,
        ?string $providerName = null,
    ): ReasoningOutcome {
        $rules = array_column($violations, 'rule');
        $unretryable = array_values(array_diff($rules, self::RETRYABLE_RULES));

        if ($unretryable !== []) {
            return ReasoningOutcome::unavailable(
                'these violations are not the kind a provider can fix by being told about them: '
                . implode(', ', $unretryable) . '. The task is BLOCKED and needs a human.'
            );
        }

        $instruction = 'Your previous proposal was refused by Engineer888 for these contract '
            . 'violations. Fix exactly these and change nothing else: '
            . implode('; ', array_column($violations, 'detail'));

        return $this->revise($project, $task, $rejectedCandidateUuid, $instruction, $providerName);
    }

    /**
     * One bounded revision, answering a human's rejection.
     *
     * The provider is given what it needs to do better and nothing that would
     * let it simply try again: the prior proposal, why it was refused, and the
     * validator's own findings.
     */
    public function revise(
        object $project,
        object $task,
        string $priorCandidateUuid,
        string $instruction,
        ?string $providerName = null,
    ): ReasoningOutcome {
        $prior = $this->store->byUuid($priorCandidateUuid);
        if ($prior === null) {
            return ReasoningOutcome::unavailable("unknown candidate {$priorCandidateUuid}");
        }

        if ($this->store->revisionCount((int) $task->id) >= self::MAX_REVISIONS) {
            return ReasoningOutcome::unavailable(
                'this task has already had its one revision. A second automatic attempt would be a retry '
                . 'loop optimising for the validator rather than for being right — the task is BLOCKED and '
                . 'needs a human.'
            );
        }

        return $this->propose($project, $task, $providerName, [
            'of'          => (int) $prior->id,
            'instruction' => $instruction,
            'prior'       => $prior,
        ]);
    }

    /**
     * The candidate a human bound themselves to — nothing newer, nothing older.
     *
     * Sprint 7 returned "the latest validated candidate", which is how an
     * approval of #12 came to install #13. The ledger is now the only source of
     * permission, and it names one candidate.
     */
    public function approvedCandidate(int $taskId): ?CandidateImplementation
    {
        $approval = (new ApprovalLedger())->activeFor($taskId);
        if ($approval === null) { return null; }

        $candidate = $this->store->candidateByUuid((string) $approval->candidate_uuid);
        if ($candidate === null) { return null; }

        // Stored rows are still validated on the way out. A row written by an
        // older validator, or edited in the database, must not become
        // installable simply because it once passed.
        return $this->validator->violations($candidate->payload) === [] ? $candidate : null;
    }

    /** The candidate UUID an approval names, for the enforcement check. */
    public function approvedCandidateUuid(int $taskId): ?string
    {
        return (new ApprovalLedger())->activeFor($taskId)?->candidate_uuid;
    }

    public function buildRequest(object $project, object $task, array $revision = []): ReasoningRequest
    {
        $config = (array) config('engineer888_reasoning', []);
        $context = (array) ($config['context'] ?? []);

        $builder = new ContextBuilder(
            new KnowledgeBase((string) $project->repository_path),
            (int) ($context['max_bytes'] ?? 60000),
            (int) ($context['max_items_per_source'] ?? 6),
            (int) ($context['min_score'] ?? 1),
        );

        return $builder->build($project, $task, $this->revisionContext($revision));
    }

    /**
     * What a revision adds to the context, and only what it adds.
     *
     * @return array<int,array<string,mixed>>
     */
    private function revisionContext(array $revision): array
    {
        $prior = $revision['prior'] ?? null;
        if ($prior === null) { return []; }

        $items = [[
            'section' => 'revision_instruction',
            'label'   => 'a human refused the previous proposal',
            'body'    => (string) ($revision['instruction'] ?? 'no reason given')
                       . ' — this is the one revision allowed; address it directly rather than starting over.',
        ]];

        $payload = json_decode((string) $prior->payload, true) ?: [];
        foreach ((array) ($payload['file_changes'] ?? []) as $change) {
            $items[] = [
                'section' => 'revision_instruction',
                'label'   => 'previously proposed: ' . (string) ($change['path'] ?? '?'),
                'body'    => substr((string) ($change['content'] ?? ''), 0, 4000),
            ];
        }

        foreach (json_decode((string) $prior->violations, true) ?: [] as $violation) {
            $items[] = [
                'section' => 'revision_instruction',
                'label'   => 'validator finding: ' . (string) ($violation['rule'] ?? ''),
                'body'    => (string) ($violation['detail'] ?? ''),
            ];
        }

        return $items;
    }

    public function registry(): ProviderRegistry
    {
        return $this->registry;
    }

    public function store(): CandidateStore
    {
        return $this->store;
    }

    public function validator(): CandidateValidator
    {
        return $this->validator;
    }
}
