<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * A proposed implementation. Not an implementation.
 *
 * This object is the entire product of the reasoning engine: structured
 * engineering output plus, optionally, candidate file content. It is inert.
 * Nothing here writes, and nothing here is trusted — the workflow still proves
 * ownership, still requires approval, still runs SafeInstaller, still verifies.
 *
 * UNKNOWN is a first-class value. A model that says "UNKNOWN" for the migration
 * question has told the truth and the workflow can act on it; a model that
 * invents a confident answer has produced the one defect no downstream gate
 * catches, because the output looks exactly like a correct one.
 *
 * THE PRE-IMAGE IS NOT PART OF THE PAYLOAD. `payload` is what the provide
 * said, kept verbatim so the record can never be read as anything else.
 * `preImage` is what Engineer888 itself observed while grounding the request.
 * They are separate constructor arguments for one reason: if evidence about the
 * repository lived inside provider output, a model could author its own
 * pre-image, and the one field an approval must be able to trust would be the
 * one field the model controls.
 */
final class CandidateImplementation
{
    public const UNKNOWN = 'UNKNOWN';

    /** The structured output contract. Every provider is asked for exactly this. */
    public const CONTRACT = [
        'problem_understanding'   => 'string — what is actually being asked, in your words',
        'assumptions'             => ['string — each assumption you had to make'],
        'unknowns'                => [['question' => 'string', 'why_it_matters' => 'string', 'how_to_resolve' => 'string']],
        'implementation_strategy' => 'string — the approach, and why this one',
        'files_affected'          => [['path' => 'string', 'action' => 'create|update|read|none', 'why' => 'string']],
        'migrations'              => ['required' => 'true|false|UNKNOWN', 'detail' => 'string'],
        'risks'                   => [['risk' => 'string', 'breaks' => 'string', 'mitigation' => 'string']],
        'testing_strategy'        => 'string — what proves this works, and what proves it did not break anything',
        'rollback'                => 'string — how to undo this if verification fails',
        'file_changes'            => [['path' => 'string relative to the repository root',
                                       'action' => 'create|update',
                                       'content' => 'string — the complete file content, not a diff',
                                       'rationale' => 'string']],
        'test_coverage'           => [
            'behaviour_changed'        => 'string — what observable behaviour this change alters',
            'test_files_proposed'      => ['string — paths in file_changes that are tests'],
            'existing_tests'           => ['string — existing test paths this change is already covered by'],
            'expected_assertions'      => ['string — what each test asserts, concretely'],
            'regression_prevented'     => 'string — the specific failure a future engineer would cause without this test',
            'test_database'            => 'string — the assigned session database, never a shared or production one',
            'full_suite_required'      => 'true|false|UNKNOWN',
            'gaps'                     => ['string — what remains unproven'],
            'classification'           => 'TESTED|NON_TESTABLE',
            'non_testable_reason'      => 'string — required when NON_TESTABLE; size is not a reason',
            'alternative_verification' => 'string — required when NON_TESTABLE',
            'risk'                     => 'string — required when NON_TESTABLE',
            'confidence'               => 'high|medium|low|unknown',
        ],
        'confidence'              => 'high|medium|low|unknown',
        'confidence_basis'        => 'string — what your confidence rests on',
    ];

    public const REQUIRED_KEYS = [
        'problem_understanding', 'assumptions', 'unknowns', 'implementation_strategy',
        'files_affected', 'migrations', 'risks', 'testing_strategy', 'rollback',
        'file_changes', 'confidence',
    ];

    /**
     * test_coverage is required only when the change is executable, so a
     * documentation task is not made to justify tests it cannot have.
     * TestContract decides; it is not in REQUIRED_KEYS for that reason.
     */
    public const COVERAGE_KEY = 'test_coverage';

    public const CONFIDENCE_LEVELS = ['high', 'medium', 'low', 'unknown'];

