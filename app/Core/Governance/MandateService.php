<?php

namespace App\Core\Governance;

use App\Core\Intelligence\ToolCostCalculatorService;
use App\Core\Orchestration\AgentMeetingEngine;
use App\Core\TaskSystem\TaskService;
use App\Models\Meeting;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MANDATE-1 — the Plan of Action as an approved mandate (DEC-0018, SPEC-0023 §3/§5/§6).
 *
 * The customer approves INTENT AND SCOPE ONCE, at the level of the plan. The tasks the plan contains are
 * created only when the plan is approved, carry the plan's reference, need no approval of their own, and
 * run only while the plan is LIVE. Sarah owns tactics and execution inside the plan (tier 1: not checked).
 *
 * The gate is ordinary: a task `sarah/execute_plan` that requires approval, so the plan appears in the same
 * queue as everything else ("Needs your OK", Basic and Advanced), is approved or rejected through the same
 * endpoints, and is dispatched by the same worker. When it runs, execute() materialises the plan's tasks.
 *
 * Hard boundaries (tier 2, SPEC-0023 §5 — SHORT and declared, never derived): a SPEND CEILING in credits
 * shown at approval; VALIDITY (a plan approved today does not run forever); a DESTINATION the customer named.
 * Missing targets are not approvals — a task whose target the plan could not name is HELD and reported.
 */
final class MandateService
{
    public const STATUS_PROPOSED   = 'proposed';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_LIVE       = 'live';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_DECLINED   = 'declined';
    public const STATUS_REVOKED    = 'revoked';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED    = 'expired';

    public const GATE_ENGINE = 'sarah';
    public const GATE_ACTION = 'execute_plan';

    /** Validity window granted at approval (SPEC-0023: every mandate is bounded). */
    public const VALIDITY_DAYS = 90;
    /** The ceiling is the estimate the customer saw, with the estimator's own slack. */
    public const CEILING_SLACK = 1.25;

    private const OPEN_TASK = ['pending', 'awaiting_approval', 'queued', 'blocked', 'running', 'verifying'];

    /** Propose a plan from a closed strategy meeting: one mandate, one gate task, one approval. Idempotent per meeting. */
    public function proposeFromMeeting(Meeting $meeting, array $plan, array $opts = []): array
    {
        $existing = DB::table('mandates')->where('source_type', 'meeting')->where('source_id', (int) $meeting->id)->first();
        if ($existing) {
            return ['mandate_id' => (int) $existing->id, 'task_id' => $existing->task_id ? (int) $existing->task_id : null,
                'approval_id' => $existing->approval_id ? (int) $existing->approval_id : null, 'created' => false, 'status' => (string) $existing->status];
        }
        $meta = json_decode((string) ($meeting->metadata_json ?? '{}'), true) ?: [];
        $title = trim((string) ($opts['title'] ?? $meeting->title ?? 'Plan of action')) ?: 'Plan of action';
        $objective = trim((string) ($opts['objective'] ?? $meta['goal'] ?? ''));
        $strategy = $this->latestSynthesis((int) $meeting->id);

        return $this->propose((int) $meeting->workspace_id, [
            'business_id'  => $meeting->business_id ?? null,
            'source_type'  => 'meeting',
            'source_id'    => (int) $meeting->id,
            'title'        => mb_substr($title, 0, 255),
            'objective'    => $objective,
            'strategy'     => $strategy,
            'tasks'        => $plan,
            'proposed_by'  => $meeting->created_by ?? null,
            'destinations' => (array) ($opts['destinations'] ?? []),
            'from_meeting' => (int) $meeting->id,
        ]);
    }

