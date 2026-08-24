<?php

namespace App\Core\Chat;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * P2-B — authoritative idempotency for chat requests.
 *
 * Enforces INV-01, INV-02, INV-03, INV-10 and INV-12.
 *
 * DESIGN NOTE — why acquisition is an INSERT, not a SELECT-then-INSERT.
 * The obvious implementation ("does a record exist? no → create one") loses the
 * race that matters: two identical requests arriving together both read "no"
 * and both proceed. Here the INSERT itself is the lock. Exactly one caller
 * survives the unique index; every other caller takes the duplicate path. The
 * database decides, so correctness does not depend on request timing.
 */
class ChatIdempotencyService
{
    public const TABLE = 'chat_idempotency_records';

    /** How long a `processing` record may be held before it is reclaimable. */
    public const LEASE_SECONDS = 180;

    /** How long a completed outcome stays replayable (contract §13: ≥24h). */
    public const RETENTION_HOURS = 48;

    public const ACQUIRED         = 'acquired';
    public const PROCESSING       = 'processing';
    public const COMPLETED        = 'completed';
    public const FAILED_RETRYABLE = 'failed_retryable';
    public const FAILED_FINAL     = 'failed_final';
    public const CONFLICT         = 'conflict';
    public const EXPIRED          = 'expired';

