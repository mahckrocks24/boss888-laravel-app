<?php

namespace App\Core\Billing;

use App\Models\Credit;
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditService
{
    /**
     * Get current balance breakdown.
     */
    public function getBalance(int $workspaceId): array
    {
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
    public function reserveCredits(
        int $workspaceId,
        int $amount,
        ?string $refType = null,
        ?int $refId = null,
        ?string $reservationRef = null,
    ): CreditTransaction {
        if ($amount <= 0) {
            // Zero-cost action — no reservation needed, return dummy
            return new CreditTransaction([
                'workspace_id' => $workspaceId,
                'type' => 'reserve',
                'amount' => 0,
                'reservation_status' => 'committed',
                'reservation_reference' => 'zero_cost',
            ]);
        }

        return DB::transaction(function () use ($workspaceId, $amount, $refType, $refId, $reservationRef) {
            $credit = Credit::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();

            if ($credit->available() < $amount) {
                abort(402, 'Insufficient credits for reservation');
            }

            $credit->increment('reserved_balance', $amount);

            $ref = $reservationRef ?? 'rsv_' . Str::random(16);

            return CreditTransaction::create([
                'workspace_id' => $workspaceId,
                'type' => 'reserve',
                'amount' => $amount,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'reservation_status' => 'pending',
                'reservation_reference' => $ref,
            ]);
        });
    }

    /**
     * Commit reserved credits on successful execution.
     * Converts reservation into a finalized debit.
     */
    public function commitReservedCredits(string $reservationRef): ?CreditTransaction
    {
        return DB::transaction(function () use ($reservationRef) {
            $reservation = CreditTransaction::where('reservation_reference', $reservationRef)
                ->where('type', 'reserve')
                ->where('reservation_status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return null; // Already committed/released or zero-cost
            }

            $credit = Credit::where('workspace_id', $reservation->workspace_id)
                ->lockForUpdate()->firstOrFail();

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
        return DB::transaction(function () use ($reservationRef) {
            $reservation = CreditTransaction::where('reservation_reference', $reservationRef)
                ->where('type', 'reserve')
                ->where('reservation_status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return null; // Already committed/released
            }

            $credit = Credit::where('workspace_id', $reservation->workspace_id)
                ->lockForUpdate()->firstOrFail();

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
        return DB::transaction(function () use ($workspaceId, $amount, $refType, $refId, $meta) {
            $credit = Credit::where('workspace_id', $workspaceId)->lockForUpdate()->firstOrFail();
            $credit->increment('balance', $amount);

            return CreditTransaction::create([
                'workspace_id' => $workspaceId,
                'type' => 'credit',
                'amount' => $amount,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'metadata_json' => $meta,
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
        $this->reserveCredits($workspaceId, $amount, "engine_reservation", null, $ref);
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
}
