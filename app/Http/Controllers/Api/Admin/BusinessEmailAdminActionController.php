<?php

namespace App\Http\Controllers\Api\Admin;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminAccess as Access;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminGate as Gate;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\Support\BusinessEmailFailure as Failure;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INFRA888 · E3 — Business Email operator console. WRITE SIDE.
 *
 * ─── THE ONE RULE THIS CLASS EXISTS TO ENFORCE ──────────────────────────────
 *
 * It never talks to a provider. Not once. Every action here builds an
 * `EmailOperationContext` and hands it to `BusinessEmailEngine`, which owns
 * entitlement, custody, lifecycle, capability support, approval, idempotency,
 * the governed `infra_operations` record, the provider call and the
 * interpretation of its answer.
 *
 * A controller that called a connector directly would bypass all of it, and
 * would do so invisibly — the request would look identical from outside. An
 * architecture guard asserts no connector method is named in this file.
 *
 * ─── AND THE ONE IT REFUSES TO BREAK ────────────────────────────────────────
 *
 * It never manufactures success. The response is derived from the engine's
 * `ProviderResult` and nothing else. There is no branch in this class that
 * returns "done" without the engine having said so.
 */
class BusinessEmailAdminActionController
{
    public function __construct(
        private readonly BusinessEmailEngine $engine,
    ) {
    }

    /**
     * Admin action name => engine capability.
     *
     * An explicit map rather than accepting a capability slug from the request.
     * Accepting one would let a caller name any registered capability — and
     * later, any string that happened to resolve — which is how an operator
     * console becomes an arbitrary-command endpoint.
     *
     * @return array<string,string>
     */
    public static function actionMap(): array
    {
        return [
            'onboard'          => Registry::DOMAIN_ONBOARD,
            'verify-dns'       => Registry::DOMAIN_VERIFY,
            'sync-usage'       => Registry::USAGE_SYNC,
            'observe-health'   => Registry::HEALTH_OBSERVE,
            'create-mailbox'   => Registry::MAILBOX_CREATE,
            'update-mailbox'   => Registry::MAILBOX_UPDATE,
            'suspend-mailbox'  => Registry::MAILBOX_SUSPEND,
            'restore-mailbox'  => Registry::MAILBOX_RESTORE,
            'delete-mailbox'   => Registry::MAILBOX_DELETE,
            'reset-password'   => Registry::PASSWORD_RESET,
            'create-alias'     => Registry::ALIAS_CREATE,
            'delete-alias'     => Registry::ALIAS_DELETE,
            'create-forwarder' => Registry::FORWARDER_CREATE,
            'delete-forwarder' => Registry::FORWARDER_DELETE,
            'configure-catchall' => Registry::CATCHALL_CONFIGURE,
            'clear-catchall'   => Registry::CATCHALL_CLEAR,
        ];
    }

    /** Actions whose subject is the domain itself rather than a child object. */
    private static function domainScoped(): array
    {
        return ['onboard', 'verify-dns', 'sync-usage', 'observe-health'];
    }

    // ── entry points ─────────────────────────────────────────────────────────