    /**
     * Outcome of an acquisition attempt.
     *
     * @return array{
     *   state:'acquired'|'replay'|'in_progress'|'conflict'|'retryable',
     *   record:object|null, response:array|null, error_code:?string,
     *   correlation_id:string
     * }
     */
    public function acquire(array $ctx): array
    {
        $now         = now();
        $correlation = $ctx['correlation_id'] ?? ('cor_' . Str::lower(Str::ulid()));
        $fingerprint = $ctx['request_fingerprint'];

        $row = [
            'workspace_id'        => (int) $ctx['workspace_id'],
            'user_id'             => $ctx['user_id'] ?? null,
            'surface'             => (string) $ctx['surface'],
            'agent_id'            => $ctx['agent_id'] ?? null,
            'conversation_type'   => $ctx['conversation_type'] ?? null,
            'conversation_id'     => isset($ctx['conversation_id']) ? (string) $ctx['conversation_id'] : null,
            'client_message_id'   => $ctx['client_message_id'] ?? null,
            'idempotency_key'     => (string) $ctx['idempotency_key'],
            'request_fingerprint' => $fingerprint,
            'correlation_id'      => $correlation,
            'status'              => self::ACQUIRED,
            'started_at'          => $now,
            'expires_at'          => $now->copy()->addHours(self::RETENTION_HOURS),
            'metadata'            => json_encode($ctx['metadata'] ?? []),
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        try {
            $id = DB::table(self::TABLE)->insertGetId($row);

            return [
                'state' => 'acquired', 'record' => $this->find($id),
                'response' => null, 'error_code' => null, 'correlation_id' => $correlation,
            ];
        } catch (UniqueConstraintViolationException $e) {
            // Someone else got here first. THIS is the duplicate path.
            return $this->resolveDuplicate($ctx, $fingerprint);
        }
    }

    /**
     * INV-02, INV-03, INV-10 — decide what a duplicate deserves.
     * Always scoped by workspace (INV-12): never looked up by key alone.
     */
    private function resolveDuplicate(array $ctx, string $fingerprint): array
    {
        $existing = DB::table(self::TABLE)
            ->where('workspace_id', (int) $ctx['workspace_id'])
            ->where('surface', (string) $ctx['surface'])
            ->where('idempotency_key', (string) $ctx['idempotency_key'])
            ->first();

        if (!$existing) {
            // Vanishingly rare: the row was swept between the failed insert and
            // this read. Treat as retryable rather than guessing.
            return ['state' => 'retryable', 'record' => null, 'response' => null,
                    'error_code' => 'CHAT_INTERNAL_ERROR', 'correlation_id' => $ctx['correlation_id'] ?? ''];
        }

        // INV-03 — same key, different request. Never serve the wrong answer.
        if (!hash_equals($existing->request_fingerprint, $fingerprint)) {
            return ['state' => 'conflict', 'record' => $existing, 'response' => null,
                    'error_code' => 'CHAT_CONVERSATION_CONFLICT', 'correlation_id' => $existing->correlation_id];
        }

        // INV-10 — completed: return the stored outcome untouched.
        if ($existing->status === self::COMPLETED) {
            $this->noteReplay($existing->id);

            return ['state' => 'replay', 'record' => $existing,
                    'response' => json_decode($existing->response_reference ?? 'null', true),
                    'error_code' => null, 'correlation_id' => $existing->correlation_id];
        }

        // A final failure is a settled outcome; replaying it must not re-execute.
        if ($existing->status === self::FAILED_FINAL) {
            return ['state' => 'replay', 'record' => $existing,
                    'response' => json_decode($existing->response_reference ?? 'null', true),
                    'error_code' => $existing->error_code, 'correlation_id' => $existing->correlation_id];
        }

        // A retryable failure may be attempted again — reclaim the record.
        if ($existing->status === self::FAILED_RETRYABLE) {
            return $this->reclaim($existing, $fingerprint);
        }

        // acquired / processing — is the lease still live?
        if ($this->leaseExpired($existing)) {
            return $this->reclaim($existing, $fingerprint);
        }

        // INV-02 — genuinely in flight. No second provider execution.
        return ['state' => 'in_progress', 'record' => $existing, 'response' => null,
                'error_code' => 'CHAT_CONVERSATION_CONFLICT', 'correlation_id' => $existing->correlation_id];
    }

    private function leaseExpired(object $r): bool
    {
        $anchor = $r->updated_at ?? $r->started_at ?? $r->created_at;
        if (!$anchor) {
            return true;
        }

        return \Carbon\Carbon::parse($anchor)->addSeconds(self::LEASE_SECONDS)->isPast();
    }

    /**
     * Take over a stale or retryable record. Conditional on the status we read,
     * so two reclaimers cannot both win.
     */
    private function reclaim(object $existing, string $fingerprint): array
    {
        $affected = DB::table(self::TABLE)
            ->where('id', $existing->id)
            ->where('status', $existing->status)          // optimistic guard
            ->update([
                'status'              => self::ACQUIRED,
                'request_fingerprint' => $fingerprint,
                'started_at'          => now(),
                'failed_at'           => null,
                'error_code'          => null,
                'updated_at'          => now(),
            ]);

        if ($affected === 0) {
            // Another process reclaimed it first — that one owns the execution.
            return ['state' => 'in_progress', 'record' => $this->find($existing->id), 'response' => null,
                    'error_code' => 'CHAT_CONVERSATION_CONFLICT', 'correlation_id' => $existing->correlation_id];
        }

        Log::info('chat.idempotency.reclaimed', [
            'record_id' => $existing->id, 'previous_status' => $existing->status,
            'correlation_id' => $existing->correlation_id,
        ]);

        return ['state' => 'acquired', 'record' => $this->find($existing->id), 'response' => null,
                'error_code' => null, 'correlation_id' => $existing->correlation_id];
    }

    /** acquired → processing. Conditional: only one caller may start work. */
    public function markProcessing(int $id): bool
    {
        return DB::table(self::TABLE)
            ->where('id', $id)
            ->whereIn('status', [self::ACQUIRED])
            ->update(['status' => self::PROCESSING, 'updated_at' => now()]) === 1;
    }

    /** processing → completed. INV-05: the guard is why one final is persisted. */
    public function markCompleted(int $id, array $responseReference): bool
    {
        return DB::table(self::TABLE)
            ->where('id', $id)
            ->whereIn('status', [self::PROCESSING, self::ACQUIRED])
            ->update([
                'status'             => self::COMPLETED,
                'response_reference' => json_encode($responseReference),
                'completed_at'       => now(),
                'updated_at'         => now(),
            ]) === 1;
    }

    public function markFailed(int $id, string $errorCode, bool $retryable, array $responseReference = []): bool
    {
        return DB::table(self::TABLE)
            ->where('id', $id)
            ->whereNotIn('status', [self::COMPLETED])
            ->update([
                'status'             => $retryable ? self::FAILED_RETRYABLE : self::FAILED_FINAL,
                'error_code'         => $errorCode,
                'response_reference' => $responseReference ? json_encode($responseReference) : null,
                'failed_at'          => now(),
                'updated_at'         => now(),
            ]) === 1;
    }

    /** Sweep records whose lease has lapsed while `processing`. */
    public function recoverStale(int $limit = 100): int
    {
        $cutoff = now()->subSeconds(self::LEASE_SECONDS);

        return DB::table(self::TABLE)
            ->whereIn('status', [self::ACQUIRED, self::PROCESSING])
            ->where('updated_at', '<', $cutoff)
            ->limit($limit)
            ->update([
                'status'     => self::FAILED_RETRYABLE,
                'error_code' => 'CHAT_INTERNAL_ERROR',
                'failed_at'  => now(),
                'updated_at' => now(),
            ]);
    }

    public function find(int $id): ?object
    {
        return DB::table(self::TABLE)->where('id', $id)->first();
    }

    private function noteReplay(int $id): void
    {
        try {
            $r = $this->find($id);
            $meta = json_decode($r->metadata ?? '{}', true) ?: [];
            $meta['replay_count'] = ($meta['replay_count'] ?? 0) + 1;
            // completed_at is deliberately NOT touched (INV-10).
            DB::table(self::TABLE)->where('id', $id)->update(['metadata' => json_encode($meta)]);
        } catch (\Throwable $e) {
            Log::warning('chat.idempotency.replay_note_failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }
}
