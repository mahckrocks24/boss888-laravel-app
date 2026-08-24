<?php

namespace App\Core\Chat;

use App\Core\Billing\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * P2-B — charge lifecycle for one logical chat request.
 *
 * Enforces INV-06, INV-07, INV-08, INV-09, INV-13.
 *
 * THIS SERVICE DOES NOT HOLD MONEY.
 * `credits` / `credit_transactions` and App\Core\Billing\CreditService remain
 * the sole authority on balances. Everything here records what the ledger did
 * and links it to the message that caused it — the join clause K-07 says does
 * not exist today, and without which a billing dispute about a specific chat
 * cannot be answered.
 *
 * Every state change is a CONDITIONAL update guarded by the state we expect to
 * be in. That is what makes double-commit and double-release impossible under
 * concurrency rather than merely unlikely.
 */
class MessageChargeService
{
    public const TABLE = 'message_charges';

    public const PENDING        = 'pending';
    public const RESERVED       = 'reserved';
    public const EXECUTING      = 'executing';
    public const COMMITTED      = 'committed';
    public const RELEASED       = 'released';
    public const FAILED         = 'failed';
    public const NOT_CHARGEABLE = 'not_chargeable';

    /** Valid transitions. Anything absent here is rejected. */
    private const TRANSITIONS = [
        self::PENDING        => [self::RESERVED, self::NOT_CHARGEABLE, self::FAILED],
        self::RESERVED       => [self::EXECUTING, self::COMMITTED, self::RELEASED, self::FAILED],
        self::EXECUTING      => [self::COMMITTED, self::RELEASED, self::FAILED],
        self::COMMITTED      => [],   // terminal — INV-06
        self::RELEASED       => [],   // terminal — INV-08
        self::FAILED         => [],
        self::NOT_CHARGEABLE => [],
    ];

    /**
     * Surfaces that must never be charged.
     *
     * INV-13 — Studio has never charged: /api/studio/chat guards its deduction
     * with class_exists(\App\Services\CreditService::class), and that class does
     * not exist. Beginning to charge it would be a PRICING decision (CR-23),
     * not a stabilization fix. Declared here so the freeze is explicit and
     * testable rather than an accident of a broken guard.
     */
    private const NEVER_CHARGEABLE = [
        's5_studio_chat'    => 'studio_unmetered_cr23',
        's6_studio_ai'      => 'studio_unmetered_cr23',
        's7_builder_arthur' => 'studio_unmetered_cr23',
    ];

    public function __construct(private CreditService $credits) {}

    /** INV-13 — is this surface chargeable at all? */
    public function classify(string $surface): array
    {
        if (isset(self::NEVER_CHARGEABLE[$surface])) {
            return ['chargeable' => false, 'reason' => self::NEVER_CHARGEABLE[$surface]];
        }

        return ['chargeable' => true, 'reason' => null];
    }