    /** The general proposer: a frozen task list becomes ONE approval. Sarah's own plans can use it. */
    public function propose(int $wsId, array $spec): array
    {
        $tasks = $this->normaliseTasks((array) ($spec['tasks'] ?? []));
        if (! $tasks) {
            throw new \InvalidArgumentException('A plan needs at least one task.');
        }
        $estimate = $this->estimateCredits($tasks);
        $ceiling  = (int) ($spec['spend_ceiling_credits'] ?? max(1, (int) ceil($estimate * self::CEILING_SLACK)));
        $validity = (int) ($spec['validity_days'] ?? self::VALIDITY_DAYS);
        $boundaries = [
            'spend_ceiling_credits' => $ceiling,
            'validity_days'         => $validity,
            'destinations'          => array_values(array_filter(array_map('strval', (array) ($spec['destinations'] ?? [])))),
        ];
        $now = now();

        $mandateId = (int) DB::table('mandates')->insertGetId([
            'workspace_id'    => $wsId,
            'business_id'     => $spec['business_id'] ?? null,
            'kind'            => ! empty($spec['supersedes_id']) ? 'revision' : 'plan',
            'source_type'     => $spec['source_type'] ?? 'sarah',
            'source_id'       => $spec['source_id'] ?? null,
            'title'           => mb_substr((string) ($spec['title'] ?? 'Plan of action'), 0, 255),
            'objective'       => $spec['objective'] ?? null,
            'strategy_text'   => $spec['strategy'] ?? null,
            'tasks_json'      => json_encode($tasks),
            'boundaries_json' => json_encode($boundaries),
            'credit_estimate' => $estimate,
            'status'          => self::STATUS_PROPOSED,
            'proposed_by'     => $spec['proposed_by'] ?? null,
            'supersedes_id'   => $spec['supersedes_id'] ?? null,
            'meta_json'       => json_encode(['held' => []]),
            'created_at'      => $now, 'updated_at' => $now,
        ]);

        $summaryLines = array_map(fn ($t) => ($t['description'] ?: ucfirst(str_replace('_', ' ', $t['action']))) . ' (' . $t['agent'] . ')', $tasks);
        $payload = [
            'mandate_id'        => $mandateId,
            'title'             => (string) ($spec['title'] ?? 'Plan of action'),
            'objective'         => (string) ($spec['objective'] ?? ''),
            'description'       => 'Plan of action: ' . (string) ($spec['title'] ?? 'Plan of action') . ' — ' . count($tasks) . ' task' . (count($tasks) === 1 ? '' : 's') . ', up to ' . $ceiling . ' credit' . ($ceiling === 1 ? '' : 's'),
            'tasks'             => $tasks,
            'task_lines'        => $summaryLines,
            'credit_estimate'   => ['known' => true, 'credits' => $ceiling, 'estimate' => $estimate],
            'spend_ceiling'     => $ceiling,
            'validity_days'     => $validity,
            'from_meeting'      => $spec['from_meeting'] ?? null,
        ];

        $gate = app(TaskService::class)->create($wsId, [
            'engine'            => self::GATE_ENGINE,
            'action'            => self::GATE_ACTION,
            'source'            => 'agent',
            'priority'          => 'normal',
            'assigned_agents'   => ['sarah'],
            'requires_approval' => true,
            'credit_cost'       => 0,                       // the plan's tasks reserve their own credits when they run
            'user_id'           => $spec['proposed_by'] ?? null,
            'business_id'       => $spec['business_id'] ?? null,
            'idempotency_key'   => hash('sha256', "{$wsId}:mandate:{$mandateId}:gate"),
            'payload'           => $payload,
        ]);
        $approvalId = DB::table('approvals')->where('task_id', (int) $gate->id)->value('id');
        DB::table('tasks')->where('id', (int) $gate->id)->update(['mandate_id' => $mandateId, 'progress_message' => $payload['description']]);
        DB::table('mandates')->where('id', $mandateId)->update(['task_id' => (int) $gate->id, 'approval_id' => $approvalId ? (int) $approvalId : null, 'updated_at' => now()]);

        Log::info('[Mandate] proposed', ['workspace_id' => $wsId, 'mandate_id' => $mandateId, 'tasks' => count($tasks), 'estimate' => $estimate, 'ceiling' => $ceiling]);
        return ['mandate_id' => $mandateId, 'task_id' => (int) $gate->id, 'approval_id' => $approvalId ? (int) $approvalId : null, 'created' => true, 'status' => self::STATUS_PROPOSED];
    }

