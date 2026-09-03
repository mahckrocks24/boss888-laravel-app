<?php

namespace App\Core\Billing;

use App\Models\Credit;
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditService
{
    /**
     * 2026-06-24 — SHARED CREDIT POOL. Resolve any workspace to its BILLING
     * workspace (the owner's primary workspace, which holds the single shared
     * wallet across all of a user's website-workspaces). For single-workspace
     * users `billing_workspace_id` = self → identical behavior, zero regression.
     * Applied to every balance-touching op so commit/release (which read the
     * reservation's stored workspace_id) hit the pool automatically.
     */
    private function poolWorkspaceId(int $workspaceId): int
    {
        $bw = (int) (DB::table('workspaces')->where('id', $workspaceId)->value('billing_workspace_id') ?? 0);
        return $bw > 0 ? $bw : $workspaceId;
    }

    /**
     * 2026-06-24 — a workspace's credit usage this billing cycle: committed
     * spend (`commit`) + in-flight pending reserves. Drives allocation caps and
     * the per-workspace billing breakdown (agencies see spend per client).
     */
    public function workspaceUsage(int $workspaceId, ?\DateTimeInterface $since = null): int
    {
        $since = $since ?: now()->startOfMonth();
        return (int) CreditTransaction::where('workspace_id', $workspaceId)
            ->where('created_at', '>=', $since)
            ->where(function ($q) {
                $q->where('type', 'commit')
                  ->orWhere(function ($q2) {
                      $q2->where('type', 'reserve')->where('reservation_status', 'pending');
                  });
            })
            ->sum('amount');
    }

    /**
     * Per-workspace usage for the shared wallet's family (all workspaces sharing
     * one billing_workspace_id). Keyed by workspace_id — for the billing screen.
     */
    public function usageByWorkspace(int $anyWorkspaceId, ?\DateTimeInterface $since = null): array
    {
        $since  = $since ?: now()->startOfMonth();
        $poolWs = $this->poolWorkspaceId($anyWorkspaceId);
        $wsIds  = DB::table('workspaces')->where('billing_workspace_id', $poolWs)->pluck('id')->all();
        if (empty($wsIds)) {
            $wsIds = [$poolWs];
        }
        return CreditTransaction::whereIn('workspace_id', $wsIds)
            ->where('type', 'commit')
            ->where('created_at', '>=', $since)
            ->selectRaw('workspace_id, SUM(amount) AS used')
            ->groupBy('workspace_id')
            ->pluck('used', 'workspace_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Enforce a workspace's optional monthly allocation cap against the shared
     * pool (agency overspend guard). NULL/0 allocation = no cap = full pool.
     */
    private function assertWithinAllocation(int $workspaceId, int $amount): void
    {
        $alloc = (int) (DB::table('workspaces')->where('id', $workspaceId)->value('credit_allocation') ?? 0);
        if ($alloc <= 0) {
            return; // no cap
        }
        $used = $this->workspaceUsage($workspaceId);
        if ($used + $amount > $alloc) {
            abort(402, "Workspace credit allocation reached ({$used}/{$alloc} this cycle).");
        }
    }

    /**
     * Get current balance breakdown.
     */
    public function getBalance(int $workspaceId): array
    {
        $workspaceId = $this->poolWorkspaceId($workspaceId);
        // Phase 3 fix (Gap 4): was firstOrFail() which threw ModelNotFoundException
        // for workspaces with no credits row (new/free-tier). The throw happened at
        // EES Step 3 which is BEFORE the try/catch, causing an unhandled 500 instead
        // of a graceful NO_CREDITS response. Now returns zero-balance so hasBalance()
        // returns false and EES returns the proper NO_CREDITS JSON response.
        $credit = Credit::where('workspace_id', $workspaceId)->first();
        if (! $credit) {
            return ['balance' => 0, 'reserved' => 0, 'available' => 0];
        }
        return [
            'balance' => $credit->balance,
            'reserved' => $credit->reserved_balance,
            'available' => $credit->available(),
        ];
    }

    /**
     * Reserve credits BEFORE execution. Atomic with lockForUpdate.
     * Returns reservation reference for later commit/release.
     */
    /**
     * ARTHUR888 Unit N (2026-09-03): run a credit transaction with automatic
     * retry on transient concurrency errors. Under concurrent billable ops on
     * ONE workspace pool, the `SELECT ... FOR UPDATE` on `credits` can deadlock
     * (MySQL 1213); previously a deadlock on RELEASE rolled the whole tx back and
     * left the reservation `pending` forever, permanently inflating
     * reserved_balance (a credit LEAK). Laravel retries only on
     * causedByConcurrencyError (deadlock / serialization / lock-wait); these
     * closures are self-contained so a full rollback + re-run is safe and
     * idempotent (no partial row survives to duplicate a reservation ref).
     */
    private function txRetry(\Closure $fn)
    {
        return DB::transaction($fn, 5);
    }

    public function reserveCredits(
        int $workspaceId,
        int $amount,
        ?string $refType = null,
        ?int $refId = null,
        ?string $reservationRef = null,
    ): CreditTransaction {
        // 2026-06-24 — keep the ORIGINATING workspace on the transaction for
        // per-workspace USAGE ATTRIBUTION (agency billing + allocation caps);
        // resolve the shared POOL only for the wallet. commit/release re-resolve
        // the stored originating id → pool for the balance adjustment.
        $origWs = $workspaceId;
        $poolWs = $this->poolWorkspaceId($workspaceId);
        if ($amount <= 0) {
            // Zero-cost action — no reservation needed, return dummy
            return new CreditTransaction([
                'workspace_id' => $origWs,
                'type' => 'reserve',
                'amount' => 0,
                'reservation_status' => 'committed',
                'reservation_reference' => 'zero_cost',
            ]);
        }

        return $this->txRetry(function () use ($origWs, $poolWs, $amount, $refType, $refId, $reservationRef) {
            $credit = Credit::where('workspace_id', $poolWs)->lockForUpdate()->firstOrFail();

            if ($credit->available() < $amount) {
                // MONEY-1 (2026-08-29): this string is what the customer reads on the failed task.
                abort(402, "Not enough credits: this needs {$amount}, " . max(0, (int) $credit->balance - (int) $credit->reserved_balance) . " available. Upgrade your plan or wait for your monthly renewal.");
            }

            // Per-workspace allocation cap (agency overspend guard). Opt-in via
            // workspaces.credit_allocation; NULL/0 = no cap = full shared pool.
            $this->assertWithinAllocation($origWs, $amount);

            $credit->increment('reserved_balance', $amount);

            $ref = $reservationRef ?? 'rsv_' . Str::random(16);

            return CreditTransaction::create([
                'workspace_id' => $origWs,   // ATTRIBUTION = the consuming workspace
                'type' => 'reserve',
                'amount' => $amount,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reservation_status' => 'pending',
                'reservation_reference' => $ref,
                // P1-U1 (2026-08-30): authoritative pool provenance — which wallet this reserve hit.
                'metadata_json' => ['pool_workspace_id' => $poolWs],
            ]);
        });
    }

    /**
     * Commit reserved credits on successful execution.
     * Converts reservation into a finalized debit.
     */
    public function commitReservedCredits(string $reservationRef): ?CreditTransaction
    {
        return $this->txRetry(function () use ($reservationRef) {
            $reservation = CreditTransaction::where('reservation_reference', $reservationRef)
                ->where('type', 'reserve')
                ->where('reservation_status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return null; // Already committed/released or zero-cost
            }

            $credit = Credit::where('workspace_id', $this->poolWorkspaceId($reservation->workspace_id))
                ->lockForUpdate()->firstOrFail(); // resolve originating → shared pool for the wallet

            // Move from reserved to spent
            $credit->decrement('reserved_balance', $reservation->amount);
            $credit->decrement('balance', $reservation->amount);

            $reservation->update([
                'reservation_status' => 'committed',
                'finalized_at' => now(),
            ]);

            // Create commit transaction for audit trail
            return CreditTransaction::create([
                'workspace_id' => $reservation->workspace_id,
                'type' => 'commit',
                'amount' => $reservation->amount,
                'reference_type' => $reservation->reference_type,
                'reference_id' => $reservation->reference_id,
                'reservation_reference' => $reservationRef,
                'reservation_status' => 'committed',
                'finalized_at' => now(),
            ]);
        });
    }

    /**
     * Release reserved credits on failure/timeout/block.
     * Returns credits to available pool.
     */
    public function releaseReservedCredits(string $reservationRef): ?CreditTransaction
    {
        return $this->txRetry(function () use ($reservationRef) {
            $reservation = CreditTransaction::where('reservation_reference', $reservationRef)
                ->where('type', 'reserve')
                ->where('reservation_status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return null; // Already committed/released
            }

            $credit = Credit::where('workspace_id', $this->poolWorkspaceId($reservation->workspace_id))
                ->lockForUpdate()->firstOrFail(); // resolve originating → shared pool for the wallet

            $credit->decrement('reserved_balance', $reservation->amount);

            $reservation->update([
                'reservation_status' => 'released',
                'released_at' => now(),
            ]);

            return CreditTransaction::create([
                'workspace_id' => $reservation->workspace_id,
                'type' => 'release',
                'amount' => $reservation->amount,
                'reference_type' => $reservation->reference_type,
                'reference_id' => $reservation->reference_id,
                'reservation_reference' => $reservationRef,
                'reservation_status' => 'released',
                'released_at' => now(),
            ]);
        });
    }

    /**
     * Find pending (orphaned) reservations older than threshold.
     */
    public function findOrphanedReservations(int $minutes = 30): \Illuminate\Database\Eloquent\Collection
    {
        return CreditTransaction::where('type', 'reserve')
            ->where('reservation_status', 'pending')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();
    }

    /**
     * Legacy debit (Phase 1 compat). Now wraps reserve+commit for backward compat.
     */
    public function debit(
        int $workspaceId,
        int $amount,
        ?string $refType = null,
        ?int $refId = null,
        ?array $meta = null,
    ): CreditTransaction {
        $reservation = $this->reserveCredits($workspaceId, $amount, $refType, $refId);
        $this->commitReservedCredits($reservation->reservation_reference);

        return $reservation;
    }

    /**
     * Legacy credit (Phase 1 compat).
     */
    public function credit(
        int $workspaceId,
        int $amount,
        ?string $refType = null,
        ?int $refId = null,
        ?array $meta = null,
    ): CreditTransaction {
        $origWs = $workspaceId;
        $poolWs = $this->poolWorkspaceId($workspaceId);
        return $this->txRetry(function () use ($origWs, $poolWs, $amount, $refType, $refId, $meta) {
            // Crediting must not fail when the wallet does not exist yet (e.g. a
            // brand-new workspace's first-website trial activation). Create it, then
            // lock+increment. Debit/reserve/commit/release keep firstOrFail: you cannot
            // spend a wallet that is not there.
            $credit = Credit::where('workspace_id', $poolWs)->lockForUpdate()->first();
            if (! $credit) {
                try {
                    Credit::create(['workspace_id' => $poolWs, 'balance' => 0, 'reserved_balance' => 0]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // concurrent create raced us on the UNIQUE(workspace_id) — fall through
                }
                $credit = Credit::where('workspace_id', $poolWs)->lockForUpdate()->firstOrFail();
            }
            $credit->increment('balance', $amount);

            return CreditTransaction::create([
                'workspace_id' => $origWs,
                'type' => 'credit',
                'amount' => $amount,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'metadata_json' => array_merge(is_array($meta) ? $meta : [], ['pool_workspace_id' => $poolWs]),
            ]);
        });
    }

    /**
     * Quick balance check for EngineExecutionService.
     */
    public function hasBalance(int $workspaceId, int $amount): bool
    {
        $balance = $this->getBalance($workspaceId);
        return ($balance['available'] ?? 0) >= $amount;
    }

    /**
     * Reserve credits (wrapper for EngineExecutionService).
     * Returns reservation reference string.
     */
    public function reserve(int $workspaceId, int $amount, string $reason = ''): string
    {
        // PATCH v1.0.2: was 'res_' . uniqid() — microsecond-based, collision-possible
        // under concurrent load (5 supervisor workers). Str::uuid() is cryptographically
        // random (RFC 4122 v4) — collision probability negligible at any scale.
        $ref = 'res_' . \Illuminate\Support\Str::uuid()->toString();
        // MISSION-018 WS-1 (2026-08-24, RISK-0042): $reason was accepted from
        // twelve subsystems ("{engine}/{action}", "seo_assistant:{action}",
        // "meeting_strategy", "proposal:{id}", …) and then DROPPED — every
        // reservation filed as the constant 'engine_reservation'. It now flows
        // into reference_type, so the ledger records what each reservation was
        // for. Falls back to the old constant only when no reason is supplied.
        $refType = $reason !== '' ? mb_substr($reason, 0, 255) : 'engine_reservation';
        $this->reserveCredits($workspaceId, $amount, $refType, null, $ref);
        return $ref;
    }

    /**
     * Commit reserved credits.
     */
    public function commit(int $workspaceId, string $reservationRef, int $amount): void
    {
        $this->commitReservedCredits($reservationRef);
    }

    /**
     * Release reserved credits.
     */
    public function release(int $workspaceId, string $reservationRef): void
    {
        $this->releaseReservedCredits($reservationRef);
    }

    /**
     * Wave 22 — 10-chat batched metering for AI Assistant + agent chats.
     *
     * Atomically increments workspaces.chat_meter. On the 10th call,
     * resets the counter to 0 and debits 1 credit (effective 0.1 cr/chat).
     *
     * Returns ['debited' => bool, 'counter' => int, 'sufficient' => bool].
     * When sufficient=false the caller should reject the chat with a 402.
     */
    public function meterChat(int $workspaceId, string $reason = 'chat'): array
    {
        return $this->txRetry(function () use ($workspaceId, $reason) {
            $current = (int) (DB::table('workspaces')
                ->where('id', $workspaceId)
                ->lockForUpdate()
                ->value('chat_meter') ?? 0);

            $newCounter = $current + 1;

            if ($newCounter >= 10) {
                // 10th chat triggers a 1-credit debit; refuse if balance short.
                if (!$this->hasBalance($workspaceId, 1)) {
                    return ['debited' => false, 'counter' => $current, 'sufficient' => false];
                }
                DB::table('workspaces')->where('id', $workspaceId)->update(['chat_meter' => 0]);
                $this->debit($workspaceId, 1, $reason);
                return ['debited' => true, 'counter' => 0, 'sufficient' => true];
            }

            DB::table('workspaces')->where('id', $workspaceId)->update(['chat_meter' => $newCounter]);
            return ['debited' => false, 'counter' => $newCounter, 'sufficient' => true];
        });
    }
}