    public function __construct(
        public readonly array $payload,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $requestFingerprint,
        /**
         * Engineer888's own observation of the sources this proposal was
         * reasoned against, as PreImage::fromGrounding() shaped it.
         *
         * Empty means legacy: a candidate produced before pre-image evidence
         * was carried. It is never filled in afterwards by re-reading the
         * repository, because a hash taken today is not what an older model saw.
         *
         * @var array<int,array<string,mixed>>
         */
        public readonly array $preImage = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /** @return array<int,array{path:string,action:string,content:string,rationale:string}> */
    public function fileChanges(): array
    {
        $changes = $this->payload['file_changes'] ?? [];

        return is_array($changes) ? array_values(array_filter($changes, 'is_array')) : [];
    }

    /** @return array<int,string> */
    public function paths(): array
    {
        return array_values(array_filter(array_map(
            fn (array $c) => isset($c['path']) && is_string($c['path']) ? $c['path'] : null,
            $this->fileChanges()
        )));
    }

    /** @return array<int,array<string,mixed>> */
    public function unknowns(): array
    {
        $unknowns = $this->payload['unknowns'] ?? [];

        return is_array($unknowns) ? array_values(array_filter($unknowns, 'is_array')) : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function risks(): array
    {
        $risks = $this->payload['risks'] ?? [];

        return is_array($risks) ? array_values(array_filter($risks, 'is_array')) : [];
    }

    public function confidence(): string
    {
        $value = strtolower((string) ($this->payload['confidence'] ?? 'unknown'));

        return in_array($value, self::CONFIDENCE_LEVELS, true) ? $value : 'unknown';
    }

    /** True when the proposal explicitly declines to answer something. */
    public function declaresUnknowns(): bool
    {
        return $this->unknowns() !== [] || $this->confidence() === 'unknown';
    }

    /** A candidate that proposes no file content cannot be implemented. */
    public function isEmpty(): bool
    {
        return $this->fileChanges() === [];
    }

    /**
     * Does a complete, positive pre-image cover every file this candidate changes?
     *
     * This is the legacy discriminator, and it answers by inspection rather than
     * by date: a candidate stored before E1-A has no evidence and reports false,
     * and so does a new candidate whose evidence is incomplete. A later gate may
     * refuse on that basis; nothing here manufactures the missing half.
     */
    public function hasBoundPreImage(): bool
    {
        return PreImage::covers($this->preImage, $this->paths());
    }

    /**
     * The shape ImplementStage already knows how to consume.
     *
     * Deliberately the SAME structure a human-supplied change set uses, so
     * model-authored content flows through the identical governed path rathe
     * than a second one written for it.
     *
     * @return array<string,array{content:string}>
     */
    public function asChangeSet(): array
    {
        $changeSet = [];
        foreach ($this->fileChanges() as $change) {
            if (! isset($change['path'], $change['content'])) { continue; }
            $changeSet[(string) $change['path']] = ['content' => (string) $change['content']];
        }

        return $changeSet;
    }

    /**
     * Identity of the proposed content, so drift between stages is detectable.
     *
     * Deliberately still the POST-image alone. It answers "are these the same
     * proposed bytes?", which is a different question from "was this approved?",
     * and the approval fingerprint is where the pre-image belongs.
     */
    public function contentFingerprint(): string
    {
        $parts = [];
        foreach ($this->asChangeSet() as $path => $spec) {
            $parts[] = $path . ':' . sha1($spec['content']);
        }
        sort($parts);

        return sha1(implode('|', $parts));
    }

    public function toArray(): array
    {
        return [
            'provider'            => $this->provider,
            'model'               => $this->model,
            'request_fingerprint' => $this->requestFingerprint,
            'content_fingerprint' => $this->contentFingerprint(),
            'confidence'          => $this->confidence(),
            'files'               => $this->paths(),
            'unknowns'            => count($this->unknowns()),
            'pre_image'           => $this->preImage,
            'pre_image_bound'     => $this->hasBoundPreImage(),
            'payload'             => $this->payload,
        ];
    }

    public static function fromArray(array $row): self
    {
        return new self(
            $row['payload'] ?? [],
            (string) ($row['provider'] ?? 'unknown'),
            (string) ($row['model'] ?? 'unknown'),
            (string) ($row['request_fingerprint'] ?? ''),
            is_array($row['pre_image'] ?? null) ? $row['pre_image'] : [],
        );
    }
}
