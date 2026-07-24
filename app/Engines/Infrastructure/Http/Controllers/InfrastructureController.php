<?php

namespace App\Engines\Infrastructure\Http\Controllers;

use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Policies\InfraHostingAccountPolicy;
use App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Services\HostingService;
use App\Engines\Infrastructure\Services\InfraEventRecorder;
use App\Engines\Infrastructure\Services\InfrastructureService;
use App\Engines\Infrastructure\Services\ProvisioningService;
use App\Core\Tenancy\WorkspaceContext;
use App\Http\Controllers\Api\BaseEngineController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * INFRA888 HTTP surface.
 *
 * Thin by design (directive §11): validation and orchestration live in services.
 * The controller resolves identity, enforces policy, delegates, and maps errors.
 *
 * Phase 1B adds exactly ONE write endpoint — hosting provisioning through a Null
 * connector. Suspend, terminate, restore, domain and email writes are
 * deliberately NOT exposed until this lifecycle is proven (directive §15).
 */
class InfrastructureController extends BaseEngineController
{
    public function __construct(
        private readonly InfrastructureService $infrastructure,
        private readonly HostingService $hosting,
        private readonly ProvisioningService $provisioning,
        private readonly InfraEventRecorder $events,
    ) {
    }

    protected function engineSlug(): string
    {
        return Registry::ENGINE;
    }

    // ------------------------------------------------------------------ READS

