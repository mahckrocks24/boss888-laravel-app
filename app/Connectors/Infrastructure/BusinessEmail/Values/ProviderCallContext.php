<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — everything a provider call needs to be traceable and safe to retry.
 *
 * WHY A VALUE OBJECT RATHER THAN FOUR PARAMETERS
 * ProviderResult's docblock is explicit that DTOs are foreign to this codebase
 * and that nothing gets one without concrete justification. This is the
 * justification: these four facts must accompany EVERY mutating call, on twenty
 * methods. As loose parameters they would be four positional arguments that are
 * easy to transpose — swapping the idempotency key and the correlation id
 * compiles, passes review, and silently destroys idempotency. As a constructed
 * object, a missing or empty key is impossible: the constructor refuses it.
 *
 * WORKSPACE IS PRESENT BUT IS NOT AN AUTHORISATION CONTROL. The engine has
 * already decided the caller may act by the time a context is built. It is here
 * so provider calls are attributable in an operator log, and so an adapter for a
 * provider with per-tenant accounts can route correctly.
 *
 * NO CREDENTIALS. Not a token, not a password, not a secret of any kind. The
 * adapter resolves its own credentials from the provider registry.
 */
final class ProviderCallContext
{
    /**
     * @param int         $workspaceId  the tenant this call is attributable to
     * @param string      $ownerType    business object type, e.g. email_mailbox
     * @param int|null    $ownerId      business object id; null for a create
     * @param string      $idempotencyKey stable per logical intent — the whole point
     * @param string|null $correlationId ties provider logs to ours
     * @param int|null    $operationId  the infra_operations row governing this call
     * @param int|null    $actorUserId  who asked; null for system-initiated work
     *
     * @throws InvalidArgumentException when identity or idempotency is missing
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly string $ownerType,
        public readonly ?int $ownerId,
        public readonly string $idempotencyKey,
        public readonly ?string $correlationId = null,
        public readonly ?int $operationId = null,
        public readonly ?int $actorUserId = null,
    ) {
        if ($workspaceId <= 0) {
            throw new InvalidArgumentException(
                'ProviderCallContext: a provider call must be attributable to a workspace.'
            );
        }

        if (trim($ownerType) === '') {
            throw new InvalidArgumentException(
                'ProviderCallContext: ownerType is required so the call can be traced to a business object.'
            );
        }

        // Refusing to default this is the point. An absent idempotency key is
        // not a minor omission — it is the difference between a retried job
        // creating one mailbox and creating four.
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'ProviderCallContext: an idempotency key is required. A mutating provider call without one '
                . 'cannot be safely retried, and every queue in this platform retries.'
            );
        }
    }

    /** Safe for an operation record and an operator log. Carries no payload. */
    public function toArray(): array
    {
        return [
            'workspace_id'    => $this->workspaceId,
            'owner_type'      => $this->ownerType,
            'owner_id'        => $this->ownerId,
            'idempotency_key' => $this->idempotencyKey,
            'correlation_id'  => $this->correlationId,
            'operation_id'    => $this->operationId,
            'actor_user_id'   => $this->actorUserId,
        ];
    }

    public function withCorrelationId(string $correlationId): self
    {
        return new self(
            $this->workspaceId,
            $this->ownerType,
            $this->ownerId,
            $this->idempotencyKey,
            $correlationId,
            $this->operationId,
            $this->actorUserId,
        );
    }
}
