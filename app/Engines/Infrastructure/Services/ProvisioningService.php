<?php

namespace App\Engines\Infrastructure\Services;

use App\Connectors\Infrastructure\Contracts\HostingProviderConnector;
use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Connectors\Infrastructure\ProviderResult;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Models\InfraProviderResource;
use App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry as Registry;
use App\Engines\Infrastructure\States\HostingState;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Provider-neutral governed operation service (directive §11).
 *
 * Owns the operation lifecycle. Contains NO provider logic — every external call
 * goes through a connector contract. Controllers stay thin; orchestration lives
 * here.
 *
 * The two halves:
 *   request()  — runs in the HTTP request. Validates, creates the operation
 *                record, and hands governance to EngineExecutionService.
 *   execute()  — runs in the queue AFTER approval. Resolves the connector,
 *                executes, persists normalized results, records events.
 *
 * Idempotency is enforced at two levels: the (workspace_id, idempotency_key)
 * unique index on infra_operations, and a terminal-state short-circuit in
 * execute(). TaskExecutionJob retries 4x with backoff [8,16,32,64]; without both
 * guards a retry would provision four times.
 */
class ProvisioningService
{
    public function __construct(
        private readonly InfrastructureConnectorResolver $connectors,
        private readonly InfraEventRecorder $events,
    ) {
    }

    // ---------------------------------------------------------------- REQUEST

    /**
     * Create (or return) the operation for a hosting provisioning request.
     * Does NOT execute — execution happens only after approval.
     *
     * @return array{operation:InfraOperation, reused:bool}
     */
    public function requestHostingProvision(
        int $wsId,
        ?int $actorUserId,
        array $payload,
        ?string $idempotencyKey = null
    ): array {
        $meta = Registry::get(Registry::OP_PROVISION_HOSTING);

        if (!$meta) {
            throw new RuntimeException('Operation is not registered.');
        }

        $clean = $this->validateHostingPayload($payload);
        $key = $idempotencyKey ?: $this->deriveIdempotencyKey($wsId, Registry::OP_PROVISION_HOSTING, $clean);

        return WorkspaceContext::run($wsId, function () use ($wsId, $actorUserId, $clean, $key, $meta) {
            // Idempotency: an identical in-flight or completed request returns the
            // SAME operation rather than creating a second one.
            $existing = InfraOperation::query()->where('idempotency_key', $key)->first();

            if ($existing) {
                return ['operation' => $existing, 'reused' => true];
            }

            $op = InfraOperation::create([
                'workspace_id'         => $wsId,
                'owner_type'           => $meta['resource_type'],
                'owner_id'             => null,
                'operation'            => Registry::OP_PROVISION_HOSTING,
                'state'                => OperationState::REQUESTED,
                'capability'           => $meta['capability_key'],
                'provider'             => null,
                'idempotency_key'      => $key,
                'attempt_count'        => 0,
                'max_attempts'         => (int) config('infrastructure.operations.default_max_attempts', 3),
                'actor_user_id'        => $actorUserId,
                'source'               => 'manual',
                'timeout_at'           => now()->addSeconds((int) config('infrastructure.operations.timeout_seconds', 300)),
                'request_json'         => $this->events->redact($clean),
            ]);

            $this->events->record(
                workspaceId: $wsId,
                event: 'operation_requested',
                ownerType: $meta['resource_type'],
                ownerId: $op->id,
                severity: InfraEvent::SEVERITY_INFO,
                toState: OperationState::REQUESTED,
                summary: 'Hosting provisioning requested.',
                context: ['operation' => Registry::OP_PROVISION_HOSTING, 'payload' => $clean],
                actorUserId: $actorUserId,
                source: 'manual',
                operationId: $op->id,
            );

            return ['operation' => $op, 'reused' => false];
        });
    }

    /** Record the governance decision returned by EngineExecutionService. */
    public function markAwaitingApproval(int $wsId, int $operationId, ?int $approvalId, ?int $taskId): void
    {
        WorkspaceContext::run($wsId, function () use ($wsId, $operationId, $approvalId, $taskId) {
            $op = InfraOperation::query()->find($operationId);
            if (!$op || $op->state !== OperationState::REQUESTED) {
                return;
            }

            $op->transitionTo(OperationState::AWAITING_APPROVAL);
            $op->approval_id = $approvalId;
            $op->task_id = $taskId;
            $op->save();

            $this->events->recordStateChange(
                $wsId, $op->owner_type, $op->id,
                OperationState::REQUESTED, OperationState::AWAITING_APPROVAL,
                $op->actor_user_id, 'manual',
                ['approval_id' => $approvalId, 'task_id' => $taskId],
                $op->id
            );
        });
    }

    // ---------------------------------------------------------------- EXECUTE

