<?php

namespace App\Http\Controllers\Api\Admin;

use App\Core\Engineer888\Approval\ApprovalBinding;
use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovalState;
use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Reasoning\CandidateStore;
use App\Core\Engineer888\Recovery\ManualRecovery;
use App\Core\Engineer888\Reasoning\ProviderRegistry;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Reasoning\ReasoningOutcome;
use App\Core\Engineer888\Support\UnifiedDiff;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use App\Jobs\Engineer888WorkflowJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Engineer888 Command Center — the admin API.
 *
 * Every route here sits inside the existing admin group (auth.jwt + admin) and
 * adds DenyApiKeyAuth in its own route file, so nothing is reachable by an API
 * key or a customer session.
 *
 * The controller is deliberately thin. It reads and presents; the decisions live
 * in ApprovalLedger, ReasoningEngine and the workflow, which are the things the
 * test suite exercises directly. A control that only exists in a controller is a
 * control that a second caller can bypass.
 *
 * NOTE: this app has no base Controller class; controllers here extend nothing.
 */
final class Engineer888Controller
{
    /** The fourteen stages, in order — the UI renders every one, run or not. */
    private const LIFECYCLE = [
        'RECEIVE', 'UNDERSTAND', 'ANALYZE', 'PLAN', 'ESTIMATE', 'IDENTIFY_FILES',
        'IDENTIFY_RISKS', 'REQUEST_APPROVAL', 'IMPLEMENT', 'VERIFY', 'DEPLOY_VERIFY',
        'DOCUMENT', 'LEARN', 'PROMOTE',
    ];

    // ── read ────────────────────────────────────────────────────────────