    /**
     * The gate task ran: the customer approved the plan. Materialise its tasks under the mandate.
     * Called by the Orchestrator for sarah/execute_plan. Idempotent — a second run reports, it does not duplicate.
     */
    public function execute(int $mandateId, Task $gate): array
    {
        $m = DB::table('mandates')->where('id', $mandateId)->first();
        if (! $m) throw new \RuntimeException("Mandate #{$mandateId} not found.");
        if ((int) $m->workspace_id !== (int) $gate->workspace_id) throw new \RuntimeException('Mandate belongs to another workspace.');
        if (in_array((string) $m->status, [self::STATUS_LIVE, self::STATUS_COMPLETED], true)) {
            return $this->summaryRow($m) + ['already' => true];
        }
        if (in_array((string) $m->status, [self::STATUS_DECLINED, self::STATUS_REVOKED, self::STATUS_SUPERSEDED, self::STATUS_EXPIRED], true)) {
            throw new \RuntimeException("This plan is {$m->status}; it cannot run.");
        }
        if ((string) $gate->approval_status !== 'approved') {
            throw new \RuntimeException('The plan has not been approved — nothing runs without the customer\'s decision.');
        }
        $approval = DB::table('approvals')->where('task_id', (int) $gate->id)->orderByDesc('id')->first(['id', 'decision_by', 'decided_at', 'decision_note']);
        $now = now();
        $boundaries = json_decode((string) $m->boundaries_json, true) ?: [];
        $validity = (int) ($boundaries['validity_days'] ?? self::VALIDITY_DAYS);
        DB::table('mandates')->where('id', $mandateId)->update([
            'status' => self::STATUS_APPROVED, 'decided_by' => $approval->decision_by ?? null,
            'decided_at' => $approval->decided_at ?? $now, 'approval_id' => $approval->id ?? $m->approval_id,
            'starts_at' => $now, 'ends_at' => $now->copy()->addDays(max(1, $validity)), 'updated_at' => $now,
        ]);
        if (! empty($m->supersedes_id)) {
            $this->supersede((int) $m->supersedes_id, $mandateId);
        }

        // CAMPAIGNS-1 (RFC-0018): a campaign's plan is approved once; its dated items are released on their dates by
        // CampaignService::tick under this mandate (same boundaries, same liveness), not all at once.
        if ((string) $m->source_type === 'campaign' && $m->source_id) {
            DB::table('mandates')->where('id', $mandateId)->update(['status' => self::STATUS_LIVE, 'executed_at' => now(), 'updated_at' => now()]);
            app(\App\Core\Campaigns\CampaignService::class)->onMandateLive((int) $m->source_id, $mandateId);
            $fresh = DB::table('mandates')->where('id', $mandateId)->first();
            return $this->summaryRow($fresh) + ['created' => 0, 'held' => [], 'task_ids' => [], 'campaign_id' => (int) $m->source_id];
        }
        $tasks = json_decode((string) $m->tasks_json, true) ?: [];
        $ceiling = (int) ($boundaries['spend_ceiling_credits'] ?? PHP_INT_MAX);
        $created = []; $held = []; $spent = 0; $byIndex = [];
        $engine = app(AgentMeetingEngine::class);
        $meeting = ((string) $m->source_type === 'meeting' && $m->source_id) ? Meeting::find((int) $m->source_id) : null;
        foreach ($tasks as $i => $planTask) {
            $cost = $this->estimateCredits([$planTask]);
            if ($spent + $cost > $ceiling) {
                $held[] = ['index' => $i, 'action' => $planTask['action'], 'reason' => 'spend_ceiling', 'note' => "Would take the plan past its " . $ceiling . "-credit ceiling"];
                continue;
            }
            $overrides = [
                'requires_approval'      => false,           // DEC-0018: the plan's approval is the task's approval
                'authorized_by_proposal' => true,
                'auto_approve'           => true,
                'mandate_id'             => $mandateId,
                'business_id'            => $m->business_id,
                'decided_by'             => $approval->decision_by ?? null,
                'payload_extra'          => ['_mandate_id' => $mandateId, 'from_plan' => $m->title],
            ];
            // PLAN-CHECK-1: a task planned to run after another waits for it (parent_task_id) and inherits its article.
            if (isset($planTask['after']) && is_int($planTask['after'])) {
                if (empty($byIndex[$planTask['after']])) {
                    $held[] = ['index' => $i, 'action' => $planTask['action'], 'reason' => 'needs_earlier_task', 'note' => 'Depends on a step that did not start'];
                    continue;
                }
                $overrides['parent_task_id'] = $byIndex[$planTask['after']];
            }
            $res = $engine->materialisePlanTask((int) $m->workspace_id, $planTask, $overrides, $meeting);
            if (($res['held'] ?? null) !== null) { $held[] = ['index' => $i, 'action' => $planTask['action'], 'reason' => 'needs_target', 'note' => (string) $res['held']]; continue; }
            if (empty($res['task_id'])) { $held[] = ['index' => $i, 'action' => $planTask['action'], 'reason' => 'create_failed', 'note' => (string) ($res['error'] ?? 'could not be created')]; continue; }
            $created[] = (int) $res['task_id'];
            $byIndex[$i] = (int) $res['task_id'];
            if (! empty($res['kept_gate'])) $held[] = ['index' => $i, 'action' => $planTask['action'], 'reason' => 'protected_capability', 'note' => 'A protected action keeps its own approval (task #' . (int) $res['task_id'] . ')'];
            $spent += $cost;
        }
        $meta = json_decode((string) ($m->meta_json ?? '{}'), true) ?: [];
        $meta['held'] = $held; $meta['created_task_ids'] = $created; $meta['decision_note'] = $approval->decision_note ?? null;
        DB::table('mandates')->where('id', $mandateId)->update([
            'status' => self::STATUS_LIVE, 'executed_at' => now(), 'meta_json' => json_encode($meta), 'updated_at' => now(),
        ]);
        Log::info('[Mandate] live', ['mandate_id' => $mandateId, 'created' => count($created), 'held' => count($held)]);
        $fresh = DB::table('mandates')->where('id', $mandateId)->first();
        return $this->summaryRow($fresh) + ['created' => count($created), 'held' => $held, 'task_ids' => $created];
    }

