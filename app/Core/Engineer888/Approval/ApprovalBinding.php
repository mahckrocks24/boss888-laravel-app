<?php

namespace App\Core\Engineer888\Approval;

use App\Core\Engineer888\Reasoning\CandidateImplementation;

/**
 * What a human actually approved, reduced to one hash.
 *
 * THE DEFECT THIS EXISTS TO CLOSE. In Sprint 7 a human approved candidate #12,
 * PLAN reasoned again inside the run, and IMPLEMENT installed candidate #13.
 * Both were valid and both happened to be correct — but the bytes that reached
 * the repository were not the bytes anybody read. Approval was recorded against
 * the task, and a task is not a change.
 *
 * The binding covers everything that would make an approval mean something
 * different: which candidate, which task, which project, which provider and
 * model produced it, which paths, and the exact content of each path. Change
 * any of those and the hash changes, and IMPLEMENT refuses.
 *
 * ORDER MUST NOT MATTER. Paths are sorted before hashing, so a provider that
 * returns the same two files in the other order does not invalidate an approval
 * — and a provider that returns *different* files always does. The directive
 * asks that a reordering which changes the fingerprint be refused; sorting makes
 * such a reordering impossible to produce accidentally, which is stronger.
 */
final class ApprovalBinding
{
    public const VERSION = 'e888-approval-v1';

    public function __construct(
        public readonly string $candidateUuid,
        public readonly string $taskUuid,
        public readonly string $projectKey,
        public readonly string $provider,
        public readonly string $model,
        /** @var array<string,string> path => sha256 of the exact proposed bytes */
        public readonly array $fileHashes,
    ) {}

    public static function forCandidate(
        CandidateImplementation $candidate,
        string $candidateUuid,
        string $taskUuid,
        string $projectKey,
    ): self {
        $hashes = [];
        foreach ($candidate->asChangeSet() as $path => $spec) {
            $hashes[$path] = hash('sha256', (string) $spec['content']);
        }
        ksort($hashes);

        return new self($candidateUuid, $taskUuid, $projectKey,
            $candidate->provider, $candidate->model, $hashes);
    }

    /** @return array<int,string> */
    public function paths(): array
    {
        return array_keys($this->fileHashes);
    }

    /**
     * The single value an approval is recorded against.
     *
     * Deliberately includes VERSION. If the binding's definition ever changes,
     * every existing approval stops matching rather than silently meaning
     * something narrower than the human intended.
     */
    /**
     * Extra parts a caller can bind in — currently the migration rehearsal.
     *
     * A migration approval that covered only the file's bytes would survive the
     * rehearsal being redone, failing, or having been run against a different
     * database. Binding the rehearsal identity and verdict means a migration
     * approval cannot outlive the evidence it rested on.
     *
     * @var array<int,string>
     */
    public array $extraParts = [];

    public function withExtraParts(array $parts): self
    {
        $clone = new self($this->candidateUuid, $this->taskUuid, $this->projectKey,
            $this->provider, $this->model, $this->fileHashes);
        $clone->extraParts = $parts;

        return $clone;
    }

    public function fingerprint(): string
    {
        $parts = [
            self::VERSION,
            'candidate:' . $this->candidateUuid,
            'task:' . $this->taskUuid,
            'project:' . $this->projectKey,
            'provider:' . $this->provider,
            'model:' . $this->model,
        ];

        foreach ($this->fileHashes as $path => $hash) {
            $parts[] = 'file:' . $path . ':' . $hash;
        }

        foreach ($this->extraParts as $extra) { $parts[] = $extra; }

        return hash('sha256', implode("\n", $parts));
    }

    /** Short form for display. Never used for comparison. */
    public function shortFingerprint(): string
    {
        return substr($this->fingerprint(), 0, 16);
    }

    /**
     * Every way this binding differs from another.
     *
     * Returns reasons rather than a boolean, because "refused" without a reason
     * is the kind of gate people work around instead of fixing.
     *
     * @return array<int,string>
     */
    public function differencesFrom(self $approved): array
    {
        $differences = [];

        if ($this->candidateUuid !== $approved->candidateUuid) {
            $differences[] = "a different candidate: approved {$approved->candidateUuid}, holding {$this->candidateUuid}";
        }
        if ($this->taskUuid !== $approved->taskUuid) {
            $differences[] = 'a different task';
        }
        if ($this->projectKey !== $approved->projectKey) {
            $differences[] = "a different project: approved {$approved->projectKey}, holding {$this->projectKey}";
        }
        if ($this->provider !== $approved->provider || $this->model !== $approved->model) {
            $differences[] = "a different provider or model: approved {$approved->provider}/{$approved->model}, "
                           . "holding {$this->provider}/{$this->model}";
        }

        $added = array_diff($this->paths(), $approved->paths());
        $removed = array_diff($approved->paths(), $this->paths());

        foreach ($added as $path) { $differences[] = "a file nobody approved: {$path}"; }
        foreach ($removed as $path) { $differences[] = "an approved file that is now missing: {$path}"; }

        foreach ($this->fileHashes as $path => $hash) {
            if (! isset($approved->fileHashes[$path])) { continue; }
            if ($approved->fileHashes[$path] !== $hash) {
                $differences[] = "changed content in {$path}";
            }
        }

        return $differences;
    }

    public function toArray(): array
    {
        return [
            'version'        => self::VERSION,
            'candidate_uuid' => $this->candidateUuid,
            'task_uuid'      => $this->taskUuid,
            'project_key'    => $this->projectKey,
            'provider'       => $this->provider,
            'model'          => $this->model,
            'file_hashes'    => $this->fileHashes,
            'extra_parts'    => $this->extraParts,
            'fingerprint'    => $this->fingerprint(),
        ];
    }

    public static function fromArray(array $data): self
    {
        $binding = new self(
            (string) ($data['candidate_uuid'] ?? ''),
            (string) ($data['task_uuid'] ?? ''),
            (string) ($data['project_key'] ?? ''),
            (string) ($data['provider'] ?? ''),
            (string) ($data['model'] ?? ''),
            (array) ($data['file_hashes'] ?? []),
        );

        $binding->extraParts = (array) ($data['extra_parts'] ?? []);

        return $binding;
    }
}