    /**
     * Execute an approved operation. Invoked from the queue via the Orchestrator
     * dispatch map after ApprovalService approves the linked task.
     */
    public function execute(int $wsId, array $params): array
    {
        $operationId = (int) ($params['operation_id'] ?? 0);

        if ($operationId <= 0) {
            throw new InvalidArgumentException('operation_id is required.');
        }

        return WorkspaceContext::run($wsId, function () use ($wsId, $operationId, $params) {
            /** @var InfraOperation|null $op */
            $op = InfraOperation::query()->find($operationId);

            if (!$op) {
                throw new RuntimeException('Operation not found in this workspace.');
            }

            // ---- Idempotency short-circuit -------------------------------
            // A retried job must never re-execute a finished operation.
            if (OperationState::isTerminal((string) $op->state)) {
                return [
                    'success'      => $op->state === OperationState::SUCCEEDED,
                    'operation_id' => $op->id,
                    'state'        => $op->state,
                    'idempotent'   => true,
                    'message'      => 'Operation already completed; no action taken.',
                ];
            }

            // Approval is mandatory for protected operations.
            if (in_array($op->operation, Registry::protectedOperations(), true)
                && !$op->approval_id
                && $op->state !== OperationState::APPROVED) {
                throw new RuntimeException('Operation has not been approved.');
            }

            if ($op->state === OperationState::AWAITING_APPROVAL) {
                $op->transitionTo(OperationState::APPROVED)->save();
            }

            if ($op->state === OperationState::APPROVED) {
                $op->transitionTo(OperationState::QUEUED)->save();
            }

            if ($op->state !== OperationState::QUEUED && $op->state !== OperationState::FAILED_RETRYABLE) {
                throw new RuntimeException("Operation cannot run from state '{$op->state}'.");
            }

            if ($op->state === OperationState::FAILED_RETRYABLE) {
                $op->transitionTo(OperationState::QUEUED)->save();
            }

            $op->transitionTo(OperationState::RUNNING);
            $op->attempt_count = (int) $op->attempt_count + 1;
            $op->started_at = now();
            $op->save();

            $this->events->recordStateChange(
                $wsId, $op->owner_type, $op->id,
                OperationState::QUEUED, OperationState::RUNNING,
                $op->actor_user_id, 'task', ['attempt' => $op->attempt_count], $op->id
            );

            try {
                return $this->runHostingProvision($wsId, $op, $params);
            } catch (Throwable $e) {
                // An unexpected exception is treated as retryable — the provider
                // outcome is unknown, so we do not declare terminal failure.
                return $this->failOperation($wsId, $op, 'unexpected_error',
                    'The operation could not be completed. Support has been notified.',
                    ProviderResult::failed('unexpected_error', $e->getMessage(), 'retryable'));
            }
        });
    }

    private function runHostingProvision(int $wsId, InfraOperation $op, array $params): array
    {
        $meta = Registry::get(Registry::OP_PROVISION_HOSTING);
        $spec = (array) ($op->request_json ?? []);

        /** @var HostingProviderConnector $connector */
        $connector = $this->connectors->resolve($meta['connector_capability']);

        if (!$connector instanceof HostingProviderConnector) {
            throw new RuntimeException('Resolved connector does not satisfy the hosting contract.');
        }

        $op->provider = $connector->provider();
        $op->save();

        // The connector receives OUR idempotency key so a provider-side retry is
        // also deduplicated.
        $result = $connector->provisionHosting($spec, (string) $op->idempotency_key);

        if (!$result->success) {
            return $this->failOperation(
                $wsId, $op,
                $result->errorCode ?? 'provider_error',
                $result->errorSummary ?? 'The hosting service could not be set up.',
                $result
            );
        }

        // ---- Persist normalized result ---------------------------------
        return DB::transaction(function () use ($wsId, $op, $result, $spec, $connector) {
            $account = InfraHostingAccount::create([
                'workspace_id'   => $wsId,
                'name'           => $spec['name'],
                'state'          => HostingState::PROVISIONING,
                'region'         => $spec['region'] ?? null,
                'environment'    => $spec['environment'] ?? 'production',
                'created_by'     => $op->actor_user_id,
            ]);

            $resource = InfraProviderResource::create([
                'workspace_id'           => $wsId,
                'owner_type'             => 'hosting_account',
                'owner_id'               => $account->id,
                'provider'               => $connector->provider(),
                'provider_resource_type' => 'hosting_account',
                // Persisted, not discarded — the exact defect found in
                // CustomDomainService::connect():66.
                'provider_resource_id'   => $result->providerResourceId,
                'normalized_state'       => $result->normalizedState,
                'provider_state'         => $result->providerState,
                'last_synced_at'         => now(),
                'last_operation_id'      => $op->id,
                'idempotency_reference'  => $op->idempotency_key,
                'provider_metadata_json' => $this->events->redact($result->data),
            ]);

            // The connector reported acceptance, not verified reality. Only a
            // verified result may present as active.
            if ($result->verified) {
                $account->transitionTo(HostingState::CONFIGURING)->save();
                $account->transitionTo(HostingState::ACTIVE);
                $account->provisioned_at = now();
                $account->save();
            }

            $op->owner_id = $account->id;
            $op->provider_correlation_id = $result->correlationId;
            $op->result_json = $this->events->redact($result->toArray());
            $op->transitionTo(OperationState::SUCCEEDED);
            $op->finished_at = now();
            $op->save();

            $this->events->record(
                workspaceId: $wsId,
                event: 'operation_succeeded',
                ownerType: 'hosting_account',
                ownerId: $account->id,
                severity: InfraEvent::SEVERITY_SUCCESS,
                fromState: OperationState::RUNNING,
                toState: OperationState::SUCCEEDED,
                summary: $result->verified
                    ? 'Hosting service is active.'
                    : 'Hosting service accepted by the provider and is being set up.',
                context: ['verified' => $result->verified, 'account_id' => $account->id],
                actorUserId: $op->actor_user_id,
                source: 'task',
                operationId: $op->id,
                provider: $connector->provider(),
                providerResourceId: $result->providerResourceId,
            );

            return [
                'success'              => true,
                'operation_id'         => $op->id,
                'state'                => $op->state,
                'hosting_account_id'   => $account->id,
                'provider_resource_id' => $resource->provider_resource_id,
                'verified'             => $result->verified,
            ];
        });
    }

