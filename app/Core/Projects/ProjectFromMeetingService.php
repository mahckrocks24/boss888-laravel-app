<?php

namespace App\Core\Projects;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProjectFromMeetingService — Strategy Room → Project handoff.
 *
 * Two-step ratification flow:
 *   1. ratify() — creates Project + Sarah proposes KPIs/milestones.
 *      Returns proposal for frontend to display + let user edit.
 *   2. persistProposal() — user clicks "Confirm", we insert the edited
 *      KPIs/milestones via the per-domain services.
 */
class ProjectFromMeetingService
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly SarahProjectPlanner $planner,
        private readonly MilestoneService $milestones,
        private readonly KpiService $kpis,
    ) {}

    /**
     * Ratify a meeting into a new Project + get Sarah's KPI/milestone proposal.
     *
     * Required:
     *   $wsId, $userId, $meetingId
     *
     * Optional opts:
     *   - duration_days   (int, default 30)
     *   - budget_credits  (int)
     *   - channels        (array of engine slugs)
     *   - kpi_count       (int 2-7, default 4)
     *   - milestone_count (int 2-7, default 5)
     *   - name_override   (string)  — override the auto-derived name
     *   - goal_override   (string)  — override the meeting goal
     */
    public function ratify(int $wsId, int $userId, int $meetingId, array $opts = []): array
    {
        $meeting = DB::table('meetings')
            ->where('id', $meetingId)
            ->where('workspace_id', $wsId)
            ->first();
        if (!$meeting) {
            return ['success' => false, 'error' => 'meeting not found in workspace'];
        }

        // Already ratified? Surface that explicitly rather than silently creating dupes.
        $existing = DB::table('projects')
            ->where('workspace_id', $wsId)
            ->where('source_type', 'strategy_room')
            ->where('source_meeting_id', $meetingId)
            ->whereNull('deleted_at')
            ->first(['id']);
        if ($existing) {
            return [
                'success'    => false,
                'error'      => 'meeting already ratified as project',
                'project_id' => $existing->id,
            ];
        }

        $meta = json_decode($meeting->metadata_json ?? '{}', true) ?: [];
        $goalText = (string) ($opts['goal_override'] ?? $meta['goal'] ?? '');
        if ($goalText === '') {
            return ['success' => false, 'error' => 'meeting has no goal — cannot ratify as project'];
        }

        $projectName = (string) ($opts['name_override'] ?? $meeting->title ?? '');
        if (mb_strlen($projectName) > 200) {
            $projectName = mb_substr($projectName, 0, 197) . '...';
        }
        if ($projectName === '') {
            $projectName = 'Project from meeting #' . $meetingId;
        }

        // Build enriched goal: meeting goal + tail of agent reasoning if available.
        // The planner's prompt benefits from seeing what the agents actually agreed.
        $enrichedGoal = $goalText;
        $synthesisFragments = $this->collectSynthesisFragments($meetingId);
        if ($synthesisFragments) {
            $enrichedGoal .= "\n\nAgents discussed:\n" . $synthesisFragments;
        }

        // ── Step 1: create the Project (source-tagged) ─────────────────
        $created = $this->projects->create($wsId, [
            'name'              => $projectName,
            'goal'              => $goalText, // store raw goal, not enriched
            'description'       => $this->buildDescription($meta, $synthesisFragments),
            'source_type'       => 'strategy_room',
            'source_meeting_id' => $meetingId,
            'owner_user_id'     => $userId,
            'budget_credits'    => isset($opts['budget_credits']) ? (int) $opts['budget_credits'] : null,
            'planned_end_at'    => isset($opts['duration_days'])
                ? now()->addDays((int) $opts['duration_days'])->toDateTimeString()
                : null,
            'metadata'          => [
                'ratified_at'      => now()->toIso8601String(),
                'ratified_by'      => $userId,
                'meeting_type'     => $meeting->type,
                'meeting_agents'   => $meta['agents'] ?? [],
                'has_meeting_plan' => !empty($meta['plan']),
            ],
        ]);
        if (empty($created['success'])) {
            return ['success' => false, 'error' => $created['error'] ?? 'project creation failed'];
        }
        $projectId = $created['project_id'];

        // ── Step 2: ask Sarah for proposal (enriched goal) ─────────────
        $proposalParams = [
            'goal'            => $enrichedGoal,
            'duration_days'   => (int) ($opts['duration_days']   ?? 30),
            'budget_credits'  => (int) ($opts['budget_credits']  ?? 0),
            'channels'        => $opts['channels']        ?? [],
            'kpi_count'       => (int) ($opts['kpi_count']       ?? 4),
            'milestone_count' => (int) ($opts['milestone_count'] ?? 5),
        ];
        $proposal = $this->planner->proposeForProject($wsId, $proposalParams);

        return [
            'success'      => true,
            'project_id'   => $projectId,
            'project_data' => $created['data'] ?? null,
            'proposal'     => $proposal,
            'meeting'      => [
                'id'    => $meetingId,
                'title' => $meeting->title,
                'type'  => $meeting->type,
            ],
        ];
    }

    /**
     * Persist a (possibly user-edited) proposal into project_milestones + project_kpis.
     *
     * Both arrays may be empty — caller can choose to persist only KPIs or only milestones.
     */
    public function persistProposal(int $wsId, int $projectId, array $kpis = [], array $milestones = []): array
    {
        $proj = DB::table('projects')
            ->where('id', $projectId)->where('workspace_id', $wsId)
            ->whereNull('deleted_at')->first(['id']);
        if (!$proj) return ['success' => false, 'error' => 'project not found in workspace'];

        $kpiResults       = [];
        $milestoneResults = [];
        $errors           = [];

        foreach ($kpis as $i => $k) {
            $res = $this->kpis->create($wsId, $projectId, [
                'name'         => $k['name']         ?? '',
                'description'  => $k['description']  ?? null,
                'target_value' => $k['target_value'] ?? null,
                'unit'         => $k['unit']         ?? null,
                'direction'    => $k['direction']    ?? 'higher_is_better',
                'order_index'  => $i,
                'metadata'     => [
                    'rationale' => $k['rationale']  ?? null,
                    'source'    => 'sarah_proposal',
                ],
                'measurement_source' => ['type' => 'manual'],
            ]);
            if (!empty($res['success'])) {
                $kpiResults[] = $res['kpi_id'];
            } else {
                $errors[] = "kpi[$i]: " . ($res['error'] ?? 'unknown');
            }
        }

        foreach ($milestones as $i => $m) {
            $offset = (int) ($m['target_date_offset_days'] ?? 0);
            $targetDate = $offset > 0
                ? now()->addDays($offset)->toDateTimeString()
                : null;
            $res = $this->milestones->create($wsId, $projectId, [
                'title'            => $m['title']            ?? '',
                'description'      => $m['description']      ?? null,
                'target_date'      => $targetDate,
                'success_criteria' => [
                    'type'        => 'manual',
                    'description' => $m['success_criteria'] ?? '',
                ],
                'order_index'      => $i,
                'notes'            => isset($m['rationale']) ? "Sarah's rationale: " . $m['rationale'] : null,
            ]);
            if (!empty($res['success'])) {
                $milestoneResults[] = $res['milestone_id'];
            } else {
                $errors[] = "milestone[$i]: " . ($res['error'] ?? 'unknown');
            }
        }

        return [
            'success'             => empty($errors),
            'kpis_created'        => count($kpiResults),
            'milestones_created'  => count($milestoneResults),
            'kpi_ids'             => $kpiResults,
            'milestone_ids'       => $milestoneResults,
            'errors'              => $errors,
        ];
    }

    /**
     * Collect the last 3-5 agent (non-user, non-system) messages as synthesis
     * fragments to feed into Sarah's planner. Keeps it under ~1000 chars total
     * so we don't blow the runtime prompt budget.
     */
    private function collectSynthesisFragments(int $meetingId): string
    {
        try {
            $rows = DB::table('meeting_messages')
                ->where('meeting_id', $meetingId)
                ->where('sender_type', 'agent')
                ->orderByDesc('id')
                ->limit(5)
                ->get(['message']);
        } catch (\Throwable $e) {
            return '';
        }

        if ($rows->isEmpty()) return '';

        $pieces = [];
        $total = 0;
        foreach ($rows->reverse() as $r) {
            $msg = trim(strip_tags((string) ($r->message ?? '')));
            if ($msg === '') continue;
            // Cap each fragment at 220 chars
            if (mb_strlen($msg) > 220) $msg = mb_substr($msg, 0, 217) . '...';
            $pieces[] = '- ' . $msg;
            $total += mb_strlen($msg);
            if ($total > 900) break;
        }
        return implode("\n", $pieces);
    }

    private function buildDescription(array $meta, string $synthesis): string
    {
        $lines = [];
        if (!empty($meta['goal'])) $lines[] = "Original goal: " . $meta['goal'];
        if (!empty($meta['agents']) && is_array($meta['agents'])) {
            $lines[] = "Agents involved: " . implode(', ', $meta['agents']);
        }
        if ($synthesis) $lines[] = "Meeting synthesis (excerpt):\n" . $synthesis;
        return implode("\n\n", $lines) ?: 'Ratified from Strategy Room meeting.';
    }
}