<?php

namespace App\Core\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REPORT-0071 P0-2 (2026-10-02): one way to give a customer back what a task cost when the platform, not the customer, is at
 * fault - a deliverable Sarah's own quality gate rejected, or the work the owner was let down by (RFC-0023 repair loop).
 *
 * Billing model unchanged (DEC-0073 D1, CreditService): the engine reserves and commits at execution; a refund is a `credit`
 * transaction referencing the Task, carrying `refund_for_task` and the reason. Idempotent per task across every caller: a task
 * is refunded at most once, whichever path gets there first (the older repair refunds carry `repair_refund`).
 */
final class TaskRefund
{
    /** @return array{credits:int, balance:?int}|null null when nothing was charged or it was already refunded */
    public static function refund(int $wsId, int $taskId, string $reason, array $meta = []): ?array
    {
        // CREDIT-CERT-1 (G-D13): two refund paths can reach one task at the same moment (a failed video's poller and the quality
        // gate); check-then-credit is done under one lock per task so it pays back once
        $lock = \Illuminate\Support\Facades\Cache::lock('task_refund:' . $taskId, 15);
        if (! $lock->block(10)) return null;
        try {
            $committed = (int) DB::table('credit_transactions')->where('workspace_id', $wsId)->where('reference_type', 'Task')->where('reference_id', $taskId)->whereIn('type', ['commit', 'debit'])->sum('amount');
            if ($committed <= 0) return null;
            if (self::alreadyRefunded($wsId, $taskId)) return null;
            app(CreditService::class)->credit($wsId, $committed, 'Task', $taskId, array_merge($meta, ['refund_for_task' => true, 'reason' => mb_substr($reason, 0, 200)]));
            $bal = app(CreditService::class)->getBalance($wsId)['balance'] ?? null;
            Log::info('[TaskRefund] refunded', ['ws' => $wsId, 'task' => $taskId, 'credits' => $committed, 'reason' => mb_substr($reason, 0, 120)]);
            return ['credits' => $committed, 'balance' => $bal !== null ? (int) round((float) $bal) : null];
        } catch (\Throwable $e) { Log::warning('[TaskRefund] failed: ' . $e->getMessage(), ['ws' => $wsId, 'task' => $taskId]); return null; }
        finally { try { $lock->release(); } catch (\Throwable $e) {} }
    }

    public static function alreadyRefunded(int $wsId, int $taskId): bool
    {
        return DB::table('credit_transactions')->where('workspace_id', $wsId)->where('reference_type', 'Task')->where('reference_id', $taskId)->where('type', 'credit')
            ->where(fn ($q) => $q->whereRaw("JSON_EXTRACT(metadata_json, '$.refund_for_task') = true")->orWhereRaw("JSON_EXTRACT(metadata_json, '$.repair_refund') = true"))->exists();
    }
}