    private function failOperation(
        int $wsId,
        InfraOperation $op,
        string $code,
        string $safeSummary,
        ProviderResult $result
    ): array {
        $retryable = $result->isRetryable() && (int) $op->attempt_count < (int) $op->max_attempts;
        $target = $retryable ? OperationState::FAILED_RETRYABLE : OperationState::FAILED_TERMINAL;

        $op->transitionTo($target);
        $op->failure_code = $code;
        // Customer-facing text only — no vendor names, no stack traces, no URLs.
        $op->failure_summary = $safeSummary;
        $op->retry_classification = $retryable
            ? InfraOperation::RETRY_RETRYABLE
            : InfraOperation::RETRY_PERMANENT;
        $op->next_retry_at = $retryable ? now()->addSeconds(30 * (int) $op->attempt_count) : null;
        $op->result_json = $this->events->redact($result->toArray());
        $op->finished_at = $retryable ? null : now();
        $op->save();

        $this->events->record(
            workspaceId: $wsId,
            event: 'operation_failed',
            ownerType: $op->owner_type,
            ownerId: $op->id,
            severity: InfraEvent::SEVERITY_ERROR,
            fromState: OperationState::RUNNING,
            toState: $target,
            summary: $safeSummary,
            context: ['failure_code' => $code, 'attempt' => $op->attempt_count, 'retryable' => $retryable],
            actorUserId: $op->actor_user_id,
            source: 'task',
            operationId: $op->id,
            provider: $op->provider,
        );

        return [
            'success'      => false,
            'operation_id' => $op->id,
            'state'        => $op->state,
            'retryable'    => $retryable,
            'message'      => $safeSummary,
        ];
    }

    // --------------------------------------------------------------- HELPERS

    /**
     * Validate and NARROW the payload. Anything not explicitly allowed is
     * dropped — a caller must never be able to smuggle provider credentials or
     * arbitrary fields into a persisted record.
     */
    public function validateHostingPayload(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('A service name between 1 and 120 characters is required.');
        }

        $environment = (string) ($payload['environment'] ?? 'production');

        if (!in_array($environment, ['production', 'staging'], true)) {
            throw new InvalidArgumentException('Environment must be production or staging.');
        }

        $clean = ['name' => $name, 'environment' => $environment];

        if (!empty($payload['region'])) {
            $region = (string) $payload['region'];
            if (!preg_match('/^[a-z0-9\-]{2,32}$/i', $region)) {
                throw new InvalidArgumentException('Region format is not valid.');
            }
            $clean['region'] = $region;
        }

        // INFRA888 hosting productization (2026-07-23) — OPTIONAL customer
        // selections from the hosting wizard. Both are additive: requests that
        // omit them behave exactly as before.
        //
        // Subdomain rules are NOT reimplemented here. SubdomainService is the
        // single canonical authority (Builder's two route closures are to be
        // migrated onto it last, per the locked implementation order), so the
        // hosting path and the Builder path cannot drift.
        if (isset($payload['website_id']) && $payload['website_id'] !== '' && $payload['website_id'] !== null) {
            if (!is_numeric($payload['website_id']) || (int) $payload['website_id'] < 1) {
                throw new InvalidArgumentException('Select a valid website.');
            }
            $clean['website_id'] = (int) $payload['website_id'];
        }

        if (isset($payload['subdomain']) && trim((string) $payload['subdomain']) !== '') {
            // Throws InvalidArgumentException with a customer-safe message,
            // which hostingStore() already maps to a 422 VALIDATION_FAILED.
            $clean['subdomain'] = app(SubdomainService::class)
                ->requireAvailable((string) $payload['subdomain'], $clean['website_id'] ?? null);
        }
        // Deterministic simulation hook for the Null connector. Never honoured
        // outside non-production environments.
        if (!empty($payload['simulate']) && !app()->environment('production')) {
            $sim = (string) $payload['simulate'];
            if (in_array($sim, ['success', 'retryable_failure', 'terminal_failure'], true)) {
                $clean['simulate'] = $sim;
            }
        }

        return $clean;
    }

    private function deriveIdempotencyKey(int $wsId, string $operation, array $payload): string
    {
        return substr(hash('sha256', $wsId . '|' . $operation . '|' . json_encode($payload) . '|' . microtime(true)), 0, 48);
    }
}