    /**
     * Open the charge record for a logical request. Exactly one per idempotency
     * record (INV-06), so this is find-or-reopen, never a blind insert.
     *
     * WHY: a retryable failure releases the charge and marks the idempotency
     * record `failed_retryable`. A later retry RECLAIMS that same record, so a
     * blind insert would violate the unique index on idempotency_record_id —
     * which is exactly how this bug was caught. Reusing the row keeps the
     * "one charge per logical request" guarantee across retries.
     *
     * A COMMITTED charge is never reopened: that would be the double-charge
     * INV-06 exists to prevent.
     */
    public function open(array $ctx): int
    {
        $class = $this->classify((string) $ctx['surface']);

        // P2-C: a surface that already owns a correct reserve/commit/release
        // cycle keeps it. Charging again here would bill the same request twice
        // — which is exactly what the chatbot integration surfaced. The record
        // still captures linkage; it simply does not take the money.
        if (($ctx['delegated'] ?? false) === true) {
            $class = ['chargeable' => false, 'reason' => 'delegated_to_surface_meter'];
        }

        $now   = now();

        $existing = DB::table(self::TABLE)
            ->where('idempotency_record_id', (int) $ctx['idempotency_record_id'])
            ->first();

        if ($existing) {
            if (in_array($existing->status, [self::COMMITTED, self::NOT_CHARGEABLE], true)) {
                return (int) $existing->id;   // settled — do not touch
            }

            // A previous attempt failed or was released. Reset for this attempt,
            // preserving what happened before so the history is not lost.
            $meta = json_decode($existing->metadata ?? '{}', true) ?: [];
            $meta['attempts'] = ($meta['attempts'] ?? 1) + 1;
            $meta['previous'][] = [
                'status'       => $existing->status,
                'failure_code' => $existing->failure_code,
                'released'     => (int) $existing->released_credits,
            ];
            if ($class['reason']) {
                $meta['reason'] = $class['reason'];
            }

            DB::table(self::TABLE)->where('id', $existing->id)->update([
                'status'            => $class['chargeable'] ? self::PENDING : self::NOT_CHARGEABLE,
                'estimated_credits' => (int) ($ctx['estimated_credits'] ?? 0),
                'reserved_credits'  => 0,
                'charged_credits'   => 0,
                'released_credits'  => 0,
                'existing_credit_transaction_reference' => null,
                'reserved_at'       => null,
                'committed_at'      => null,
                'released_at'       => null,
                'failed_at'         => null,
                'failure_code'      => null,
                'correlation_id'    => (string) $ctx['correlation_id'],
                'metadata'          => json_encode($meta),
                'updated_at'        => $now,
            ]);

            return (int) $existing->id;
        }

        return DB::table(self::TABLE)->insertGetId([
            'workspace_id'          => (int) $ctx['workspace_id'],
            'user_id'               => $ctx['user_id'] ?? null,
            'surface'               => (string) $ctx['surface'],
            'agent_id'              => $ctx['agent_id'] ?? null,
            'conversation_id'       => isset($ctx['conversation_id']) ? (string) $ctx['conversation_id'] : null,
            'client_message_id'     => $ctx['client_message_id'] ?? null,
            'idempotency_record_id' => (int) $ctx['idempotency_record_id'],
            'idempotency_key'       => $ctx['idempotency_key'] ?? null,
            'correlation_id'        => (string) $ctx['correlation_id'],
            'estimated_credits'     => (int) ($ctx['estimated_credits'] ?? 0),
            'status'                => $class['chargeable'] ? self::PENDING : self::NOT_CHARGEABLE,
            'metadata'              => json_encode(array_filter([
                'reason' => $class['reason'],
            ])),
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);
    }

    /**
     * INV-09 — eligibility BEFORE any provider call.
     * Returns false when the workspace cannot afford the request.
     */
    public function isEligible(int $workspaceId, int $credits): bool
    {
        if ($credits <= 0) {
            return true;
        }

        return $this->credits->hasBalance($workspaceId, $credits);
    }

    /** pending → reserved, through the authoritative ledger. */
    public function reserve(int $chargeId, int $workspaceId, int $credits, string $refType = 'chat'): ?string
    {
        $charge = $this->find($chargeId);
        if (!$charge || $charge->status === self::NOT_CHARGEABLE) {
            return null;   // INV-13
        }
        if ($credits <= 0) {
            $this->transition($chargeId, self::PENDING, self::NOT_CHARGEABLE, ['metadata' => json_encode(['reason' => 'zero_cost'])]);

            return null;
        }

        $ref = 'chatchg_' . $chargeId . '_' . Str::lower(Str::random(10));

        try {
            $this->credits->reserveCredits($workspaceId, $credits, $refType, $chargeId, $ref);
        } catch (\Throwable $e) {
            $this->transition($chargeId, self::PENDING, self::FAILED, [
                'failure_code' => 'CHAT_INSUFFICIENT_CREDITS', 'failed_at' => now(),
            ]);

            return null;
        }

        $this->transition($chargeId, self::PENDING, self::RESERVED, [
            'reserved_credits' => $credits,
            'reserved_at'      => now(),
            'existing_credit_transaction_reference' => $ref,
        ]);

        return $ref;
    }

    public function markExecuting(int $chargeId, ?string $provider = null, ?string $model = null): void
    {
        $this->transition($chargeId, self::RESERVED, self::EXECUTING, array_filter([
            'provider' => $provider, 'model' => $model,
            'provider_execution_started_at' => now(),
        ], fn ($v) => $v !== null));
    }

    /**
     * INV-06 — commit exactly once. The conditional update is the guarantee:
     * a second caller matches 0 rows and never reaches the ledger.
     */
    public function commit(int $chargeId, array $extra = []): bool
    {
        $charge = $this->find($chargeId);
        if (!$charge) {
            return false;
        }
        if ($charge->status === self::NOT_CHARGEABLE) {
            return true;   // INV-13 — nothing to commit, and that is success
        }

        $moved = DB::table(self::TABLE)
            ->where('id', $chargeId)
            ->whereIn('status', [self::RESERVED, self::EXECUTING])
            ->update(array_merge([
                'status'          => self::COMMITTED,
                'charged_credits' => $charge->reserved_credits,
                'committed_at'    => now(),
                'updated_at'      => now(),
            ], $extra));

        if ($moved !== 1) {
            return false;   // already committed or released — do NOT touch the ledger
        }

        if ($charge->existing_credit_transaction_reference) {
            try {
                $this->credits->commitReservedCredits($charge->existing_credit_transaction_reference);
            } catch (\Throwable $e) {
                Log::error('chat.charge.commit_failed', [
                    'charge_id' => $chargeId, 'correlation_id' => $charge->correlation_id,
                    'reference' => $charge->existing_credit_transaction_reference,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * INV-07 + INV-08 — release exactly once, and never commit after failure.
     * Double release would CREATE credits from nothing, so the conditional
     * update matters more here than anywhere else in the service.
     */
    public function release(int $chargeId, ?string $failureCode = null): bool
    {
        $charge = $this->find($chargeId);
        if (!$charge) {
            return false;
        }
        if ($charge->status === self::NOT_CHARGEABLE) {
            return true;
        }

        $moved = DB::table(self::TABLE)
            ->where('id', $chargeId)
            ->whereIn('status', [self::PENDING, self::RESERVED, self::EXECUTING])
            ->update([
                'status'           => self::RELEASED,
                'released_credits' => $charge->reserved_credits,
                'charged_credits'  => 0,
                'released_at'      => now(),
                'failure_code'     => $failureCode,
                'updated_at'       => now(),
            ]);

        if ($moved !== 1) {
            return false;   // already terminal — releasing again would inflate the balance
        }

        if ($charge->existing_credit_transaction_reference) {
            try {
                $this->credits->releaseReservedCredits($charge->existing_credit_transaction_reference);
            } catch (\Throwable $e) {
                Log::error('chat.charge.release_failed', [
                    'charge_id' => $chargeId, 'correlation_id' => $charge->correlation_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /** Attach the message this charge paid for — the K-07 join. */
    public function attachMessage(int $chargeId, string $store, ?int $messageId, array $usage = []): void
    {
        DB::table(self::TABLE)->where('id', $chargeId)->update(array_filter([
            'message_store' => $store,
            'message_id'    => $messageId,
            'usage_units'   => $usage['tokens'] ?? null,
            'provider'      => $usage['provider'] ?? null,
            'model'         => $usage['model'] ?? null,
            'updated_at'    => now(),
        ], fn ($v) => $v !== null));
    }

    public function find(int $id): ?object
    {
        return DB::table(self::TABLE)->where('id', $id)->first();
    }

    /** Guarded state change; rejects undeclared transitions. */
    private function transition(int $id, string $from, string $to, array $extra = []): bool
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            Log::warning('chat.charge.invalid_transition', ['id' => $id, 'from' => $from, 'to' => $to]);

            return false;
        }

        return DB::table(self::TABLE)
            ->where('id', $id)
            ->where('status', $from)
            ->update(array_merge(['status' => $to, 'updated_at' => now()], $extra)) === 1;
    }
}
