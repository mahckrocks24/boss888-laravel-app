<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 Phase 1Q — reverse the work a single turn created.
 *
 * Two guards need exactly this: UngroundedWorkGuard (work created on a premise
 * the reply disowned) and RefusalBoundaryGuard (work created after the reply
 * refused the request). The trigger differs; the reversal is identical, and
 * writing it twice would guarantee the two copies drift.
 *
 * Only possible at all because Phase 1M stamps execution_id onto every task —
 * without provenance this would have to cancel by time window, which would
 * sweep up a concurrent turn's work.
 *
 * Cancels, never deletes. An owner auditing their workspace should be able to
 * see that something was proposed and withdrawn, and why.
 */
class TurnWork
{
    /** Statuses that have not yet run and can still be withdrawn safely. */
    private const REVERSIBLE = ['pending', 'queued', 'awaiting_approval'];

    /**
     * @return array{cancelled:int, ids:array<int,int>, actions:array<int,string>}
     */
    public function cancelThisTurn(int $wsId, string $reason): array
    {
        $out = ['cancelled' => 0, 'ids' => [], 'actions' => []];

        $execId = app(CorrelationContext::class)->executionId();
        if (!$execId) {
            Log::info('[Sarah888] turn reversal requested with no execution_id to target', [
                'ws' => $wsId, 'reason' => $reason,
            ]);
            return $out;
        }

        $rows = DB::table('tasks')
            ->where('workspace_id', $wsId)
            ->whereIn('status', self::REVERSIBLE)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.execution_id')) = ?", [$execId])
            ->get(['id', 'action']);
        if ($rows->isEmpty()) return $out;

        $ids = $rows->pluck('id')->all();
        DB::table('tasks')->whereIn('id', $ids)->update([
            'status'     => 'cancelled',
            'error_text' => 'Cancelled by Sarah888: ' . $reason,
            'updated_at' => now(),
        ]);

        $out['cancelled'] = count($ids);
        $out['ids']       = $ids;
        $out['actions']   = $rows->pluck('action')->all();

        Log::warning('[Sarah888] turn work reversed', [
            'ws' => $wsId, 'execution_id' => $execId, 'reason' => $reason,
            'task_ids' => $ids, 'actions' => $out['actions'],
        ]);
        return $out;
    }
}