    /** The customer rejected the gate task (ApprovalService::reject hook). */
    public function declined(Task $gate, ?int $userId, ?string $note): void
    {
        $mandateId = (int) ($gate->mandate_id ?? 0) ?: (int) (($gate->payload_json['mandate_id'] ?? 0));
        if ($mandateId <= 0) return;
        $m = DB::table('mandates')->where('id', $mandateId)->first(['id', 'status', 'meta_json']);
        if (! $m || (string) $m->status !== self::STATUS_PROPOSED) return;
        $meta = json_decode((string) ($m->meta_json ?? '{}'), true) ?: []; $meta['decision_note'] = $note;
        DB::table('mandates')->where('id', $mandateId)->update([
            'status' => self::STATUS_DECLINED, 'decided_by' => $userId, 'decided_at' => now(), 'meta_json' => json_encode($meta), 'updated_at' => now(),
        ]);
        Log::info('[Mandate] declined', ['mandate_id' => $mandateId, 'by' => $userId]);
    }

    /**
     * SPEC-0023 §6 — evaluated at EXECUTION, not at creation: is the mandate this task descends from still live,
     * and does this task fit inside its spend ceiling?
     */
    public function liveness(Task $task, ?int $mandateId = null): array
    {
        $mandateId = $mandateId ?: ((int) ($task->mandate_id ?? 0) ?: (int) (($task->payload_json['_mandate_id'] ?? 0)));
        if ($mandateId <= 0) return ['ok' => true, 'reason' => ''];
        $m = DB::table('mandates')->where('id', $mandateId)->first();
        if (! $m) return ['ok' => false, 'reason' => 'This task descends from a plan that no longer exists.'];
        if ((int) $m->workspace_id !== (int) $task->workspace_id) return ['ok' => false, 'reason' => 'This task descends from a plan in another workspace.'];
        $status = (string) $m->status;
        if (in_array($status, [self::STATUS_DECLINED, self::STATUS_REVOKED, self::STATUS_SUPERSEDED, self::STATUS_EXPIRED], true)) {
            return ['ok' => false, 'reason' => "The plan this task belongs to is {$status}; nothing under it runs."];
        }
        if (! in_array($status, [self::STATUS_APPROVED, self::STATUS_LIVE, self::STATUS_COMPLETED], true)) {
            return ['ok' => false, 'reason' => 'The plan this task belongs to has not been approved yet.'];
        }
        if ($m->ends_at && now()->greaterThan(\Carbon\Carbon::parse($m->ends_at))) {
            DB::table('mandates')->where('id', $mandateId)->update(['status' => self::STATUS_EXPIRED, 'updated_at' => now()]);
            return ['ok' => false, 'reason' => 'The plan this task belongs to expired on ' . \Carbon\Carbon::parse($m->ends_at)->toDateString() . '; approve a new plan to continue.'];
        }
        $boundaries = json_decode((string) $m->boundaries_json, true) ?: [];
        $ceiling = (int) ($boundaries['spend_ceiling_credits'] ?? 0);
        if ($ceiling > 0) {
            $spent = (int) DB::table('tasks')->where('mandate_id', $mandateId)->where('id', '!=', (int) $task->id)
                ->whereNotIn('status', ['cancelled', 'failed', 'blocked'])->where('action', '!=', self::GATE_ACTION)->sum('credit_cost');
            if ($spent + (int) ($task->credit_cost ?? 0) > $ceiling) {
                return ['ok' => false, 'reason' => "This task would take the plan past its {$ceiling}-credit ceiling ({$spent} already committed)."];
            }
        }
        return ['ok' => true, 'reason' => ''];
    }

