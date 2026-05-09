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

            $interpretation = $this->interpretResult(
                $row->engine . '/' . $row->action,
                $result,
                $agentName
            );

            $insights[] = [
                'task_id'        => $row->id,
                'agent_slug'     => $agentSlug,
                'agent_name'     => $agentName,
                'engine'         => $row->engine,
                'action'         => $row->action,
                'completed_at'   => $row->completed_at,
                'interpretation' => $interpretation,
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
            $lines[] = "- {$i['agent_name']} finished {$i['engine']}/{$i['action']} (task #{$i['task_id']}): {$i['interpretation']}";
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
    private function interpretResult(string $taskKey, array $result, string $agentName): string
    {
        $runtime = app(RuntimeClient::class);
        if (!$runtime->isConfigured()) {
            return "{$agentName} completed {$taskKey}.";
        }

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($resultJson) > 4000) {
            $resultJson = substr($resultJson, 0, 4000) . '... (truncated)';
        }

        $system = "You are Sarah, the Digital Marketing Manager. Interpret an agent's task result in 2-3 sentences max. Be direct and actionable. Output JSON: {\"reply\":\"<2-3 sentences>\"}.";
        $user = "Agent {$agentName} just completed task `{$taskKey}`. Result JSON:\n{$resultJson}\n\nWhat does this mean for the business and what should happen next?";

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
        return "{$agentName} completed {$taskKey}.";
    }
}