    /**
     * POST /api/admin/business-email/domains/{id}/actions/{action}
     *
     * `subject_type` and `subject_id` name the child object for actions that
     * have one. Both are validated against the domain, so an operator cannot
     * act on one workspace's mailbox through another workspace's domain.
     */
    public function act(Request $request, int $domainId, string $action): JsonResponse
    {
        if ($deny = $this->deny($request, $action)) {
            return $deny;
        }

        $capability = self::actionMap()[$action];

        $domain = EmailDomain::withoutWorkspaceScope()->find($domainId);

        if ($domain === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $workspaceId = (int) $domain->workspace_id;

        $subject = null;

        if (! in_array($action, self::domainScoped(), true)) {
            $subject = $this->resolveSubject($request, $domain);

            if ($subject === null) {
                return $this->typedFailure(Failure::INVALID_CONTEXT, 422, [
                    'reason' => 'This action needs a subject_type and subject_id belonging to this domain.',
                ]);
            }
        }

        // Destructive actions must be confirmed explicitly. A console that
        // deletes a mailbox on a single click is one misclick from destroying
        // mail no rollback restores.
        if (Access::isElevated(Access::requiredFor($capability)) && ! $request->boolean('confirm')) {
            return response()->json([
                'error'  => 'confirmation_required',
                'reason' => 'This action destroys customer data and must be confirmed explicitly.',
                'confirm_with' => ['confirm' => true],
            ], 428);
        }

        $context = new EmailOperationContext(
            workspaceId: $workspaceId,
            capability: $capability,
            domain: $domain,
            subject: $subject,
            actorUserId: (int) ($request->user()?->id ?? 0) ?: null,
            source: 'admin',
            entitlements: $this->entitlementsFor($workspaceId),
            input: $this->inputFor($request),
            idempotencyKey: $this->idempotencyKey($request, $capability, $domainId, $subject),
            // The operator IS the approval. E4 replaces this with a real
            // approval record; until then the actor is recorded and the
            // separation-of-duties rule below still applies.
            approved: true,
            approvedBy: $this->approver($request, $capability),
        );

        $result = $this->engine->execute($context);

        $this->recordAdminDecision($context, $action, $result->success ? 'executed' : 'refused', $request);

        return $this->respond($result, $capability);
    }

    /**
     * POST /api/admin/business-email/operations/{id}/resolve
     *
     * The manual-review workflow. `decision` is one of a fixed set; anything
     * else is refused rather than interpreted.
     */
    public function resolve(Request $request, int $operationId): JsonResponse
    {
        if (! Gate::isEnabled()) {
            return response()->json(['error' => 'not_available', 'reason' => Gate::unavailableReason()], 404);
        }

        if (! (new Access())->allows($request, Access::MANUAL_REVIEW)) {
            return response()->json(['error' => 'forbidden', 'capability' => Access::MANUAL_REVIEW], 403);
        }

        $decision = (string) $request->input('decision', '');
        $reason = trim((string) $request->input('reason', ''));

        if (! in_array($decision, ['confirm', 'acknowledge', 'retry'], true)) {
            return response()->json([
                'error'   => 'unknown_decision',
                'allowed' => ['confirm', 'acknowledge', 'retry'],
            ], 422);
        }

        // A manual decision with no stated reason is an audit entry that
        // explains nothing six months later.
        if ($reason === '') {
            return response()->json([
                'error'  => 'reason_required',
                'reason' => 'Every manual resolution must record why it was taken.',
            ], 422);
        }

        $operation = InfraOperation::withoutWorkspaceScope()
            ->where('capability', BusinessEmailEngine::CAPABILITY)->find($operationId);

        if ($operation === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $ambiguous = in_array($operation->state, [
            OperationState::COMPENSATION_PENDING, OperationState::TIMED_OUT,
        ], true);

        // THE RULE THAT MATTERS MOST IN THIS FILE. An ambiguous mutation may
        // have taken effect. Re-issuing it can duplicate a billable mailbox and
        // split a customer's inbound mail. The engine already forbids it; this
        // refuses it at the console so the operator is told why rather than
        // finding the button greyed out.
        if ($decision === 'retry' && $ambiguous) {
            return response()->json([
                'error'  => 'retry_forbidden',
                'reason' => 'The outcome of this request is unknown. Re-issuing it could duplicate a billable '
                    . 'mailbox. Resolve it with a read-back instead.',
                'use_instead' => 'confirm',
            ], 409);
        }

        if ($decision === 'retry' && $operation->retry_classification !== 'retryable') {
            return response()->json([
                'error'  => 'retry_forbidden',
                'reason' => 'This operation is not classified as retryable.',
            ], 409);
        }

        $domain = EmailDomain::withoutWorkspaceScope()
            ->where('workspace_id', $operation->workspace_id)->find($this->domainIdFor($operation));

        if ($decision === 'acknowledge') {
            $this->recordResolution($operation, 'acknowledged', $reason, $request);

            return response()->json([
                'decision'  => 'acknowledged',
                // Acknowledging changes NOTHING about the operation's state.
                // It records that a human has seen it. Hiding an unresolved
                // condition behind an acknowledgement is how outages get missed.
                'operation_state' => $operation->state,
                'note' => 'Acknowledged. The operation remains unresolved until evidence settles it.',
            ]);
        }

        if ($domain === null) {
            return $this->typedFailure(Failure::INVALID_CONTEXT, 422, [
                'reason' => 'The domain this operation belongs to could not be resolved.',
            ]);
        }

        $context = new EmailOperationContext(
            workspaceId: (int) $operation->workspace_id,
            capability: (string) $operation->operation,
            domain: $domain,
            subject: $this->subjectForOperation($operation),
            actorUserId: (int) ($request->user()?->id ?? 0) ?: null,
            source: 'admin',
            entitlements: $this->entitlementsFor((int) $operation->workspace_id),
            idempotencyKey: (string) $operation->idempotency_key,
            approved: true,
            approvedBy: $this->approver($request, (string) $operation->operation),
        );

        $result = $decision === 'confirm'
            ? $this->engine->confirm($context)
            : $this->engine->execute($context);

        $this->recordResolution($operation->refresh(), $decision, $reason, $request);

        return $this->respond($result, (string) $operation->operation, ['decision' => $decision]);
    }

    // ── guards and helpers ───────────────────────────────────────────────────

    private function deny(Request $request, string $action): ?JsonResponse
    {
        if (! Gate::isEnabled()) {
            return response()->json(['error' => 'not_available', 'reason' => Gate::unavailableReason()], 404);
        }

        if (! array_key_exists($action, self::actionMap())) {
            return response()->json([
                'error'   => 'unknown_action',
                'allowed' => array_keys(self::actionMap()),
            ], 404);
        }

        $required = Access::requiredFor(self::actionMap()[$action]);

        if (! (new Access())->allows($request, $required)) {
            return response()->json([
                'error'      => 'forbidden',
                'capability' => $required,
                'reason'     => Access::isElevated($required)
                    ? 'This action is restricted to explicitly named operators.'
                    : 'This administrator does not hold the capability required for this action.',
            ], 403);
        }

        return null;
    }

    private function resolveSubject(Request $request, EmailDomain $domain): ?Model
    {
        $type = (string) $request->input('subject_type', '');
        $id = (int) $request->input('subject_id', 0);

        if ($id <= 0) {
            return null;
        }

        $model = match ($type) {
            'mailbox'   => EmailMailbox::withoutWorkspaceScope()->find($id),
            'alias'     => EmailAlias::withoutWorkspaceScope()->find($id),
            'forwarder' => EmailForwarder::withoutWorkspaceScope()->find($id),
            'catchall'  => EmailCatchAll::withoutWorkspaceScope()->find($id),
            default     => null,
        };

        // Cross-tenant defence: the subject must belong to THIS domain. Without
        // it an operator could act on another workspace's mailbox by naming its
        // id against a domain they can reach.
        if ($model === null || (int) $model->email_domain_id !== (int) $domain->id) {
            return null;
        }

        return $model;
    }

    /**
     * Entitlements for an admin-initiated action.
     *
     * Admin actions are not sold to the customer; they are operator
     * interventions, so plan access is granted here rather than resolved from
     * a subscription. Plan LIMITS are deliberately left unlimited (null) — an
     * operator repairing a customer's mail must not be blocked by a mailbox
     * count, and the engine still enforces every lifecycle and custody rule.
     * E4 replaces this when customers can act for themselves.
     */
    private function entitlementsFor(int $workspaceId): array
    {
        // `true`, not `null`. The two are different in EmailOperationContext and
        // the difference is load-bearing: hasEntitlement() reads null as NOT
        // GRANTED, while limit() reads `true` as GRANTED WITH NO CEILING.
        // Passing null here refused every mailbox create with
        // `email_not_entitled` — the capability's required entitlement IS the
        // mailbox-limit key, so "unlimited" spelled as null read as "no access".
        return [
            Registry::ENTITLEMENT_ACCESS        => true,
            Registry::ENTITLEMENT_MAILBOX_LIMIT => true,
            Registry::ENTITLEMENT_DOMAIN_LIMIT  => true,
        ];
    }

    /**
     * Only the fields the engine understands, and never anything resembling a
     * credential. A caller cannot smuggle a password into an operation record
     * by naming an unexpected key.
     */
    private function inputFor(Request $request): array
    {
        return array_filter(
            $request->only(['local_part', 'display_name', 'quota_mb', 'target_address', 'destination_address']),
            fn ($v) => $v !== null && $v !== ''
        );
    }

    private function idempotencyKey(Request $request, string $capability, int $domainId, ?Model $subject): ?string
    {
        $supplied = trim((string) $request->input('idempotency_key', ''));

        if ($supplied !== '') {
            return $supplied;
        }

        $meta = Registry::get($capability);

        // Derived-key capabilities compute their own; caller-key ones must be
        // given one, and the engine refuses to invent it. Deriving a stable key
        // from the subject here is the console's contribution: two clicks on
        // the same button are the same intent.
        if (($meta['idempotency'] ?? null) === Registry::IDEMPOTENCY_CALLER_KEY) {
            return 'admin:' . $capability . ':' . $domainId . ':' . ($subject?->getKey() ?? 'none');
        }

        return null;
    }

    /**
     * The approver for separation-of-duties capabilities.
     *
     * Returns a value distinct from the actor ONLY when the request names one.
     * The engine refuses self-approval on `email.mailbox.delete`, so an
     * operator deleting a mailbox must name a second person — which is the
     * point, and returning the actor here would silently defeat it.
     */
    private function approver(Request $request, string $capability): ?int
    {
        $meta = Registry::get($capability);

        if (($meta['approval_mode'] ?? null) === Registry::APPROVAL_SEPARATION_OF_DUTIES) {
            $named = (int) $request->input('approved_by', 0);

            return $named > 0 ? $named : null;
        }

        return (int) ($request->user()?->id ?? 0) ?: null;
    }

    private function domainIdFor(InfraOperation $operation): int
    {
        $request = (array) ($operation->request_json ?? []);

        return (int) ($request['domain_id'] ?? 0);
    }

    private function subjectForOperation(InfraOperation $operation): ?Model
    {
        $ownerId = (int) ($operation->owner_id ?? 0);

        if ($ownerId <= 0) {
            return null;
        }

        return match ($operation->owner_type) {
            EmailMailbox::OWNER_TYPE   => EmailMailbox::withoutWorkspaceScope()->find($ownerId),
            EmailAlias::OWNER_TYPE     => EmailAlias::withoutWorkspaceScope()->find($ownerId),
            EmailForwarder::OWNER_TYPE => EmailForwarder::withoutWorkspaceScope()->find($ownerId),
            EmailCatchAll::OWNER_TYPE  => EmailCatchAll::withoutWorkspaceScope()->find($ownerId),
            default => null,
        };
    }

    // ── responses ────────────────────────────────────────────────────────────

    /**
     * Derived entirely from the engine's answer.
     *
     * `verified` and `accepted` are reported as the different things they are.
     * A console that rendered both as "done" would undo the whole point of the
     * distinction: an accepted mailbox does not exist yet.
     */
    private function respond($result, string $capability, array $extra = []): JsonResponse
    {
        $meta = Registry::get($capability);

        $payload = [
            'capability' => $capability,
            'success'    => $result->success,
            'verified'   => $result->verified,
            'state'      => $result->normalizedState,
            'message'    => $result->errorSummary,
            'retry_classification' => $result->retryClassification,
            'failure_code' => $result->errorCode,
            'operation_id' => $result->data['operation_id'] ?? null,
        ] + $extra;

        // A password-reset result is never echoed, whatever it contained. The
        // contract already forbids an adapter returning credential material;
        // this is the second line, because "never" is cheap to assert twice.
        if (($meta['never_log_result'] ?? false) !== true) {
            $payload['data'] = $result->data;
        } else {
            $payload['data'] = ['redacted' => true];
            $payload['credential_note'] = 'The provider runs its own reset flow. No password is returned, '
                . 'stored or logged by LevelUp.';
        }

        $status = match (true) {
            $result->verified => 200,
            $result->success  => 202,
            $result->errorCode === Failure::PROVIDER_NOT_CONFIGURED => 503,
            $result->errorCode === Failure::CAPABILITY_NOT_SUPPORTED => 501,
            $result->errorCode === Failure::APPROVAL_REQUIRED => 428,
            $result->errorCode === Failure::CROSS_TENANT => 403,
            default => 422,
        };

        return response()->json($payload, $status)
            // A reset response must not sit in a shared cache even though it
            // carries nothing secret.
            ->header('Cache-Control', 'no-store, private');
    }

    private function typedFailure(string $code, int $status, array $extra = []): JsonResponse
    {
        return response()->json([
            'success'      => false,
            'verified'     => false,
            'failure_code' => $code,
            'message'      => Failure::message($code),
        ] + $extra, $status);
    }

    // ── audit ────────────────────────────────────────────────────────────────

    private function recordAdminDecision(EmailOperationContext $context, string $action, string $outcome, Request $request): void
    {
        WorkspaceContext::run($context->workspaceId, function () use ($context, $action, $outcome, $request) {
            InfraEvent::create([
                'workspace_id'  => $context->workspaceId,
                'owner_type'    => EmailDomain::OWNER_TYPE,
                'owner_id'      => $context->domain?->getKey(),
                'event'         => 'email.admin_action',
                'severity'      => $outcome === 'executed' ? InfraEvent::SEVERITY_INFO : InfraEvent::SEVERITY_WARNING,
                'actor_user_id' => $request->user()?->id,
                'source'        => 'admin',
                'provider'      => null,
                'summary'       => "Operator {$outcome} '{$action}'.",
                'context_json'  => [
                    'admin_action' => $action,
                    'capability'   => $context->capability,
                    'outcome'      => $outcome,
                    'subject_type' => $context->subject !== null ? $context->subject::class : null,
                    'subject_id'   => $context->subject?->getKey(),
                ],
                'created_at'    => now(),
            ]);
        });
    }

    private function recordResolution(InfraOperation $operation, string $decision, string $reason, Request $request): void
    {
        WorkspaceContext::run((int) $operation->workspace_id, function () use ($operation, $decision, $reason, $request) {
            InfraEvent::create([
                'workspace_id'  => (int) $operation->workspace_id,
                'owner_type'    => (string) $operation->owner_type,
                'owner_id'      => $operation->owner_id,
                'event'         => 'email.manual_review_resolved',
                'severity'      => InfraEvent::SEVERITY_WARNING,
                'operation_id'  => (int) $operation->id,
                'actor_user_id' => $request->user()?->id,
                'source'        => 'admin',
                'provider'      => null,
                'summary'       => "Operator resolution: {$decision}.",
                'context_json'  => [
                    'decision'        => $decision,
                    // Required, and recorded verbatim. A manual decision with
                    // no reason explains nothing six months later.
                    'reason'          => $reason,
                    'operation_state' => $operation->state,
                    'idempotency_key' => $operation->idempotency_key,
                ],
                'created_at'    => now(),
            ]);
        });
    }
}