    /** The customer stops a plan: nothing pending under it runs again. */
    public function revoke(int $wsId, int $mandateId, ?int $userId, string $reason = ''): array
    {
        $m = DB::table('mandates')->where('id', $mandateId)->where('workspace_id', $wsId)->first();
        if (! $m) throw new \RuntimeException('Plan not found.');
        if (in_array((string) $m->status, [self::STATUS_DECLINED, self::STATUS_REVOKED, self::STATUS_SUPERSEDED], true)) {
            return $this->summaryRow($m) + ['already' => true, 'cancelled' => 0];
        }
        $cancelled = $this->cancelOpenChildren($mandateId, 'Plan stopped by the customer' . ($reason !== '' ? ': ' . $reason : ''));
        if ((string) $m->status === self::STATUS_PROPOSED && $m->task_id) {
            DB::table('tasks')->where('id', (int) $m->task_id)->whereIn('status', self::OPEN_TASK)->update(['status' => 'cancelled', 'approval_status' => 'rejected', 'cancelled_at' => now(), 'error_text' => 'Plan withdrawn', 'updated_at' => now()]);
            DB::table('approvals')->where('task_id', (int) $m->task_id)->where('status', 'pending')->update(['status' => 'expired', 'decision_by' => $userId, 'decided_at' => now(), 'decision_note' => $reason ?: 'withdrawn', 'updated_at' => now()]);
        }
        $meta = json_decode((string) ($m->meta_json ?? '{}'), true) ?: []; $meta['revoke_reason'] = $reason;
        DB::table('mandates')->where('id', $mandateId)->update(['status' => self::STATUS_REVOKED, 'decided_by' => $userId, 'meta_json' => json_encode($meta), 'updated_at' => now()]);
        Log::info('[Mandate] revoked', ['mandate_id' => $mandateId, 'by' => $userId, 'cancelled' => $cancelled]);
        $fresh = DB::table('mandates')->where('id', $mandateId)->first();
        return $this->summaryRow($fresh) + ['cancelled' => $cancelled];
    }