    public function overview(Request $r): JsonResponse
    {
        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'infrastructure', null);
        }

        $wsId = $this->wsId($r);
        $data = $this->infrastructure->overview($wsId);
        $data['entitlement'] = app(\App\Engines\Infrastructure\Services\InfrastructureEntitlementService::class)
            ->summary($wsId);

        return $this->readJson(['success' => true, 'data' => $data]);
    }

    public function activity(Request $r): JsonResponse
    {
        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'infrastructure', null);
        }

        return $this->readJson([
            'success' => true,
            'data'    => $this->infrastructure->recentActivity($this->wsId($r), (int) $r->input('limit', 20)),
        ]);
    }

    public function hostingIndex(Request $r): JsonResponse
    {
        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'hosting_account', null);
        }

        return $this->readJson([
            'success' => true,
            'data'    => $this->hosting->list($this->wsId($r), [
                'state'  => $r->input('state'),
                'search' => $r->input('search'),
            ]),
        ]);
    }

    public function hostingShow(Request $r, int $id): JsonResponse
    {
        $wsId = $this->wsId($r);

        try {
            $account = $this->hosting->find($wsId, $id);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        if (!app(InfraHostingAccountPolicy::class)->view($r->user(), $account)) {
            return $this->denied($r, 'hosting_account', $account->id);
        }

        return $this->readJson(['success' => true, 'data' => $this->hosting->detail($wsId, $id)]);
    }

    public function domainsIndex(Request $r): JsonResponse
    {
        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'domain', null);
        }

        return $this->readJson(['success' => true, 'data' => ['items' => [], 'total' => 0, 'available' => false]]);
    }

    public function emailIndex(Request $r): JsonResponse
    {
        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'mailbox', null);
        }

        return $this->readJson(['success' => true, 'data' => ['items' => [], 'total' => 0, 'available' => false]]);
    }

    /** GET /api/infrastructure/operations/{id} — request/approval/execution state. */
    public function operationShow(Request $r, int $id): JsonResponse
    {
        $wsId = $this->wsId($r);

        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'operation', null);
        }

        $op = WorkspaceContext::run($wsId, fn () => InfraOperation::query()->find($id));

        if (!$op) {
            return $this->notFound();
        }

        return $this->readJson(['success' => true, 'data' => $this->presentOperation($op)]);
    }

    public function operationsIndex(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);

        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'operation', null);
        }

        $ops = WorkspaceContext::run($wsId, fn () => InfraOperation::query()
            ->orderByDesc('id')->limit(50)->get());

        return $this->readJson([
            'success' => true,
            'data'    => ['items' => $ops->map(fn ($o) => $this->presentOperation($o))->values()->all()],
        ]);
    }

    /**
     * GET /api/infrastructure/operations/{id}/timeline
     *
     * Human-readable activity for one operation, built from linked infra_events.
     * Workspace-scoped: the operation is resolved under the workspace scope
     * FIRST, so a cross-tenant id yields 404 and never leaks event data.
     */
    public function operationTimeline(Request $r, int $id): JsonResponse
    {
        $wsId = $this->wsId($r);

        if (!$this->authorizeInfra($r, 'viewAny')) {
            return $this->denied($r, 'operation', null);
        }

        $payload = WorkspaceContext::run($wsId, function () use ($id) {
            $op = InfraOperation::query()->find($id);
            if (!$op) { return null; }

            $events = \App\Engines\Infrastructure\Models\InfraEvent::query()
                ->where('operation_id', $op->id)
                ->orderBy('created_at')
                ->limit(100)
                ->get();

            return [
                'operation' => $this->presentOperation($op),
                'events' => $events->map(fn ($e) => [
                    'id'         => $e->id,
                    'label'      => $this->humanizeEvent((string) $e->event),
                    'severity'   => $e->severity,
                    'from_state' => $e->from_state,
                    'to_state'   => $e->to_state,
                    'summary'    => $e->summary,       // already customer-safe
                    'actor_id'   => $e->actor_user_id,
                    'source'     => $e->source,
                    'at'         => optional($e->created_at)->toIso8601String(),
                ])->values()->all(),
            ];
        });

        if (!$payload) { return $this->notFound(); }

        return $this->readJson(['success' => true, 'data' => $payload]);
    }

    /** Slugs never reach the customer (no-schema-leakage rule). */
    private function humanizeEvent(string $event): string
    {
        return match ($event) {
            'operation_requested' => 'Request submitted',
            'state_changed'       => 'Status updated',
            'operation_succeeded' => 'Completed',
            'operation_failed'    => 'Failed',
            'operation_reaped'    => 'Recovered by system check',
            'permission_denied'   => 'Access denied',
            default               => 'Activity',
        };
    }

    // ------------------------------------------------------------------ WRITE

    /**
     * POST /api/infrastructure/hosting
     *
     * Requests a hosting provisioning operation. Does NOT provision inline —
     * the capability is `protected`, so this returns 202 AWAITING_APPROVAL and
     * execution happens on the queue after a human approves.
     */
    public function hostingStore(Request $r): JsonResponse
    {
        $wsId = $this->wsId($r);
        $user = $r->user();

        // Owner-level: provisioning creates a billing relationship.
        if (!app(InfraHostingAccountPolicy::class)->create($user, $wsId)) {
            return $this->denied($r, 'hosting_account', null);
        }

        // Product entitlement is a SEPARATE control from role. An owner on an
        // unentitled plan is refused even though they hold the highest role and
        // could approve their own request.
        $ent = app(\App\Engines\Infrastructure\Services\InfrastructureEntitlementService::class)
            ->checkHostingProvision($wsId);

        if (!$ent['allowed']) {
            $this->events->recordDenial(
                workspaceId: $wsId,
                ownerType: 'hosting_account',
                ownerId: null,
                reason: 'Entitlement denied: ' . $ent['code'],
                actorUserId: $user?->id,
                context: ['limit' => $ent['limit'], 'used' => $ent['used']],
            );

            return response()->json([
                'success' => false,
                'error'   => $ent['message'],
                'code'    => 'PLAN_GATED',
            ], 403);
        }

        try {
            $payload = $this->provisioning->validateHostingPayload($r->all());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
                'code'    => 'VALIDATION_FAILED',
            ], 422);
        }

        // Client-supplied idempotency key is honoured; absent, one is derived.
        $idempotencyKey = $r->header('Idempotency-Key') ?: $r->input('idempotency_key');

        $requested = $this->provisioning->requestHostingProvision(
            $wsId, $user?->id, $payload, $idempotencyKey ? (string) $idempotencyKey : null
        );

        /** @var InfraOperation $op */
        $op = $requested['operation'];

        if ($requested['reused']) {
            // Idempotent replay — return the existing operation, do not re-request.
            return response()->json([
                'success'    => true,
                'idempotent' => true,
                'data'       => $this->presentOperation($op),
            ], 200);
        }

        // Governance: route through the platform kernel so capability checks,
        // plan gating and the approval queue all apply.
        $result = $this->executeAction(
            $r,
            Registry::OP_PROVISION_HOSTING,
            ['operation_id' => $op->id] + $payload,
            'manual',
            202
        );

        $decoded = json_decode($result->getContent(), true) ?: [];

        if (($decoded['code'] ?? null) === 'AWAITING_APPROVAL' || !empty($decoded['pending_approval'])) {
            $this->provisioning->markAwaitingApproval(
                $wsId, $op->id,
                isset($decoded['approval_id']) ? (int) $decoded['approval_id'] : null,
                isset($decoded['task_id']) ? (int) $decoded['task_id'] : null
            );
        }

        return response()->json([
            'success'   => true,
            'code'      => $decoded['code'] ?? 'AWAITING_APPROVAL',
            'message'   => 'Your hosting request has been submitted for approval.',
            'data'      => $this->presentOperation(
                WorkspaceContext::run($wsId, fn () => InfraOperation::query()->find($op->id))
            ),
        ], 202);
    }

    // ----------------------------------------------------------------- HELPERS

    private function presentOperation(InfraOperation $op): array
    {
        return [
            'id'            => $op->id,
            'operation'     => $op->operation,
            'state'         => $op->state,
            'resource_type' => $op->owner_type,
            'resource_id'   => $op->owner_id,
            'attempt'       => (int) $op->attempt_count,
            'max_attempts'  => (int) $op->max_attempts,
            'retryable'     => $op->retry_classification === InfraOperation::RETRY_RETRYABLE,
            'failure'       => $op->failure_summary,   // safe text only
            'requested_at'  => optional($op->created_at)->toIso8601String(),
            'started_at'    => optional($op->started_at)->toIso8601String(),
            'finished_at'   => optional($op->finished_at)->toIso8601String(),
            'awaiting_approval' => $op->state === 'awaiting_approval',
        ];
    }

    private function authorizeInfra(Request $r, string $ability): bool
    {
        return app(InfraHostingAccountPolicy::class)->{$ability}($r->user(), $this->wsId($r));
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => 'Resource not found',
            'code'    => 'NOT_FOUND',
        ], 404);
    }

    private function denied(Request $r, string $ownerType, ?int $ownerId): JsonResponse
    {
        $this->events->recordDenial(
            workspaceId: $this->wsId($r),
            ownerType: $ownerType,
            ownerId: $ownerId,
            reason: 'Infrastructure access denied for this role or workspace.',
            actorUserId: $r->user()?->id,
        );

        return response()->json([
            'success' => false,
            'error'   => 'You do not have permission to perform this infrastructure action.',
            'code'    => 'FORBIDDEN',
        ], 403);
    }
}