    public function bootstrap(Request $request)
    {
        $projects = DB::table('engineering_projects')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'company' => $p->company,
            'repository_path' => $p->repository_path, 'phpunit_config' => $p->phpunit_config,
            'test_database' => $p->test_database, 'ownership_manifest' => $p->ownership_manifest,
        ])->all();

        $registry = ProviderRegistry::fromConfig();

        return response()->json([
            'projects'         => $projects,
            'default_project'  => $projects[0]['key'] ?? null,
            'lifecycle'        => self::LIFECYCLE,
            'providers'        => $registry->inventory(),
            'provider_default' => $registry->defaultName(),
            'reasoning_enabled' => $registry->enabled(),
            'actor'            => $this->actorName($request),
            'counts'           => [
                'total'     => DB::table('engineering_tasks')->count(),
                'awaiting'  => DB::table('engineering_candidate_approvals')
                                  ->where('state', ApprovalState::PENDING)->count(),
                'blocked'   => DB::table('engineering_tasks')->where('status', 'blocked')->count(),
                'failed'    => DB::table('engineering_tasks')->where('status', 'failed')->count(),
                'completed' => DB::table('engineering_tasks')->where('status', 'completed')->count(),
            ],
        ]);
    }

    public function tasks(Request $request)
    {
        $query = DB::table('engineering_tasks as t')
            ->leftJoin('engineering_projects as p', 'p.id', '=', 't.project_id')
            ->select('t.*', 'p.key as project_key', 'p.name as project_name');

        foreach (['status' => 't.status', 'priority' => 't.priority', 'stage' => 't.current_stage'] as $param => $column) {
            if ($request->filled($param)) { $query->where($column, $request->query($param)); }
        }
        if ($request->filled('project')) { $query->where('p.key', $request->query('project')); }
        if ($request->filled('q')) {
            $term = '%' . $request->query('q') . '%';
            $query->where(fn ($w) => $w->where('t.title', 'like', $term)->orWhere('t.uuid', 'like', $term));
        }

        $rows = $query->orderByDesc('t.id')->limit(200)->get();

        // One query for approvals and one for candidates, then matched in PHP.
        // A per-row lookup here would be 200 round trips on a single-core box.
        $approvals = DB::table('engineering_candidate_approvals')
            ->whereIn('task_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('task_id');
        $candidates = DB::table('engineering_candidates')
            ->whereIn('task_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('task_id');

        $tasks = [];
        foreach ($rows as $row) {
            $taskApprovals = $approvals->get($row->id, collect());
            $taskCandidates = $candidates->get($row->id, collect());
            $latestCandidate = $taskCandidates->last();

            $approvalState = $this->approvalStateFor($taskApprovals);

            $tasks[] = [
                'uuid'           => $row->uuid,
                'title'          => $row->title,
                'project'        => $row->project_name,
                'project_key'    => $row->project_key,
                'kind'           => $row->kind,
                'priority'       => $row->priority,
                'status'         => $row->status,
                'stage'          => $row->current_stage,
                'provider'       => $latestCandidate->provider ?? null,
                'model'          => $latestCandidate->model ?? null,
                'candidates'     => $taskCandidates->count(),
                'approval_state' => $approvalState,
                'requested_by'   => $row->requested_by,
                'created_at'     => $row->created_at,
                'last_activity'  => $row->updated_at,
                'blocker'        => $this->blockerFor($row),
            ];
        }

        if ($request->query('awaiting') === '1') {
            $tasks = array_values(array_filter($tasks, fn ($t) => $t['approval_state'] === ApprovalState::PENDING));
        }
        if ($request->filled('provider')) {
            $tasks = array_values(array_filter($tasks, fn ($t) => $t['provider'] === $request->query('provider')));
        }

        return response()->json(['tasks' => $tasks, 'count' => count($tasks)]);
    }

    public function task(Request $request, string $uuid)
    {
        $task = DB::table('engineering_tasks')->where('uuid', $uuid)->first();
        if ($task === null) { return response()->json(['error' => 'unknown task'], 404); }

        $project = DB::table('engineering_projects')->find($task->project_id);
        $recorded = DB::table('engineering_task_stages')->where('task_id', $task->id)
            ->orderBy('position')->get()->keyBy('stage');

        $stages = [];
        foreach (self::LIFECYCLE as $position => $name) {
            $row = $recorded->get($name);

            if ($row === null) {
                $stages[] = ['stage' => $name, 'position' => $position + 1, 'state' => 'PENDING',
                             'summary' => null, 'duration_ms' => null];
                continue;
            }

            $inputs = json_decode((string) $row->inputs, true) ?: [];
            $evidence = json_decode((string) $row->evidence, true) ?: [];

            $stages[] = [
                'stage'        => $name,
                'position'     => (int) $row->position,
                'state'        => $this->stageState($name, (string) $row->status),
                'summary'      => $row->summary,
                'failure'      => $row->failure,
                'duration_ms'  => (int) $row->duration_ms,
                'started_at'   => $row->created_at,
                'completed_at' => $row->updated_at,
                'purpose'      => $inputs['purpose'] ?? null,
                'inputs'       => $inputs['declared'] ?? [],
                'outputs'      => $this->summariseOutputs($name, json_decode((string) $row->outputs, true) ?: []),
                'evidence'     => $evidence['evidence'] ?? [],
                'verification' => $evidence['verification'] ?? null,
                'recovery'     => $evidence['recovery'] ?? null,
            ];
        }

        foreach ($recorded as $name => $row) {
            if (in_array($name, self::LIFECYCLE, true)) { continue; }
            $evidenceJson = json_decode((string) $row->outputs, true) ?: [];
            $stages[] = [
                'stage'        => $name,
                'position'     => (int) $row->position,
                'state'        => $row->status === 'ok' ? 'PASSED' : 'FAILED',
                'summary'      => $row->summary,
                'failure'      => $row->failure,
                'duration_ms'  => (int) $row->duration_ms,
                'started_at'   => $row->created_at,
                'completed_at' => $row->updated_at,
                'purpose'      => (json_decode((string) $row->inputs, true) ?: [])['purpose'] ?? null,
                'inputs'       => (json_decode((string) $row->inputs, true) ?: [])['declared'] ?? [],
                'outputs'      => $this->summariseOutputs($name, $evidenceJson),
                'evidence'     => [],
                'verification' => null,
                'recovery'     => null,
                'lifecycle'    => false,
            ];
        }

        $ledger = new ApprovalLedger();
        $approvals = $ledger->historyFor((int) $task->id);

        $candidates = DB::table('engineering_candidates')->where('task_id', $task->id)
            ->orderBy('id')->get()->map(fn ($c) => [
                'uuid' => $c->uuid, 'status' => $c->status, 'provider' => $c->provider,
                'model' => $c->model, 'file_count' => $c->file_count, 'confidence' => $c->confidence,
                'latency_ms' => $c->latency_ms, 'created_at' => $c->created_at,
                'revision_of' => $c->revision_of, 'superseded_by' => $c->superseded_by,
                'violations' => json_decode((string) $c->violations, true) ?: [],
                'error' => $c->error,
                'approval' => $this->approvalSummary(
                    collect($approvals)->firstWhere('candidate_uuid', $c->uuid)
                ),
            ])->all();

        return response()->json([
            'task' => [
                'uuid' => $task->uuid, 'title' => $task->title, 'description' => $task->description,
                'kind' => $task->kind, 'priority' => $task->priority, 'status' => $task->status,
                'stage' => $task->current_stage, 'requested_by' => $task->requested_by,
                'acceptance_criteria' => json_decode((string) $task->acceptance_criteria, true) ?: [],
                'constraints' => json_decode((string) $task->constraints, true) ?: [],
                'modules' => json_decode((string) $task->modules, true) ?: [],
                'created_at' => $task->created_at, 'updated_at' => $task->updated_at,
            ],
            'project'    => $project === null ? null : [
                'key' => $project->key, 'name' => $project->name, 'company' => $project->company,
                'repository_path' => $project->repository_path,
            ],
            'stages'     => $stages,
            'candidates' => $candidates,
            'approvals'  => array_map(fn ($a) => $this->approvalSummary($a), $approvals),
            'evidence'   => $this->completionEvidence($task, $recorded),
            'recoveries' => DB::table('engineering_recoveries')
                ->where('task_uuid', $task->uuid)->orderBy('id')->get()
                ->map(fn ($r) => [
                    'status'      => $r->status,
                    'fingerprint' => $r->fingerprint,
                    'actor'       => $r->actor,
                    'approved_by' => $r->approved_by,
                    'id'          => $r->id,
                    'evidence'    => json_decode((string) $r->evidence, true) ?: [],
                    'manifest'    => json_decode((string) $r->manifest, true) ?: [],
                    'at'          => $r->created_at,
                ])->all(),
            'revisions'  => [
                'used'    => (new CandidateStore())->revisionCount((int) $task->id),
                'allowed' => ReasoningEngine::MAX_REVISIONS,
            ],
        ]);
    }

    public function candidate(Request $request, string $uuid)
    {
        $engine = ReasoningEngine::make();
        $row = $engine->store()->byUuid($uuid);
        if ($row === null) { return response()->json(['error' => 'unknown candidate'], 404); }

        $task = DB::table('engineering_tasks')->find($row->task_id);
        $project = DB::table('engineering_projects')->find($row->project_id);
        $payload = json_decode((string) $row->payload, true) ?: [];
        $manifest = $project === null ? null : OwnershipManifest::active($project->repository_path);

        $files = [];
        foreach ((array) ($payload['file_changes'] ?? []) as $change) {
            $path = (string) ($change['path'] ?? '');
            $proposed = (string) ($change['content'] ?? '');
            $absolute = rtrim((string) ($project->repository_path ?? ''), '/') . '/' . $path;
            $exists = $path !== '' && is_file($absolute);
            $current = $exists ? (string) file_get_contents($absolute) : '';

            $ownership = $manifest?->classify($path) ?? ['status' => OwnershipManifest::UNKNOWN,
                                                          'evidence' => 'no active sprint manifest'];

            $files[] = [
                'path'          => $path,
                'action'        => $exists ? 'UPDATE' : strtoupper((string) ($change['action'] ?? 'CREATE')),
                'rationale'     => $change['rationale'] ?? null,
                'bytes'         => strlen($proposed),
                'current'       => $exists ? $current : null,
                'proposed'      => $proposed,
                'diff'          => UnifiedDiff::between($current, $proposed),
                'ownership'     => $ownership['status'],
                'ownership_why' => $ownership['evidence'] ?? null,
                'committable'   => OwnershipManifest::isCommittable((string) $ownership['status']),
                'governed'      => GovernedFiles::isGoverned($path),
                'governed_policy' => GovernedFiles::policyFor($path),
            ];
        }

        $binding = null;
        $candidate = $engine->store()->candidateByUuid($uuid);
        if ($candidate !== null && $task !== null && $project !== null) {
            $binding = ApprovalBinding::forCandidate(
                $candidate, $uuid, (string) $task->uuid, (string) $project->key
            )->toArray();
        }

        $context = json_decode((string) $row->context_manifest, true) ?: [];

        return response()->json([
            'candidate' => [
                'uuid' => $row->uuid, 'status' => $row->status, 'provider' => $row->provider,
                'model' => $row->model, 'requested_model' => $row->provider,
                'fallback_used' => false, 'generated_at' => $row->created_at,
                'latency_ms' => $row->latency_ms, 'confidence' => $row->confidence,
                'content_fingerprint' => $row->content_fingerprint,
                'request_fingerprint' => $row->request_fingerprint,
                'revision_of' => $row->revision_of, 'revision_instruction' => $row->revision_instruction,
                'superseded_by' => $row->superseded_by,
                'usage' => json_decode((string) $row->usage, true) ?: [],
                'error' => $row->error,
            ],
            'task_uuid'  => $task->uuid ?? null,
            'project'    => $project->key ?? null,
            'reasoning'  => [
                'problem_understanding'   => $payload['problem_understanding'] ?? null,
                'implementation_strategy' => $payload['implementation_strategy'] ?? null,
                'assumptions'             => $payload['assumptions'] ?? [],
                'unknowns'                => $payload['unknowns'] ?? [],
                'risks'                   => $payload['risks'] ?? [],
                'migrations'              => $payload['migrations'] ?? null,
                'testing_strategy'        => $payload['testing_strategy'] ?? null,
                'rollback'                => $payload['rollback'] ?? null,
                'confidence_basis'        => $payload['confidence_basis'] ?? null,
            ],
            'context'    => [
                'sections' => $context['sections'] ?? [],
                'excluded' => array_slice((array) ($context['excluded'] ?? []), 0, 60),
                'bytes'    => $context['bytes'] ?? null,
            ],
            'files'      => $files,
            'binding'    => $binding,
            'validation' => json_decode((string) $row->violations, true) ?: [],
            'approval'   => $this->approvalSummary((new ApprovalLedger())->forCandidateUuid($uuid)),
            'totals'     => [
                'files'     => count($files),
                'additions' => array_sum(array_column(array_column($files, 'diff'), 'additions')),
                'deletions' => array_sum(array_column(array_column($files, 'diff'), 'deletions')),
            ],
        ]);
    }

    public function projects(Request $request)
    {
        $rows = DB::table('engineering_projects')->orderBy('id')->get();

        $out = [];
        foreach ($rows as $project) {
            $tasks = DB::table('engineering_tasks')->where('project_id', $project->id);

            $out[] = [
                'key' => $project->key, 'name' => $project->name, 'company' => $project->company,
                'repository_path' => $project->repository_path,
                'ownership_manifest' => $project->ownership_manifest,
                'test_database' => $project->test_database,
                'phpunit_config' => $project->phpunit_config,
                'tasks' => [
                    'total'     => (clone $tasks)->count(),
                    'completed' => (clone $tasks)->where('status', 'completed')->count(),
                    'failed'    => (clone $tasks)->where('status', 'failed')->count(),
                    'blocked'   => (clone $tasks)->where('status', 'blocked')->count(),
                ],
                'assets' => DB::table('engineering_assets')
                    ->where(fn ($q) => $q->where('project_id', $project->id)->orWhereNull('project_id'))
                    ->orderByDesc('id')->limit(10)
                    ->get(['kind', 'name', 'summary', 'evidence_task_id', 'project_id'])->all(),
                'recent_failures' => DB::table('engineering_task_stages as s')
                    ->join('engineering_tasks as t', 't.id', '=', 's.task_id')
                    ->where('t.project_id', $project->id)
                    ->whereIn('s.status', ['failed', 'blocked'])
                    ->orderByDesc('s.id')->limit(5)
                    ->get(['s.stage', 's.summary', 't.title'])->all(),
            ];
        }

        return response()->json(['projects' => $out]);
    }

    // ── write ───────────────────────────────────────────────────────────

    public function createTask(Request $request)
    {
        $data = $request->validate([
            'project'             => 'required|string|max:64',
            'title'               => 'required|string|max:255',
            'description'         => 'required|string|max:20000',
            'kind'                => 'nullable|string|max:32',
            'priority'            => 'nullable|string|in:low,normal,high,urgent',
            'acceptance_criteria' => 'nullable|array|max:20',
            'acceptance_criteria.*' => 'string|max:1000',
            'constraints'         => 'nullable|array|max:20',
            'constraints.*'       => 'string|max:1000',
            'modules'             => 'nullable|array|max:20',
            'modules.*'           => 'string|max:120',
            'environment'         => 'nullable|string|max:64',
            'notes'               => 'nullable|string|max:5000',
        ]);

        $engine = new WorkflowEngine();
        if ($engine->project($data['project']) === null) {
            return response()->json(['error' => 'unknown project'], 422);
        }

        $constraints = $data['constraints'] ?? [];
        if (! empty($data['environment'])) { $constraints[] = 'environment: ' . $data['environment']; }
        if (! empty($data['notes'])) { $constraints[] = 'note: ' . $data['notes']; }

        $task = $engine->createTask($data['project'], [
            'title'               => $data['title'],
            'description'         => $data['description'],
            'kind'                => $data['kind'] ?? 'feature',
            'priority'            => $data['priority'] ?? 'normal',
            'requested_by'        => $this->actorName($request),
            'acceptance_criteria' => $data['acceptance_criteria'] ?? [],
            'constraints'         => $constraints,
            'modules'             => $data['modules'] ?? [],
            'change_set'          => [],
        ]);

        return response()->json(['uuid' => $task->uuid, 'title' => $task->title], 201);
    }

    public function reason(Request $request, string $uuid)
    {
        $data = $request->validate(['provider' => 'nullable|string|max:32']);

        [$task, $project, $error] = $this->resolve($uuid);
        if ($error !== null) { return $error; }

        $outcome = ReasoningEngine::make()->propose($project, $task, $data['provider'] ?? null);

        return response()->json([
            'status'         => $outcome->status,
            'reason'         => $outcome->reason,
            'candidate_uuid' => $outcome->candidateUuid,
            'violations'     => $outcome->violations,
        ], $outcome->isValidated() ? 200 : 422);
    }

    /**
     * Approve exactly these bytes.
     *
     * The fingerprint is required and is compared, not trusted. It is the
     * value the UI displayed; submitting it proves the human was looking at
     * this candidate and not at a screen that has since been superseded.
     */
    public function approve(Request $request, string $uuid)
    {
        $data = $request->validate([
            'fingerprint' => 'required|string|size:64',
            'statement'   => 'required|string|max:500',
            'comment'     => 'nullable|string|max:2000',
        ]);

        $ledger = new ApprovalLedger();
        $row = $ledger->forCandidateUuid($uuid);
        if ($row === null) { return response()->json(['error' => 'unknown candidate'], 404); }

        // The typed statement must be the exact sentence for THIS candidate and
        // fingerprint. A generic "I approve" would let a click mean anything.
        $expected = $ledger->statement($uuid, $data['fingerprint']);
        if (trim($data['statement']) !== $expected) {
            return response()->json([
                'error'    => 'the approval statement does not match this candidate',
                'expected' => $expected,
            ], 422);
        }

        try {
            $approval = $ledger->approve(
                $uuid, $data['fingerprint'],
                (int) ($request->user()->id ?? 0),
                $this->actorName($request),
                $data['comment'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['approval' => $this->approvalSummary($approval)]);
    }

    public function reject(Request $request, string $uuid)
    {
        $data = $request->validate([
            'instruction' => 'required|string|max:2000',
            'revise'      => 'nullable|boolean',
        ]);

        $ledger = new ApprovalLedger();
        $row = $ledger->forCandidateUuid($uuid);
        if ($row === null) { return response()->json(['error' => 'unknown candidate'], 404); }

        $ledger->reject($uuid, (int) ($request->user()->id ?? 0), $this->actorName($request), $data['instruction']);

        if (! ($data['revise'] ?? false)) {
            return response()->json(['rejected' => true, 'revision' => null]);
        }

        $task = DB::table('engineering_tasks')->find($row->task_id);
        $project = DB::table('engineering_projects')->find($row->project_id);

        $outcome = ReasoningEngine::make()->revise($project, $task, $uuid, $data['instruction']);

        // A refused revision is not an error to retry — it is the point at which
        // the task needs a human. Reported as BLOCKED, never as a failure to
        // paper over with another attempt.
        return response()->json([
            'rejected' => true,
            'revision' => [
                'status'         => $outcome->status,
                'reason'         => $outcome->reason,
                'candidate_uuid' => $outcome->candidateUuid,
                'violations'     => $outcome->violations,
                'blocked'        => ! $outcome->isValidated(),
            ],
        ], $outcome->isValidated() ? 200 : 422);
    }

    public function revoke(Request $request, string $uuid)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:2000']);

        $ledger = new ApprovalLedger();
        if ($ledger->forCandidateUuid($uuid) === null) {
            return response()->json(['error' => 'unknown candidate'], 404);
        }

        $approval = $ledger->revoke($uuid, (int) ($request->user()->id ?? 0),
            $this->actorName($request), $data['reason'] ?? null);

        return response()->json(['approval' => $this->approvalSummary($approval)]);
    }

    /**
     * Run the lifecycle.
     *
     * Queued, not synchronous: VERIFY runs the project's whole suite, which took
     * 405 seconds on the run that proved Sprint 7. A request that waited for it
     * would time out at the proxy and leave the caller unable to tell a slow run
     * from a dead one.
     */
    public function execute(Request $request, string $uuid)
    {
        $data = $request->validate(['dry_run' => 'nullable|boolean']);

        [$task, $project, $error] = $this->resolve($uuid);
        if ($error !== null) { return $error; }

        if (in_array($task->status, ['running'], true)) {
            return response()->json(['error' => 'this task is already running'], 409);
        }

        DB::table('engineering_tasks')->where('id', $task->id)
            ->update(['status' => 'running', 'updated_at' => now()]);

        Engineer888WorkflowJob::dispatch($task->uuid, (bool) ($data['dry_run'] ?? false),
            $this->actorName($request));

        return response()->json(['queued' => true, 'uuid' => $task->uuid]);
    }

    // ── manual recovery ─────────────────────────────────────────────────

    /**
     * The recovery candidate, rebuilt from disk right now.
     *
     * Rebuilt rather than replayed on purpose: the whole contract is that
     * an approval binds to the CURRENT state, so a stale screen must produce
     * a fingerprint that no longer matches.
     */
    public function recovery(Request $request, int $id)
    {
        $row = DB::table('engineering_recoveries')->find($id);
        if ($row === null) { return response()->json(['error' => 'unknown recovery'], 404); }

        $project = DB::table('engineering_projects')
            ->join('engineering_tasks', 'engineering_tasks.project_id', '=', 'engineering_projects.id')
            ->where('engineering_tasks.uuid', $row->task_uuid)
            ->first(['engineering_projects.repository_path']);

        $manual = new ManualRecovery($project->repository_path ?? base_path());
        $candidate = $manual->candidateFor($id);

        if ($candidate === null) { return response()->json(['error' => 'no candidate'], 422); }

        // Offering records it, so an approval has something stable to bind to.
        $offer = $manual->offer($id, $candidate);

        return response()->json([
            'recovery'  => ['id' => $row->id, 'status' => $row->status, 'task_uuid' => $row->task_uuid],
            'candidate' => $candidate->toArray(),
            'approval'  => ['status' => $offer->status, 'fingerprint' => $offer->fingerprint],
        ]);
    }

    public function approveRecovery(Request $request, string $uuid)
    {
        $data = $request->validate([
            'fingerprint' => 'required|string|size:64',
            'statement'   => 'required|string|max:500',
            'comment'     => 'nullable|string|max:2000',
        ]);

        try {
            $approval = (new ManualRecovery(base_path()))->approve(
                $uuid, $data['fingerprint'], $data['statement'],
                (int) ($request->user()->id ?? 0), $this->actorName($request),
                $data['comment'] ?? null
            );
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $approval->status, 'expires_at' => $approval->expires_at]);
    }

    public function rejectRecovery(Request $request, string $uuid)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:2000']);

        $approval = (new ManualRecovery(base_path()))->reject(
            $uuid, (int) ($request->user()->id ?? 0), $this->actorName($request), $data['reason'] ?? null
        );

        return response()->json(['status' => $approval->status]);
    }

    public function executeRecovery(Request $request, string $uuid)
    {
        $evidence = (new ManualRecovery(base_path()))->execute($uuid, $this->actorName($request));

        return response()->json($evidence, $evidence['status'] === ManualRecovery::EXECUTED ? 200 : 422);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array{0:?object,1:?object,2:mixed} */
    private function resolve(string $uuid): array
    {
        $task = DB::table('engineering_tasks')->where('uuid', $uuid)->first();
        if ($task === null) { return [null, null, response()->json(['error' => 'unknown task'], 404)]; }

        $project = DB::table('engineering_projects')->find($task->project_id);
        if ($project === null) { return [null, null, response()->json(['error' => 'task has no project'], 422)]; }

        return [$task, $project, null];
    }

    /**
     * The UI's stage vocabulary.
     *
     * REQUEST_APPROVAL blocking is not the same event as ANALYZE blocking, and
     * showing both as "BLOCKED" would hide the one state a human can act on.
     */
    private function stageState(string $stage, string $status): string
    {
        if ($status === 'blocked' && $stage === 'REQUEST_APPROVAL') { return 'AWAITING_APPROVAL'; }

        return match ($status) {
            'ok'      => 'PASSED',
            'blocked' => 'BLOCKED',
            'failed'  => 'FAILED',
            'skipped' => 'SKIPPED',
            default   => strtoupper($status),
        };
    }

    private function approvalStateFor($approvals): string
    {
        if ($approvals->isEmpty()) { return 'NONE'; }

        foreach ([ApprovalState::APPROVED, ApprovalState::PENDING] as $preferred) {
            if ($approvals->contains('state', $preferred)) { return $preferred; }
        }

        return (string) $approvals->last()->state;
    }

    private function approvalSummary(?object $approval): ?array
    {
        if ($approval === null) { return null; }

        return [
            'candidate_uuid' => $approval->candidate_uuid,
            'state'          => $approval->state,
            'fingerprint'    => $approval->fingerprint,
            'paths'          => json_decode((string) $approval->approved_paths, true) ?: [],
            'hashes'         => json_decode((string) $approval->approved_hashes, true) ?: [],
            'approver'       => $approval->approver_name,
            'statement'      => $approval->statement,
            'comment'        => $approval->comment,
            'approved_at'    => $approval->approved_at,
            'expires_at'     => $approval->expires_at,
            'revoked_by'     => $approval->revoked_by,
            'provider'       => $approval->provider,
            'model'          => $approval->model,
        ];
    }

    private function blockerFor(object $task): ?string
    {
        if (! in_array($task->status, ['blocked', 'failed'], true)) { return null; }

        $stage = DB::table('engineering_task_stages')->where('task_id', $task->id)
            ->whereIn('status', ['blocked', 'failed'])->orderByDesc('position')->first();

        return $stage === null ? null : $stage->stage . ': ' . $stage->summary;
    }

    /** Never just COMPLETED. Completion is reported with what proves it. */
    private function completionEvidence(object $task, $recorded): array
    {
        $verify = $recorded->get('VERIFY');
        $deploy = $recorded->get('DEPLOY_VERIFY');
        $implement = $recorded->get('IMPLEMENT');
        $promote = $recorded->get('PROMOTE');
        $learn = $recorded->get('LEARN');

        $verifyOut = $verify === null ? [] : (json_decode((string) $verify->outputs, true) ?: []);
        $suite = null;
        foreach ((array) ($verifyOut['checks'] ?? []) as $check) {
            if (str_contains((string) $check['check'], 'artisan test')) {
                if (preg_match('/Tests:\s+(.+)/', (string) $check['detail'], $m)) { $suite = trim($m[1]); }
            }
        }

        $implementOut = $implement === null ? [] : (json_decode((string) $implement->outputs, true) ?: []);
        $decisions = (array) ($implementOut['decisions'] ?? []);

        return [
            'status'        => $task->status,
            'files_changed' => array_column($decisions, 'destination'),
            'backups'       => array_values(array_filter(array_column($decisions, 'backup'))),
            'suite'         => $suite,
            'deploy_verdict' => $deploy === null ? null : $deploy->summary,
            'assets'        => $promote === null ? [] : ((json_decode((string) $promote->outputs, true) ?: [])['assets'] ?? []),
            'findings'      => $learn === null ? [] : ((json_decode((string) $learn->outputs, true) ?: [])['findings'] ?? []),
            'origin'        => $implementOut['origin'] ?? null,
            'verification'  => $verify === null ? null : [
                'adequacy' => ((json_decode((string) $verify->outputs, true) ?: [])['plan'] ?? [])['adequacy'] ?? null,
                'selected' => array_map(
                    fn ($c) => $c['check'] . ' — ' . $c['target'] . ' (' . $c['why'] . ')',
                    ((json_decode((string) $verify->outputs, true) ?: [])['plan'] ?? [])['checks'] ?? []),
                'excluded' => ((json_decode((string) $verify->outputs, true) ?: [])['plan'] ?? [])['excluded'] ?? [],
                'policy'   => ((json_decode((string) $verify->outputs, true) ?: [])['plan'] ?? [])['policy'] ?? [],
            ],
            'enforcement'   => $implementOut['enforcement'] ?? null,
            'duration_ms'   => $recorded->sum('duration_ms'),
        ];
    }

    /** @return array<string,mixed> */
    private function summariseOutputs(string $stage, array $outputs): array
    {
        // Stage outputs can carry whole file bodies. The timeline shows shape,
        // and the candidate screen shows content — sending both would make the
        // task page many megabytes.
        $trimmed = [];
        foreach ($outputs as $key => $value) {
            if (is_scalar($value) || $value === null) { $trimmed[$key] = $value; continue; }
            if (is_array($value)) {
                $trimmed[$key] = count($value) > 12
                    ? ['count' => count($value), 'sample' => array_slice($value, 0, 4)]
                    : $value;
            }
        }

        return $trimmed;
    }

    private function actorName(Request $request): string
    {
        $user = $request->user();
        if ($user === null) { return 'unknown admin'; }

        return trim(($user->name ?? '') . ' <' . ($user->email ?? '') . '>');
    }
}