    /** A revised plan went live: the plan it replaces stops authorising (SPEC-0023 §3, §7). */
    public function supersede(int $oldId, int $byId): int
    {
        $old = DB::table('mandates')->where('id', $oldId)->first(['id', 'status']);
        if (! $old || in_array((string) $old->status, [self::STATUS_DECLINED, self::STATUS_REVOKED, self::STATUS_SUPERSEDED], true)) return 0;
        $n = $this->cancelOpenChildren($oldId, "Superseded by plan #{$byId}");
        DB::table('mandates')->where('id', $oldId)->update(['status' => self::STATUS_SUPERSEDED, 'updated_at' => now()]);
        return $n;
    }

    /** Called when a child completes/fails: a plan with nothing open is completed. */
    public function settleIfFinished(int $mandateId): void
    {
        $m = DB::table('mandates')->where('id', $mandateId)->first(['id', 'status', 'source_type', 'source_id']);
        if (! $m || (string) $m->status !== self::STATUS_LIVE) return;
        // CAMPAIGNS-1: a campaign's plan stays live until the campaign itself finishes
        if ((string) $m->source_type === 'campaign' && DB::table('marketing_campaigns')->where('id', (int) $m->source_id)->whereNotIn('status', ['completed', 'archived'])->exists()) return;
        $open = DB::table('tasks')->where('mandate_id', $mandateId)->where('action', '!=', self::GATE_ACTION)->whereIn('status', self::OPEN_TASK)->exists();
        if (! $open) DB::table('mandates')->where('id', $mandateId)->update(['status' => self::STATUS_COMPLETED, 'completed_at' => now(), 'updated_at' => now()]);
    }

    public function show(int $wsId, int $mandateId): ?array
    {
        $m = DB::table('mandates')->where('id', $mandateId)->where('workspace_id', $wsId)->first();
        return $m ? $this->summaryRow($m, true) : null;
    }

    public function forMeeting(int $wsId, int $meetingId): ?array
    {
        $m = DB::table('mandates')->where('workspace_id', $wsId)->where('source_type', 'meeting')->where('source_id', $meetingId)->orderByDesc('id')->first();
        return $m ? $this->summaryRow($m, true) : null;
    }

    public function list(int $wsId, ?string $status = null, int $limit = 50): array
    {
        $q = DB::table('mandates')->where('workspace_id', $wsId)->orderByDesc('id')->limit(max(1, min(200, $limit)));
        if ($status) $q->where('status', $status);
        return $q->get()->map(fn ($m) => $this->summaryRow($m))->values()->all();
    }

    // ── internals ──────────────────────────────────────────────────────────────────────────────────────────

