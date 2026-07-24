<?php

namespace App\Engines\Infrastructure\Http\Controllers;

use App\Core\Governance\ApprovalAuthorizationService;
use App\Core\Governance\ApprovalPolicyRegistry;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCapability;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Models\InfraProviderHealth;
use App\Engines\Infrastructure\Registry\CredentialPurposeRegistry;
use App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderEventRecorder;
use App\Engines\Infrastructure\Services\ProviderHealthService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\Services\ProviderResolutionService;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use App\Http\Controllers\Api\BaseEngineController;
use App\Models\Approval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Internal provider control-plane API (Phase 2B-G, Workstream 2).
 *
 * PLATFORM ADMINISTRATORS ONLY. Not a customer surface. Every route sits behind
 * `auth.jwt` + `admin` + `DenyApiKeyAuth`, so neither an API key nor the shared
 * admin token can reach any of it.
 *
 * THREE GOVERNANCE CLASSES, EXPLICIT PER ENDPOINT
 *
 *   DRAFT (self-service)      — cannot become operational or customer-visible:
 *                               create draft, edit draft, declare capability.
 *   PROTECTED (SoD)           — creates live provider authority: provider
 *                               activation, capability enablement, credential
 *                               activation, credential rotation. Requester and
 *                               approver MUST be different humans.
 *   INCIDENT (unilateral)     — reduces authority: credential revocation,
 *                               provider/capability disablement. Immediate, no
 *                               approval, high-severity event, reason required.
 *
 * The incident class is deliberately frictionless. Requiring a second approver
 * to REVOKE a leaked credential would keep it live during exactly the moment
 * speed matters. Reducing authority is safe; granting it is not.
 *
 * Unknown or unclassified write actions fail closed — see requestProtected().
 */
class ProviderControlPlaneController extends BaseEngineController
{
    /** Required by BaseEngineController. */
    protected function engineSlug(): string
    {
        return InfrastructureCapabilityRegistry::ENGINE;
    }

    public function __construct(
        private readonly ProviderRegistryService $registry,
        private readonly ProviderCredentialService $credentials,
        private readonly ProviderHealthService $health,
        private readonly ProviderResolutionService $resolution,
        private readonly ProviderEventRecorder $events,
        private readonly ApprovalAuthorizationService $approvals,
    ) {
    }

    // ══════════════════════════════════════════════════════════════════════
    // READ
    // ══════════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        $providers = InfraProvider::with('capabilities')->orderBy('priority')->orderBy('id')->get();

