<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Approval\ApprovalBinding;
use App\Core\Engineer888\Approval\ApprovalLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every proposal, kept — including the ones that were refused.
 *
 * Rejected candidates are the more valuable half. They are how "the model keeps
 * proposing writes to the test config" becomes a visible pattern instead of a
 * thing the engineer vaguely remembers.
 *
 * STORING A CANDIDATE SUPERSEDES EVERY OPEN APPROVAL ON ITS TASK. That single
 * line is the structural fix for the Sprint 7 defect: reasoning again cannot
 * leave an older approval standing, because the act of recording the new
 * candidate invalidates it. Nothing has to remember to do it.
 *
 * IT ALSO KEEPS WHAT THE MODEL WAS LOOKING AT. The grounded pre-image travels
 * on the candidate object and is written beside the payload, in its own column
 * rather than inside it. Provider output stays verbatim, and the one piece of
 * evidence an approval must be able to trust stays out of the model's reach.
 */
final class CandidateStore
{
    public function record(
        int $taskId,
        int $projectId,
        ReasoningRequest $request,
        ProviderResponse $response,
        string $status,
        array $violations,
        ?CandidateImplementation $candidate,
        array $revision = [],
    ): int {
        $uuid = (string) Str::uuid();

        // Taken from the candidate rather than a separate argument: the object
        // that will be bound and the row that records it must describe the same
        // observation, and two parameters is two chances for them not to.
        $preImage = $candidate?->preImage ?? [];

        $id = (int) DB::table('engineering_candidates')->insertGetId([
            'uuid'                 => $uuid,
            'task_id'              => $taskId,
            'project_id'           => $projectId,
            'revision_of'          => $revision['of'] ?? null,
            'revision_instruction' => $revision['instruction'] ?? null,
            'provider'             => $response->provider,
            'model'                => $response->model,
            'status'               => $status,
            'request_fingerprint'  => $request->fingerprint(),
            'context_manifest'     => json_encode([
                'sections' => $request->contextManifest(),
                'excluded' => array_slice($request->excluded, 0, 200),
                'bytes'    => $request->sizeBytes(),
            ], JSON_PARTIAL_OUTPUT_ON_ERROR),
            'payload'              => json_encode($response->payload, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'violations'           => json_encode($violations, JSON_PARTIAL_OUTPUT_ON_ERROR),
            // NULL, not "[]", when there is nothing to record. A candidate that
            // never had a pre-image reads the same as one stored before the
            // column existed, which is the truth: neither was bound.
            'pre_image'            => $preImage === []
                ? null
                : json_encode($preImage, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'confidence'           => $candidate?->confidence(),
            'file_count'           => $candidate === null ? 0 : count($candidate->fileChanges()),
            'content_fingerprint'  => $candidate?->contentFingerprint(),
            'latency_ms'           => $response->latencyMs,
            'usage'                => json_encode($response->usage, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'error'                => $response->error,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $ledger = new ApprovalLedger();

        // Anything previously approved on this task now describes bytes that are
        // no longer the newest proposal. It stops being permission.
        $ledger->supersedeOpenApprovals($taskId, $id);

        if ($revision['of'] ?? null) {
            DB::table('engineering_candidates')->where('id', $revision['of'])
                ->update(['superseded_by' => $id, 'superseded_at' => now(), 'updated_at' => now()]);
        }

        // Only a valid candidate can be approved. A rejected one has nothing a
        // human could bind to.
        if ($candidate !== null && $status === ReasoningOutcome::VALIDATED) {
            $task = DB::table('engineering_tasks')->find($taskId);
            $project = DB::table('engineering_projects')->find($projectId);

            if ($task !== null && $project !== null) {
                $ledger->record(
                    $id,
                    ApprovalBinding::forCandidate($candidate, $uuid, (string) $task->uuid, (string) $project->key),
                    $taskId,
                    $projectId,
                );
            }
        }

        return $id;
    }

    public function uuidFor(int $candidateId): ?string
    {
        $row = DB::table('engineering_candidates')->find($candidateId);

        return $row?->uuid;
    }

    /** The most recent validated candidate for a task, or null. */
    public function latestValidated(int $taskId): ?CandidateImplementation
    {
        return $this->hydrate(
            DB::table('engineering_candidates')
                ->where('task_id', $taskId)
                ->where('status', ReasoningOutcome::VALIDATED)
                ->orderByDesc('id')
                ->first()
        );
    }

    public function byUuid(string $uuid): ?object
    {
        return DB::table('engineering_candidates')->where('uuid', $uuid)->first();
    }

    public function candidateByUuid(string $uuid): ?CandidateImplementation
    {
        return $this->hydrate($this->byUuid($uuid));
    }

    /** How many revisions have already answered a rejection on this task. */
    public function revisionCount(int $taskId): int
    {
        return (int) DB::table('engineering_candidates')
            ->where('task_id', $taskId)->whereNotNull('revision_of')->count();
    }

    /** @return array<int,object> */
    public function forTask(int $taskId): array
    {
        return DB::table('engineering_candidates')
            ->where('task_id', $taskId)->orderBy('id')->get()->all();
    }

    private function hydrate(?object $row): ?CandidateImplementation
    {
        if ($row === null) { return null; }

        $payload = json_decode((string) $row->payload, true);
        if (! is_array($payload)) { return null; }

        // A row written before the column existed decodes to nothing, and the
        // candidate correctly reports itself as unbound. It is never repaired by
        // hashing the repository now: that would present today's bytes as the
        // ones an older model reasoned against.
        $preImage = json_decode((string) ($row->pre_image ?? ''), true);

        return new CandidateImplementation(
            $payload, (string) $row->provider, (string) $row->model, (string) $row->request_fingerprint,
            is_array($preImage) ? $preImage : []
        );
    }
}
