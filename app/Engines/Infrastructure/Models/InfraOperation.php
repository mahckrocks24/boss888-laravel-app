<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Database\Eloquent\Model;

/**
 * One attempted state-changing action against a provider.
 *
 * Created BEFORE the provider call so a crash leaves a recoverable record. The
 * (workspace_id, idempotency_key) unique constraint is what prevents a retried
 * queue job from provisioning the same resource four times — TaskExecutionJob
 * retries 4x with backoff [8,16,32,64].
 */
class InfraOperation extends Model
{
    use BelongsToWorkspace;

    protected $table = 'infra_operations';

    protected $fillable = [
        'workspace_id', 'owner_type', 'owner_id', 'operation', 'state',
        'capability', 'provider', 'idempotency_key', 'attempt_count',
        'max_attempts', 'retry_classification', 'next_retry_at', 'timeout_at',
        'started_at', 'finished_at', 'approval_id', 'task_id', 'actor_user_id',
        'source', 'provider_correlation_id', 'failure_code', 'failure_summary',
        'request_json', 'result_json',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'max_attempts'  => 'integer',
        'next_retry_at' => 'datetime',
        'timeout_at'    => 'datetime',
        'started_at'    => 'datetime',
        'finished_at'   => 'datetime',
        'request_json'  => 'array',
        'result_json'   => 'array',
    ];

    public const RETRY_RETRYABLE = 'retryable';
    public const RETRY_PERMANENT = 'permanent';
    public const RETRY_MANUAL    = 'manual';

    public function canRetry(): bool
    {
        return $this->retry_classification === self::RETRY_RETRYABLE
            && (int) $this->attempt_count < (int) $this->max_attempts;
    }

    public function isRecoverable(): bool
    {
        return in_array($this->state, OperationState::recoverable(), true);
    }

    /**
     * @throws \InvalidArgumentException on an illegal transition.
     */
    public function transitionTo(string $to): self
    {
        OperationState::assertTransition((string) $this->state, $to);
        $this->state = $to;

        return $this;
    }
}