    private function summaryRow(object $m, bool $withTasks = false): array
    {
        $boundaries = json_decode((string) $m->boundaries_json, true) ?: [];
        $meta = json_decode((string) ($m->meta_json ?? '{}'), true) ?: [];
        $plan = json_decode((string) $m->tasks_json, true) ?: [];
        $out = [
            'id' => (int) $m->id, 'workspace_id' => (int) $m->workspace_id, 'business_id' => $m->business_id ? (int) $m->business_id : null,
            'kind' => (string) $m->kind, 'source_type' => $m->source_type, 'source_id' => $m->source_id ? (int) $m->source_id : null,
            'title' => (string) $m->title, 'objective' => $m->objective, 'strategy' => $m->strategy_text,
            'status' => (string) $m->status, 'credit_estimate' => (int) $m->credit_estimate,
            'spend_ceiling' => (int) ($boundaries['spend_ceiling_credits'] ?? 0), 'validity_days' => (int) ($boundaries['validity_days'] ?? 0),
            'destinations' => $boundaries['destinations'] ?? [],
            'task_count' => count($plan), 'gate_task_id' => $m->task_id ? (int) $m->task_id : null, 'approval_id' => $m->approval_id ? (int) $m->approval_id : null,
            'proposed_by' => $m->proposed_by ? (int) $m->proposed_by : null, 'decided_by' => $m->decided_by ? (int) $m->decided_by : null,
            'decided_at' => $m->decided_at, 'starts_at' => $m->starts_at, 'ends_at' => $m->ends_at, 'executed_at' => $m->executed_at,
            'supersedes_id' => $m->supersedes_id ? (int) $m->supersedes_id : null, 'held' => $meta['held'] ?? [], 'created_at' => $m->created_at,
            'plan' => array_map(fn ($t) => ['engine' => $t['engine'], 'action' => $t['action'], 'agent' => $t['agent'], 'description' => $t['description']], $plan),
        ];
        if ($withTasks) {
            $out['tasks'] = DB::table('tasks')->where('mandate_id', (int) $m->id)->where('action', '!=', self::GATE_ACTION)->orderBy('id')
                ->get(['id', 'engine', 'action', 'status', 'credit_cost', 'progress_message', 'completed_at', 'error_text'])->map(fn ($t) => (array) $t)->all();
            $out['credits_committed'] = (int) DB::table('tasks')->where('mandate_id', (int) $m->id)->where('action', '!=', self::GATE_ACTION)
                ->whereNotIn('status', ['cancelled', 'failed', 'blocked'])->sum('credit_cost');
        }
        return $out;
    }

    private function normaliseTasks(array $plan): array
    {
        $out = [];
        foreach ($plan as $t) {
            if (! is_array($t) || empty($t['action'])) continue;
            $agent = (string) ($t['agent'] ?? 'sarah'); if ($agent === 'dmm') $agent = 'sarah';
            $out[] = [
                'engine'      => (string) ($t['engine'] ?? 'marketing'),
                'action'      => (string) $t['action'],
                'agent'       => $agent,
                'category'    => isset($t['category']) && is_string($t['category']) ? $t['category'] : null,
                'description' => mb_substr(trim((string) ($t['description'] ?? '')), 0, 500),
                'priority'    => (string) ($t['priority'] ?? 'normal'),
                'params'      => (isset($t['params']) && is_array($t['params'])) ? $t['params'] : [],
            ] + ((isset($t['after']) && is_int($t['after'])) ? ['after' => $t['after']] : []);   // PLAN-CHECK-1: runs after that task
        }
        return $out;
    }

    private function estimateCredits(array $tasks): int
    {
        try {
            $b = app(ToolCostCalculatorService::class)->estimate(array_map(fn ($t) => ['engine' => $t['engine'], 'action' => $t['action']], $tasks));
            return (int) ($b['total'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function latestSynthesis(int $meetingId): ?string
    {
        try {
            $row = DB::table('meeting_messages')->where('meeting_id', $meetingId)->where('phase', 'synthesis')->orderByDesc('id')->first(['message']);
            return $row ? mb_substr((string) $row->message, 0, 4000) : null;
        } catch (\Throwable $e) { return null; }
    }

    private function cancelOpenChildren(int $mandateId, string $why): int
    {
        return (int) DB::table('tasks')->where('mandate_id', $mandateId)->where('action', '!=', self::GATE_ACTION)
            ->whereIn('status', self::OPEN_TASK)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'error_text' => $why, 'updated_at' => now()]);
    }
}
