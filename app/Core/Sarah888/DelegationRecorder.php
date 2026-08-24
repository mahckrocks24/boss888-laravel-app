<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1F slice 1F.1 — verified multi-agent delegation.
 *
 * Traces to F1-D06. Sarah repeatedly invented agreement to add weight to a
 * claim: "James, Priya, and Elena all confirm the same", "My whole team read it
 * the same way", "All three of us landed on the same answer". No agent was
 * consulted in any of those turns.
 *
 * DenialGuard (1C.3) already strips that language, and it does so
 * unconditionally because nothing could tell it apart from a REAL consultation.
 * That is the flaw this slice fixes from the other side: the runtime's
 * strategic mode genuinely does consult specialists and returns
 * `agents_consulted`, and Laravel has been dropping it on the floor. So a
 * legitimate "James looked at this and said X" was being stripped alongside the
 * fabrications, because it had no evidence either.
 *
 * WHY agent_delegations RATHER THAN A NEW TABLE
 * The table already exists and its shape fits: from_agent, to_agent,
 * instruction, result_json, status. It currently records EXECUTION delegation
 * ("Execute write/publish_article as step 41 of plan #56") with a plan_id;
 * plan_id is nullable, so a consultation is the same relationship without a
 * plan. A second table would split "who did Sarah involve" across two places.
 * The kind marker in result_json keeps the two readable apart.
 */
class DelegationRecorder
{
    public const KIND = 'consultation';

    /**
     * Record that an agent was genuinely consulted this turn.
     *
     * Only ever called from a path that actually performed the consultation —
     * currently the runtime's strategic mode, which returns the agents it
     * consulted alongside their contributions.
     */
    public function recordConsultation(
        int $wsId,
        string $toAgent,
        string $question,
        ?string $response,
        array $meta = []
    ): ?int {
        // Three states, and the distinction matters because this record is the
        // evidence attribution is judged against:
        //   completed — we asked and have their answer
        //   consulted — we asked, the answer was folded into a synthesis and
        //               is not returned to us individually
        //   failed    — we asked and it explicitly failed
        //
        // The runtime's strategic mode returns agents_consulted (who was asked)
        // but not their individual replies, so a null response there means
        // "consulted", NOT "failed". Recording those as failures made the first
        // live run report james, priya and elena as failed when all three had
        // actually contributed to the answer the owner received.
        $status = $response !== null
            ? 'completed'
            : (($meta['failed'] ?? false) ? 'failed' : 'consulted');
        try {
            $id = DB::table('agent_delegations')->insertGetId([
                'workspace_id'  => $wsId,
                'plan_id'       => null,          // a consultation has no execution plan
                'from_agent'    => 'sarah',
                'to_agent'      => mb_substr(strtolower(trim($toAgent)), 0, 30),
                'instruction'   => mb_substr($question, 0, 255),
                'status'        => $status,
                'result_json'   => json_encode([
                    'kind'              => self::KIND,
                    'question'          => mb_substr($question, 0, 2000),
                    'response'          => $response === null ? null : mb_substr($response, 0, 4000),
                    'execution_id'      => $meta['execution_id'] ?? null,
                    'source_message_id' => $meta['source_message_id'] ?? null,
                    'conversation_id'   => $meta['conversation_id'] ?? null,
                    'recorded_at'       => now()->toIso8601String(),
                ]),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            return (int) $id;
        } catch (\Throwable $e) {
            // A failed record must not break the reply — but it DOES mean the
            // consultation is unprovable, so the guard will strip attribution.
            // That is the correct direction to fail.
            Log::warning('[Sarah888] delegation record failed', [
                'ws' => $wsId, 'agent' => $toAgent, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Record a whole strategic-mode round in one call. */
    public function recordRound(int $wsId, array $agents, string $question, array $meta = []): int
    {
        $n = 0;
        foreach ($agents as $agent) {
            if (!is_string($agent) || trim($agent) === '') continue;
            if ($this->recordConsultation($wsId, $agent, $question, $meta['responses'][$agent] ?? null, $meta)) $n++;
        }
        if ($n) {
            Log::info('[Sarah888] consultation recorded', [
                'ws' => $wsId, 'agents' => $agents, 'execution_id' => $meta['execution_id'] ?? null,
            ]);
        }
        return $n;
    }

    /** Agents genuinely consulted in a given execution. */
    public function consultedInExecution(int $wsId, ?string $executionId): array
    {
        if (!$executionId) return [];
        try {
            return DB::table('agent_delegations')
                ->where('workspace_id', $wsId)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.kind')) = ?", [self::KIND])
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.execution_id')) = ?", [$executionId])
                ->pluck('to_agent')->unique()->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The flag DenialGuard needs. False unless a real consultation is on record
     * for THIS execution — a consultation from an earlier turn does not license
     * attribution in this one.
     */
    public function wasAnyoneConsulted(int $wsId, ?string $executionId): bool
    {
        return count($this->consultedInExecution($wsId, $executionId)) > 0;
    }

    /** Recent consultations, for the prompt and for "who did you ask?". */
    public function recentForPrompt(int $wsId, int $limit = 6): string
    {
        try {
            $rows = DB::table('agent_delegations')
                ->where('workspace_id', $wsId)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.kind')) = ?", [self::KIND])
                ->orderByDesc('id')->limit($limit)->get(['to_agent', 'instruction', 'status', 'created_at']);
        } catch (\Throwable $e) {
            return '';
        }
        if ($rows->isEmpty()) {
            return "AGENT CONSULTATIONS: none on record. You have NOT asked any specialist for their view. "
                 . "Do not say a colleague confirmed, agreed with or checked anything.\n\n";
        }
        $out = "AGENT CONSULTATIONS ACTUALLY PERFORMED (only these may be attributed):\n";
        foreach ($rows as $r) {
            $out .= '  - ' . $r->to_agent . ' — "' . mb_substr((string) $r->instruction, 0, 70) . '"'
                  . ' (' . $r->status . ', ' . $r->created_at . ")\n";
        }
        return $out . "Anyone not listed here has NOT been consulted. Never attribute a view to them.\n\n";
    }
}