        return response()->json(['providers' => $providers->map(fn ($p) => $this->presentProvider($p))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $provider = InfraProvider::with('capabilities')->find($id);

        if (!$provider) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'provider'    => $this->presentProvider($provider),
            'credentials' => InfraProviderCredential::where('provider_id', $provider->id)
                ->orderByDesc('id')->get()->map->toSafeArray(),
            'health'      => InfraProviderHealth::where('provider_id', $provider->id)->get(),
        ]);
    }

    public function healthIndex(Request $request): JsonResponse
    {
        $q = InfraProviderHealth::query()->with('provider');

        if ($request->filled('provider_id'))  { $q->where('provider_id', (int) $request->input('provider_id')); }
        if ($request->filled('capability'))   { $q->where('capability', $request->string('capability')); }
        if ($request->filled('environment'))  { $q->where('environment', $request->string('environment')); }

        return response()->json(['health' => $q->orderBy('provider_id')->get()->map(fn ($h) => [
            'provider_key'   => $h->provider?->provider_key,
            'capability'     => $h->capability,
            'environment'    => $h->environment,
            'health_state'   => $h->health_state,
            'selectable'     => $h->isSelectable(),
            'retryable'      => $h->isRetryable(),
            'consecutive_failures' => $h->consecutive_failures,
            'last_error_code'      => $h->last_error_code,
            'checked_at'     => optional($h->checked_at)->toIso8601String(),
            'probe_type'     => $h->probe_type,
        ])]);
    }

    /** Credential METADATA only. `toSafeArray()` never emits secret material. */
    public function credentials(Request $request): JsonResponse
    {
        $q = InfraProviderCredential::query();

        if ($request->filled('provider_id')) { $q->where('provider_id', (int) $request->input('provider_id')); }
        if ($request->filled('state'))       { $q->where('state', $request->string('state')); }

        return response()->json([
            'credentials' => $q->orderByDesc('id')->limit(200)->get()->map->toSafeArray(),
            'purposes'    => CredentialPurposeRegistry::all(),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $q = InfraProviderEvent::query();

        if ($request->filled('provider_id'))    { $q->where('provider_id', (int) $request->input('provider_id')); }
        if ($request->filled('capability'))     { $q->where('capability', $request->string('capability')); }
        if ($request->filled('credential_id'))  { $q->where('credential_id', (int) $request->input('credential_id')); }
        if ($request->filled('correlation_id')) { $q->where('correlation_id', $request->string('correlation_id')); }
        if ($request->filled('severity'))       { $q->where('severity', $request->string('severity')); }

        return response()->json([
            'events' => $q->orderByDesc('id')->limit((int) $request->input('limit', 100))->get(),
        ]);
    }

    /** Why a capability does or does not currently resolve. Diagnosis, not a guess. */
    public function resolutionDiagnostic(Request $request): JsonResponse
    {
        $capability  = (string) $request->input('capability', '');
        $environment = (string) $request->input('environment', InfraProvider::ENV_SANDBOX);

        try {
            $candidates = $this->resolution->candidates($capability, $environment);
        } catch (Throwable $e) {
            return response()->json(['resolvable' => false, 'reason' => $e->getMessage()], 200);
        }

        if ($candidates === []) {
            try {
                $this->resolution->resolve($capability, $environment);
            } catch (Throwable $e) {
                return response()->json(['resolvable' => false, 'reason' => $e->getMessage()]);
            }
        }

        return response()->json([
            'resolvable' => $candidates !== [],
            'candidates' => array_map(fn ($c) => [
                'provider_key' => $c['provider']->provider_key,
                'priority'     => $c['provider']->priority,
                'health'       => $c['health']?->health_state,
                'credential'   => $c['credential']?->credential_key,
            ], $candidates),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // DRAFT — self-service (cannot become operational)
    // ══════════════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_key'   => ['required', 'string', 'max:64'],
            'display_name'   => ['required', 'string', 'max:120'],
            'provider_type'  => ['required', 'string', 'max:32'],
            'environments'   => ['sometimes', 'array'],
            'regions'        => ['sometimes', 'array'],
            'priority'       => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'sandbox_ready'  => ['sometimes', 'boolean'],
        ]);

        try {
            $provider = $this->registry->register([
                'provider_key'      => $data['provider_key'],
                'display_name'      => $data['display_name'],
                'provider_type'     => $data['provider_type'],
                'environments_json' => $data['environments'] ?? [InfraProvider::ENV_SANDBOX],
                'regions_json'      => $data['regions'] ?? [],
                'priority'          => $data['priority'] ?? 100,
                'sandbox_ready'     => $data['sandbox_ready'] ?? false,
            ], $request->user()?->id);
        } catch (Throwable $e) {
            return response()->json(['error' => 'registration_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['provider' => $this->presentProvider($provider)], 201);
    }

    public function declareCapability(Request $request, int $id): JsonResponse
    {
        $provider = InfraProvider::find($id);
        if (!$provider) { return response()->json(['error' => 'not_found'], 404); }

        $data = $request->validate([
            'capability'  => ['required', 'string', 'max:64'],
            'environment' => ['sometimes', 'string', 'max:32'],
            'supported'   => ['sometimes', 'boolean'],
        ]);

        try {
            $row = $this->registry->declareCapability(
                $provider,
                $data['capability'],
                $data['environment'] ?? InfraProvider::ENV_SANDBOX,
                ['supported' => $data['supported'] ?? true],
                $request->user()?->id
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'declare_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['capability' => $row], 201);
    }

    /** draft -> testing. Still not live; nothing routes to a testing provider in production. */
    public function moveToTesting(Request $request, int $id): JsonResponse
    {
        $provider = InfraProvider::find($id);
        if (!$provider) { return response()->json(['error' => 'not_found'], 404); }

        try {
            $provider = $this->registry->transition(
                $provider, ProviderLifecycleState::TESTING, $request->user()?->id,
                $request->input('reason')
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'transition_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['provider' => $this->presentProvider($provider)]);
    }

    public function storeCredential(Request $request, int $id): JsonResponse
    {
        $provider = InfraProvider::find($id);
        if (!$provider) { return response()->json(['error' => 'not_found'], 404); }

        $data = $request->validate([
            'credential_key'   => ['required', 'string', 'max:96'],
            'secret'           => ['required', 'string', 'min:8'],
            'capability_scope' => ['required', 'array', 'min:1'],
            'environment'      => ['sometimes', 'string', 'max:32'],
            'purpose'          => ['sometimes', 'string', 'max:64'],
            'expires_at'       => ['sometimes', 'nullable', 'date'],
        ]);

        try {
            $credential = $this->credentials->create(
                $provider,
                $data['credential_key'],
                $data['secret'],
                [
                    'capability_scope_json' => $data['capability_scope'],
                    'environment'           => $data['environment'] ?? InfraProvider::ENV_SANDBOX,
                    'purpose'               => $data['purpose'] ?? null,
                    'expires_at'            => $data['expires_at'] ?? null,
                ],
                $request->user()?->id
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'credential_rejected', 'message' => $e->getMessage()], 422);
        }

        // 🔴 The submitted secret is never echoed back, not even once.
        return response()->json(['credential' => $credential->toSafeArray()], 201);
    }

    public function verifyCredential(Request $request, int $credentialId): JsonResponse
    {
        $credential = InfraProviderCredential::find($credentialId);
        if (!$credential) { return response()->json(['error' => 'not_found'], 404); }

        try {
            $result = $this->credentials->verify($credential, null, $request->user()?->id);
        } catch (Throwable $e) {
            return response()->json(['error' => 'verification_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'attempted'            => $result->attempted,
            'authenticated'        => $result->authenticated,
            'permits_activation'   => $result->permitsActivation(),
            'granted_capabilities' => $result->grantedCapabilities,
            'missing_capabilities' => $result->missingCapabilities,
            'account_identifier'   => $result->accountIdentifier,
            'code'                 => $result->code,
            'summary'              => $result->summary,
            'credential'           => $credential->fresh()->toSafeArray(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // PROTECTED — separation of duties
    // ══════════════════════════════════════════════════════════════════════

    public function requestActivateProvider(Request $request, int $id): JsonResponse
    {
        return $this->requestProtected($request, InfrastructureCapabilityRegistry::OP_ACTIVATE_PROVIDER,
            ['provider_id' => $id]);
    }

    public function requestEnableCapability(Request $request, int $id): JsonResponse
    {
        return $this->requestProtected($request, InfrastructureCapabilityRegistry::OP_ENABLE_PROVIDER_CAPABILITY, [
            'provider_id' => $id,
            'capability'  => (string) $request->input('capability'),
            'environment' => (string) $request->input('environment', InfraProvider::ENV_SANDBOX),
        ]);
    }

    public function requestActivateCredential(Request $request, int $credentialId): JsonResponse
    {
        return $this->requestProtected($request, InfrastructureCapabilityRegistry::OP_ACTIVATE_CREDENTIAL,
            ['credential_id' => $credentialId]);
    }

    public function requestRotateCredential(Request $request, int $credentialId): JsonResponse
    {
        $request->validate(['secret' => ['required', 'string', 'min:8']]);

        // The new secret is stored immediately as an inert pending credential
        // rather than parked in the approval payload — approval rows are widely
        // read and must never carry secret material.
        $current = InfraProviderCredential::find($credentialId);
        if (!$current) { return response()->json(['error' => 'not_found'], 404); }

        try {
            $replacement = $this->credentials->beginRotation(
                $current, (string) $request->input('secret'), [], $request->user()?->id
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'rotation_rejected', 'message' => $e->getMessage()], 422);
        }

        return $this->requestProtected($request, InfrastructureCapabilityRegistry::OP_ROTATE_CREDENTIAL, [
            'credential_id'             => $current->id,
            'replacement_credential_id' => $replacement->id,
        ], extra: ['replacement' => $replacement->toSafeArray()]);
    }

    /**
     * Approve and execute a protected request.
     *
     * Authorization is delegated entirely to ApprovalAuthorizationService — the
     * single authority. This controller never re-implements a role or
     * self-approval check, because two implementations would eventually drift
     * and one of them would be the permissive one.
     */
    public function approve(Request $request, int $approvalId): JsonResponse
    {
        $approval = Approval::find($approvalId);

        if (!$approval || $approval->status !== 'pending') {
            return response()->json(['error' => 'not_pending'], 404);
        }

        $verdict = $this->approvals->authorize($approval, $request);

        if (!$verdict['allowed']) {
            $this->events->recordDenial(
                (string) $approval->action,
                null,
                (string) ($verdict['message'] ?? $verdict['code']),
                $request->user()?->id,
                ['approval_id' => $approval->id, 'code' => $verdict['code']]
            );

            return response()->json([
                'error'   => $verdict['code'],
                'message' => $verdict['message'],
            ], 403);
        }

        try {
            $outcome = DB::transaction(function () use ($approval, $request) {
                $result = $this->executeApproved($approval, $request->user()?->id);

                $approval->update([
                    'status'              => 'approved',
                    'decision_by'         => $request->user()?->id,
                    'decision_actor_type' => 'user',
                    'decided_at'          => now(),
                    'decision_note'       => $request->input('note'),
                ]);

                return $result;
            });
        } catch (Throwable $e) {
            return response()->json(['error' => 'execution_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['approved' => true, 'result' => $outcome]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // INCIDENT — immediate, unilateral, no approval
    // ══════════════════════════════════════════════════════════════════════

    public function revokeCredential(Request $request, int $credentialId): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:191']]);

        $credential = InfraProviderCredential::find($credentialId);
        if (!$credential) { return response()->json(['error' => 'not_found'], 404); }

        try {
            $revoked = $this->credentials->revoke(
                $credential, (string) $request->input('reason'), $request->user()?->id
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'revoke_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'credential' => $revoked->toSafeArray(),
            'note'       => 'Revocation is immediate and requires no second approval. '
                          . 'Reducing authority is safe; granting it is not.',
        ]);
    }

    public function disableCapability(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'capability' => ['required', 'string', 'max:64'],
            'reason'     => ['required', 'string', 'max:191'],
        ]);

        $provider = InfraProvider::find($id);
        if (!$provider) { return response()->json(['error' => 'not_found'], 404); }

        try {
            $row = $this->registry->disableCapability(
                $provider,
                (string) $request->input('capability'),
                (string) $request->input('environment', InfraProvider::ENV_SANDBOX),
                (string) $request->input('reason'),
                $request->user()?->id
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'disable_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['capability' => $row]);
    }

    public function disableProvider(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:191']]);

        $provider = InfraProvider::find($id);
        if (!$provider) { return response()->json(['error' => 'not_found'], 404); }

        try {
            $provider = $this->registry->transition(
                $provider, ProviderLifecycleState::DISABLED,
                $request->user()?->id, (string) $request->input('reason')
            );
        } catch (Throwable $e) {
            return response()->json(['error' => 'disable_failed', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['provider' => $this->presentProvider($provider)]);
    }

    /** Read-only manual probe. Must never mutate provider infrastructure. */
    public function probe(Request $request, int $id): JsonResponse
    {
        $provider = InfraProvider::find($id);
        if (!$provider) { return response()->json(['error' => 'not_found'], 404); }

        $capability  = (string) $request->input('capability');
        $environment = (string) $request->input('environment', InfraProvider::ENV_SANDBOX);

        $credential = $this->resolution->usableCredential($provider, $capability, $environment);

        if (!$credential) {
            $row = $this->health->recordFailure(
                $provider, $capability, $environment,
                'no_usable_credential', 'No active credential is scoped to this capability.',
                InfraProviderHealth::PROBE_MANUAL, [], $request->user()?->id
            );

            return response()->json(['health' => $row, 'probed' => false]);
        }

        $result = $this->credentials->verify($credential, null, $request->user()?->id);

        $row = $result->permitsActivation()
            ? $this->health->recordSuccess($provider, $capability, $environment, null,
                InfraProviderHealth::PROBE_MANUAL, $request->user()?->id)
            : $this->health->recordFailure($provider, $capability, $environment,
                (string) ($result->code ?? 'unknown'), (string) $result->summary,
                InfraProviderHealth::PROBE_MANUAL, $result->diagnostics, $request->user()?->id);

        return response()->json(['health' => $row, 'probed' => $result->attempted]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // internals
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Create a pending approval for a protected operation.
     *
     * FAILS CLOSED: an operation absent from InfrastructureCapabilityRegistry,
     * or present but not `protected`, is refused outright rather than executed
     * directly. An unclassified write must never take the ungoverned path.
     */
    private function requestProtected(
        Request $request,
        string $operation,
        array $payload,
        array $extra = []
    ): JsonResponse {
        $meta = InfrastructureCapabilityRegistry::get($operation);

        if (!$meta || ($meta['approval_mode'] ?? null) !== 'protected') {
            return response()->json([
                'error'   => 'operation_not_governed',
                'message' => "Operation '{$operation}' is not a registered protected operation.",
            ], 422);
        }

        $policy = ApprovalPolicyRegistry::forCapability(
            InfrastructureCapabilityRegistry::ENGINE, $operation, 'protected'
        );

        $approval = Approval::create([
            // The workspace the JWT middleware resolved is authoritative. The
            // previous hardcoded fallback of 1 assumed workspace 1 always exists,
            // which is false in a fresh database and violated the approvals FK.
            'workspace_id'         => (int) ($request->attributes->get('workspace_id')
                ?: $request->user()?->current_workspace_id),
            'requested_by'         => $request->user()?->id,
            'requester_actor_type' => 'user',
            'engine'               => InfrastructureCapabilityRegistry::ENGINE,
            'action'               => $operation,
            'capability_key'       => $meta['capability_key'],
            'approval_policy_json' => $policy,
            'data_json'            => $payload,
            'status'               => 'pending',
        ]);

        // Tell the requester up front whether anyone can actually approve this,
        // rather than letting them discover it when every attempt is denied.
        $hasApprover = $this->approvals->hasEligibleApprover($approval);

        return response()->json(array_merge([
            'approval_id'    => $approval->id,
            'status'         => 'pending',
            'capability_key' => $meta['capability_key'],
            'classification' => $policy['classification'],
            'self_approval_allowed' => $policy['self_approval_allowed'],
            'eligible_approver_exists' => $hasApprover,
            'message' => $policy['self_approval_allowed']
                ? 'Pending confirmation.'
                : 'Pending approval by a DIFFERENT platform administrator.',
        ], $extra), 202);
    }

    /** Execute an approved protected operation. */
    private function executeApproved(Approval $approval, ?int $approverId): array
    {
        $data          = $approval->data_json ?? [];
        $correlationId = (string) Str::uuid();

        switch ($approval->action) {
            case InfrastructureCapabilityRegistry::OP_ACTIVATE_PROVIDER:
                $provider = InfraProvider::findOrFail($data['provider_id']);
                $provider = $this->registry->transition(
                    $provider, ProviderLifecycleState::ACTIVE, $approverId,
                    'approved via approval #' . $approval->id, $correlationId
                );
                $this->registry->setEnabled($provider, true, $approverId, 'provider activated');

                return ['provider' => $provider->fresh()->provider_key, 'state' => $provider->fresh()->lifecycle_state];

            case InfrastructureCapabilityRegistry::OP_ENABLE_PROVIDER_CAPABILITY:
                $provider = InfraProvider::findOrFail($data['provider_id']);
                $row = $this->registry->enableCapability(
                    $provider, $data['capability'], $data['environment'], $approverId, $correlationId
                );

                return ['capability' => $row->capability, 'enabled' => $row->enabled];

            case InfrastructureCapabilityRegistry::OP_ACTIVATE_CREDENTIAL:
                $credential = InfraProviderCredential::findOrFail($data['credential_id']);
                // Re-verify at approval time. The verification that accompanied
                // the request may be stale, and activation must rest on current
                // evidence rather than on evidence that was true when asked.
                $result = $this->credentials->verify($credential, null, $approverId, $correlationId);
                $active = $this->credentials->activate($credential->fresh(), $result, $approverId, $correlationId);

                return ['credential' => $active->credential_key, 'state' => $active->state];

            case InfrastructureCapabilityRegistry::OP_ROTATE_CREDENTIAL:
                $replacement = InfraProviderCredential::findOrFail($data['replacement_credential_id']);
                $result = $this->credentials->verify($replacement, null, $approverId, $correlationId);
                $this->credentials->activate($replacement->fresh(), $result, $approverId, $correlationId);
                $old = $this->credentials->completeRotation($replacement->fresh(), $approverId, $correlationId);

                return [
                    'replacement' => $replacement->fresh()->credential_key,
                    'superseded'  => $old->credential_key,
                ];
        }

        throw new \RuntimeException("No executor for approved action '{$approval->action}'.");
    }

    private function presentProvider(InfraProvider $p): array
    {
        return [
            'id'               => $p->id,
            'provider_key'     => $p->provider_key,
            'display_name'     => $p->display_name,
            'provider_type'    => $p->provider_type,
            'lifecycle_state'  => $p->lifecycle_state,
            'enabled'          => $p->enabled,
            'production_ready' => $p->production_ready,
            'sandbox_ready'    => $p->sandbox_ready,
            'priority'         => $p->priority,
            'environments'     => $p->environments_json ?? [],
            'regions'          => $p->regions_json ?? [],
            // adapter_class deliberately absent — $hidden on the model, and
            // never reconstructed here.
            'capabilities'     => $p->relationLoaded('capabilities')
                ? $p->capabilities->map(fn ($c) => [
                    'capability'   => $c->capability,
                    'environment'  => $c->environment,
                    'supported'    => $c->supported,
                    'enabled'      => $c->enabled,
                    'health_state' => $c->health_state,
                    'operational'  => $c->isOperational(),
                ])
                : [],
        ];
    }
}
