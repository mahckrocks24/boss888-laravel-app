<?php

namespace App\Core\Orchestration;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SarahReadBackService — closes the Sarah → task → result → Sarah loop.
 *
 * After a delegated task completes, Sarah reads the `result_json`, asks the
 * runtime to interpret it in 2-3 sentences, and marks the task with
 * `sarah_read_at`. The interpretation is surfaced inline in subsequent
 * Sarah chat replies (system prompt prefix) and in proactive checks.
 *
 * Schema notes:
 *   - tasks has no `agent_id` column; assignees live in `assigned_agents_json`.
 *   - tasks has no `type` column; the action is `engine` + `action`.
 *
 * Patched 2026-05-10 (Phase 2 — execution verification loop).
 */
class SarahReadBackService
{
    /**
     * Returns at most $limit recently-completed tasks Sarah has not yet read,
     * each with an LLM-generated interpretation. Marks them as read by
     * stamping `sarah_read_at = NOW()` so the same task is not re-processed.
     */
    public function checkCompletedTasks(int $wsId, int $limit = 5): array
    {
        $rows = DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->where('status', 'completed')
            ->whereNotNull('result_json')
            ->whereNull('sarah_read_at')
            ->orderBy('completed_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $insights = [];
        foreach ($rows as $row) {
            $result = json_decode((string)$row->result_json, true) ?: [];
            $assignees = json_decode((string)$row->assigned_agents_json, true) ?: [];
            $agentSlug = $assignees[0] ?? 'agent';
            $agentName = $this->resolveAgentName($agentSlug);

            // 2026-06-20 (forensic: Chef Red orphan loop) — a task can complete
            // having changed nothing (fix_orphans applied:0, insert_link
            // inserted_count:0). The envelope flags it via `no_change`; honor it
            // so Sarah never narrates a no-op as "done".
            $data = (array) ($result['data'] ?? []);
            $noChange = ! empty($result['no_change'])
                || (array_key_exists('changed', $data) && $data['changed'] === false)
                || (array_key_exists('changed', $result) && $result['changed'] === false);

            $interpretation = $this->interpretResult(
                $row->engine . '/' . $row->action,
                $result,
                $agentName,
                $noChange
            );

            $insights[] = [
                'task_id'        => $row->id,
                'agent_slug'     => $agentSlug,
                'agent_name'     => $agentName,
                'engine'         => $row->engine,
                'action'         => $row->action,
                'completed_at'   => $row->completed_at,
                'interpretation' => $interpretation,
                'no_change'      => $noChange,
            ];

            // PATCH (Phase 2H, 2026-05-10) — push the interpreted result
            // into the cross-agent knowledge base so other specialists pick
            // it up in their next system prompt. 30-day TTL by default.
            try {
                app(\App\Core\Intelligence\WorkspaceKnowledgeBase::class)->store(
                    $wsId,
                    $agentSlug,
                    $row->engine . '_result',
                    "{$agentName} completed {$row->engine}/{$row->action} (task #{$row->id})",
                    [
                        'task_id'        => $row->id,
                        'engine'         => $row->engine,
                        'action'         => $row->action,
                        'result'         => $result,
                        'interpretation' => $interpretation,
                        'completed_at'   => $row->completed_at,
                    ],
                    30
                );
            } catch (\Throwable $kbErr) {
                Log::warning("SarahReadBackService::checkCompletedTasks KB store failed for task {$row->id}: " . $kbErr->getMessage());
            }

            DB::table('tasks')->where('id', $row->id)->update(['sarah_read_at' => now()]);
        }

        return $insights;
    }

    /**
     * Renders insights as a system-prompt block. Empty string when no
     * unread tasks — caller can concatenate unconditionally.
     */
    public function renderInsightsBlock(int $wsId, int $limit = 5): string
    {
        $insights = $this->checkCompletedTasks($wsId, $limit);
        if (empty($insights)) {
            return '';
        }
        $lines = ['COMPLETED SINCE LAST CHECK (acknowledge naturally if relevant to the user message):'];
        foreach ($insights as $i) {
            if (! empty($i['no_change'])) {
                $lines[] = "- (!) {$i['agent_name']} ran {$i['engine']}/{$i['action']} (task #{$i['task_id']}) but it CHANGED NOTHING — do NOT tell the user it is fixed; be honest and offer the real next step: {$i['interpretation']}";
            } else {
                $lines[] = "- {$i['agent_name']} finished {$i['engine']}/{$i['action']} (task #{$i['task_id']}): {$i['interpretation']}";
            }
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    private function resolveAgentName(string $slug): string
    {
        $row = DB::table('agents')->where('slug', $slug)->first();
        return $row->name ?? ucfirst($slug);
    }

    /**
     * One LLM round-trip per task. Falls back to a templated string if the
     * runtime is unreachable so the rest of the chat flow still works.
     */
    private function interpretResult(string $taskKey, array $result, string $agentName, bool $noChange = false): string
    {
        $runtime = app(RuntimeClient::class);
        if (!$runtime->isConfigured()) {
            return $noChange
                ? "{$agentName} ran {$taskKey} but it changed nothing — it needs a different approach."
                : "{$agentName} completed {$taskKey}.";
        }

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($resultJson) > 4000) {
            $resultJson = substr($resultJson, 0, 4000) . '... (truncated)';
        }

        $system = "You are Sarah, the Digital Marketing Manager. Interpret an agent's task result in 2-3 sentences max. Be direct and actionable. NEVER claim something was done, fixed, or improved if the result shows no change (e.g. applied:0, inserted_count:0, changed:false) — in that case say plainly that it did NOT change anything, why, and the real next step. Output JSON: {\"reply\":\"<2-3 sentences>\"}.";
        $statusLine = $noChange ? "IMPORTANT: this task RAN but CHANGED NOTHING — do not imply success." : "";
        $user = "Agent {$agentName} just completed task `{$taskKey}`. {$statusLine} Result JSON:\n{$resultJson}\n\nWhat does this mean for the business and what should happen next?";

        try {
            $resp = $runtime->chatJson($system, $user, [], 400);
            if (($resp['success'] ?? false)) {
                $parsed = $resp['parsed'] ?? [];
                $reply = $parsed['reply'] ?? $resp['text'] ?? '';
                if ($reply) {
                    return trim($reply);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("SarahReadBackService::interpretResult failed for {$taskKey}: " . $e->getMessage());
        }
        return $noChange
            ? "{$agentName} ran {$taskKey} but it changed nothing — it needs a different approach."
            : "{$agentName} completed {$taskKey}.";
    }
}
