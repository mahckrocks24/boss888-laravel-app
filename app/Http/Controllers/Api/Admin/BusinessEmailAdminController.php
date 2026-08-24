<?php

namespace App\Http\Controllers\Api\Admin;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminAccess as Access;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminGate as Gate;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Models\EmailUsage;
use App\Engines\Infrastructure\Email\Reconciliation\EmailReconciliationService;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Models\InfraProviderResource;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · E3 — Business Email operator console. READ SIDE.
 *
 * Everything an operator needs to answer "what does this customer actually
 * have, what is it doing, and can we prove it?" — and nothing that mutates.
 * Governed actions live in BusinessEmailAdminActionController, physically
 * separate so a read route can never be mistaken for a write one.
 *
 * ─── WHAT AN OPERATOR MAY SEE THAT A CUSTOMER MAY NOT ───────────────────────
 *
 * Provider identity, provider references, provider capability support, raw
 * failure codes and the structured desired-vs-observed comparison. That is the
 * whole point of the two-plane split: an operator cannot diagnose a failed
 * provisioning without them, and a customer must never see any of them.
 *
 * Every projection here goes through the E1/E2 `toAdminArray()` methods. No
 * model is serialised directly, because a model serialised directly gains
 * whatever column the next migration adds — which is exactly how a provider
 * reference reaches a payload nobody meant it to reach.
 *
 * NEVER RETURNED, from any method: a credential, a password, a secret, a
 * credential fingerprint's source material, or a raw provider payload
 * containing any of those.
 */
class BusinessEmailAdminController
{
    public function __construct(
        private readonly BusinessEmailEngine $engine,
    ) {
    }

    // ── guard ────────────────────────────────────────────────────────────────

    /**
     * The gate and the capability, in that order.
     *
     * Returns a JsonResponse to send, or null to continue. The gate answers
     * first because a closed area has no capabilities to check — and because
     * the reply must not distinguish "you may not" from "there is nothing
     * here", which would confirm the module exists to someone who may not use it.
     */
    private function deny(Request $request, string $capability): ?JsonResponse
    {
        if (! Gate::isEnabled()) {
            return response()->json([
                'error'  => 'not_available',
                'reason' => Gate::unavailableReason(),
            ], 404);
        }

        if (! (new Access())->allows($request, $capability)) {
            return response()->json([
                'error'      => 'forbidden',
                'capability' => $capability,
                'reason'     => 'This administrator does not hold the capability required for this action.',
            ], 403);
        }

        return null;
    }

    private function pageSize(Request $request): int
    {
        $max = (int) config('business_email.admin_max_page_size', 200);
        $size = (int) $request->query('per_page', (int) config('business_email.admin_page_size', 50));

        return max(1, min($size, $max));
    }

    /** Read across tenants deliberately: this is the platform operator console. */
    private function unscoped(string $modelClass)
    {
        return $modelClass::withoutWorkspaceScope();
    }

    // ── overview ─────────────────────────────────────────────────────────────

    /** GET /api/admin/business-email/overview */
    public function overview(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $domainsByState = $this->unscoped(EmailDomain::class)
            ->selectRaw('lifecycle_state, count(*) as c')
            ->groupBy('lifecycle_state')->pluck('c', 'lifecycle_state')->all();

        $mailboxesByState = $this->unscoped(EmailMailbox::class)
            ->selectRaw('lifecycle_state, count(*) as c')
            ->groupBy('lifecycle_state')->pluck('c', 'lifecycle_state')->all();

        $unresolved = InfraOperation::withoutWorkspaceScope()
            ->where('capability', BusinessEmailEngine::CAPABILITY)
            ->whereIn('state', [
                OperationState::COMPENSATION_PENDING,
                OperationState::TIMED_OUT,
                OperationState::FAILED_RETRYABLE,
                OperationState::FAILED_TERMINAL,
            ])->count();

        $storage = (int) $this->unscoped(EmailMailbox::class)->sum('storage_used_mb');

        return response()->json([
            'screen'      => 'business_email_overview',
            'gate'        => ['enabled' => Gate::isEnabled()],
            'domains'     => ['by_lifecycle' => $domainsByState, 'total' => array_sum($domainsByState)],
            'mailboxes'   => ['by_lifecycle' => $mailboxesByState, 'total' => array_sum($mailboxesByState)],
            'storage_used_mb' => $storage,
            'operations'  => ['unresolved' => $unresolved],
            'provider'    => $this->providerSummary(),
            'observed_at' => now()->toIso8601String(),
        ]);
    }

    // ── domains ──────────────────────────────────────────────────────────────

    /** GET /api/admin/business-email/domains */
    public function domains(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $query = $this->unscoped(EmailDomain::class);

        if (($workspaceId = (int) $request->query('workspace_id', 0)) > 0) {
            $query->where('workspace_id', $workspaceId);
        }

        if (($state = trim((string) $request->query('lifecycle_state', ''))) !== '') {
            $query->where('lifecycle_state', $state);
        }

        if (($q = trim((string) $request->query('q', ''))) !== '') {
            $query->where('domain', 'like', '%' . $q . '%');
        }

        $sort = in_array($request->query('sort'), ['domain', 'lifecycle_state', 'created_at'], true)
            ? (string) $request->query('sort')
            : 'id';

        $page = $query->orderBy($sort, $request->query('dir') === 'asc' ? 'asc' : 'desc')
            ->paginate($this->pageSize($request));

        $rows = [];

        foreach ($page->items() as $domain) {
            $rows[] = $domain->toAdminArray() + [
                'mailbox_count' => $this->unscoped(EmailMailbox::class)
                    ->where('email_domain_id', $domain->id)->count(),
                'provider_binding' => $this->bindingSummary(BusinessEmailEngine::RESOURCE_DOMAIN, (int) $domain->id),
            ];
        }

        return response()->json([
            'domains' => $rows,
            'meta'    => $this->meta($page),
        ]);
    }

    /** GET /api/admin/business-email/domains/{id} */
    public function domain(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $domain = $this->unscoped(EmailDomain::class)->find($id);

        if ($domain === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $workspaceId = (int) $domain->workspace_id;

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($domain, $id, $workspaceId) {
            return [
                'domain'      => $domain->toAdminArray(),
                'dns_requirements' => $domain->settings_json['dns_requirements'] ?? [],
                'dns_observed_at'  => $domain->settings_json['dns_requirements_observed_at'] ?? null,
                'mailboxes'   => EmailMailbox::query()->where('email_domain_id', $id)->get()
                    ->map(fn (EmailMailbox $m) => $m->toAdminArray() + [
                        'address' => $m->address(),
                        'provider_binding' => $this->bindingSummary(BusinessEmailEngine::RESOURCE_MAILBOX, (int) $m->id),
                    ])->all(),
                'aliases'     => EmailAlias::query()->where('email_domain_id', $id)->get()
                    ->map(fn (EmailAlias $a) => $a->toAdminArray())->all(),
                'forwarders'  => EmailForwarder::query()->where('email_domain_id', $id)->get()
                    ->map(fn (EmailForwarder $f) => $f->toAdminArray())->all(),
                'catch_all'   => EmailCatchAll::query()->where('email_domain_id', $id)->first()?->toAdminArray(),
                'usage'       => EmailUsage::query()->where('email_domain_id', $id)
                    ->orderByDesc('observed_at')->limit(25)->get()
                    ->map(fn (EmailUsage $u) => $u->toAdminArray())->all(),
                'operations'  => $this->operationRows($workspaceId, BusinessEmailEngine::RESOURCE_DOMAIN, $id),
                'audit'       => $this->auditRows($workspaceId, $id),
                'capabilities' => $this->capabilitySupport(),
            ];
        }));
    }

    // ── mailboxes, aliases, forwarders ───────────────────────────────────────

    /** GET /api/admin/business-email/mailboxes */
    public function mailboxes(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $query = $this->unscoped(EmailMailbox::class)->with('domain');

        if (($domainId = (int) $request->query('email_domain_id', 0)) > 0) {
            $query->where('email_domain_id', $domainId);
        }

        if (($workspaceId = (int) $request->query('workspace_id', 0)) > 0) {
            $query->where('workspace_id', $workspaceId);
        }

        if (($state = trim((string) $request->query('lifecycle_state', ''))) !== '') {
            $query->where('lifecycle_state', $state);
        }

        $page = $query->orderByDesc('id')->paginate($this->pageSize($request));

        $rows = array_map(fn (EmailMailbox $m) => $m->toAdminArray() + [
            'address'          => $m->address(),
            'provider_binding' => $this->bindingSummary(BusinessEmailEngine::RESOURCE_MAILBOX, (int) $m->id),
        ], $page->items());

        return response()->json(['mailboxes' => $rows, 'meta' => $this->meta($page)]);
    }

    /** GET /api/admin/business-email/mailboxes/{id} */
    public function mailbox(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $mailbox = $this->unscoped(EmailMailbox::class)->with('domain')->find($id);

        if ($mailbox === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $workspaceId = (int) $mailbox->workspace_id;

        return response()->json([
            'mailbox'          => $mailbox->toAdminArray() + ['address' => $mailbox->address()],
            'provider_binding' => $this->bindingSummary(BusinessEmailEngine::RESOURCE_MAILBOX, $id),
            'operations'       => $this->operationRows($workspaceId, BusinessEmailEngine::RESOURCE_MAILBOX, $id),
            'usage'            => WorkspaceContext::run($workspaceId, fn () => EmailUsage::query()
                ->where('email_mailbox_id', $id)->orderByDesc('observed_at')->limit(25)->get()
                ->map(fn (EmailUsage $u) => $u->toAdminArray())->all()),
            // Deliberately absent: anything resembling a credential. There is
            // no stored password to show, and no endpoint that could reveal one.
            'credentials'      => ['stored' => false, 'note' => 'Authentication is owned by the provider. No password is stored here.'],
        ]);
    }

    /** GET /api/admin/business-email/routing */
    public function routing(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $domainId = (int) $request->query('email_domain_id', 0);

        $aliases = $this->unscoped(EmailAlias::class);
        $forwarders = $this->unscoped(EmailForwarder::class);
        $catchAlls = $this->unscoped(EmailCatchAll::class);

        if ($domainId > 0) {
            $aliases->where('email_domain_id', $domainId);
            $forwarders->where('email_domain_id', $domainId);
            $catchAlls->where('email_domain_id', $domainId);
        }

        return response()->json([
            'aliases'    => $aliases->orderByDesc('id')->limit(200)->get()
                ->map(fn (EmailAlias $a) => $a->toAdminArray())->all(),
            'forwarders' => $forwarders->orderByDesc('id')->limit(200)->get()
                ->map(fn (EmailForwarder $f) => $f->toAdminArray() + [
                    // Loop verdicts are an operator concern before they are a
                    // customer one: a blocked forwarder is why mail is not
                    // being relayed, and the reason must be legible here.
                    'loop_blocked' => $f->isLoopBlocked(),
                ])->all(),
            'catch_alls' => $catchAlls->orderByDesc('id')->limit(200)->get()
                ->map(fn (EmailCatchAll $c) => $c->toAdminArray())->all(),
        ]);
    }

    // ── operations ───────────────────────────────────────────────────────────

    /** GET /api/admin/business-email/operations */
    public function operations(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $query = InfraOperation::withoutWorkspaceScope()
            ->where('capability', BusinessEmailEngine::CAPABILITY);

        if (($workspaceId = (int) $request->query('workspace_id', 0)) > 0) {
            $query->where('workspace_id', $workspaceId);
        }

        if (($state = trim((string) $request->query('state', ''))) !== '') {
            $query->where('state', $state);
        }

        if ($request->boolean('unresolved')) {
            $query->whereIn('state', $this->unresolvedStates());
        }

        $page = $query->orderByDesc('id')->paginate($this->pageSize($request));

        return response()->json([
            'operations' => array_map(fn (InfraOperation $o) => $this->operationRow($o), $page->items()),
            'meta'       => $this->meta($page),
        ]);
    }

    /** GET /api/admin/business-email/operations/{id} — the unified timeline. */
    public function operation(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $operation = InfraOperation::withoutWorkspaceScope()
            ->where('capability', BusinessEmailEngine::CAPABILITY)->find($id);

        if ($operation === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $events = InfraEvent::withoutWorkspaceScope()
            ->where('operation_id', $id)->orderBy('id')->get()
            ->map(fn (InfraEvent $e) => [
                'event'      => $e->event,
                'severity'   => $e->severity,
                'summary'    => $e->summary,
                'actor'      => $e->actor_user_id,
                'source'     => $e->source,
                'at'         => $e->created_at?->toIso8601String(),
                // Operator surface: the structured context including the
                // failure code. Never shown to a customer.
                'context'    => $e->context_json,
            ])->all();

        return response()->json([
            'operation' => $this->operationRow($operation),
            'timeline'  => $events,
            'resolution' => $this->resolutionOptions($operation),
        ]);
    }

    // ── reconciliation ───────────────────────────────────────────────────────

    /** GET /api/admin/business-email/reconciliation?email_domain_id= */
    public function reconciliation(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $domainId = (int) $request->query('email_domain_id', 0);
        $domain = $this->unscoped(EmailDomain::class)->find($domainId);

        if ($domain === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // Dry run: a read endpoint must not write observation facts as a side
        // effect of someone opening a screen.
        $report = app(EmailReconciliationService::class)->reconcile($domain, record: false);

        return response()->json([
            'report' => $report->toAdminArray(),
            // The customer-safe summary is returned alongside so an operator
            // can see exactly what the customer would be told — and confirm it
            // leaks nothing. It is NOT a customer route.
            'customer_projection_preview' => $report->toCustomerArray(),
        ]);
    }

    /** GET /api/admin/business-email/observations?subject= */
    public function observations(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $query = DB::table('infra_observation_facts')->where('dimension', 'email_provider');

        if (($subject = trim((string) $request->query('subject', ''))) !== '') {
            $query->where('subject', $subject);
        }

        $rows = $query->orderByDesc('id')->limit($this->pageSize($request))->get();

        return response()->json(['observations' => $rows]);
    }

    // ── providers ────────────────────────────────────────────────────────────

    /**
     * GET /api/admin/business-email/providers
     *
     * The generic provider registry view. It reports credential STATE — present,
     * fingerprint, when it was last verified — and never credential VALUE.
     * There is no code path in this controller that reads a secret column.
     */
    public function providers(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $connections = DB::table('infra_provider_connections')
            ->where('capability', BusinessEmailEngine::CAPABILITY)
            ->orderBy('id')->get()
            ->map(fn ($c) => [
                'id'            => (int) $c->id,
                'workspace_id'  => $c->workspace_id,
                'provider'      => $c->provider,
                'label'         => $c->label,
                'state'         => $c->state,
                // The NAME of a config key, never a value. The column cannot
                // hold a secret by design; this reports only whether one is
                // referenced at all.
                'credential_reference_present' => $c->credential_ref !== null && $c->credential_ref !== '',
                'last_health_check_at' => $c->last_health_check_at,
                'last_health_state'    => $c->last_health_state,
                'last_error_code'      => $c->last_error_code,
                'resource_count' => DB::table('infra_provider_resources')
                    ->where('provider_connection_id', $c->id)->count(),
            ])->all();

        return response()->json([
            'connections'  => $connections,
            'summary'      => $this->providerSummary(),
            'capabilities' => $this->capabilitySupport(),
            'credential_policy' => [
                'secret_returned_after_creation' => false,
                'stored_encrypted'               => true,
                'displayed'                      => 'masked_only',
                'rotation'                       => 'explicit_workflow',
                'audited'                        => true,
            ],
        ]);
    }

    /** GET /api/admin/business-email/health */
    public function health(Request $request): JsonResponse
    {
        if ($deny = $this->deny($request, Access::READ)) {
            return $deny;
        }

        $connector = $this->engine->connector();

        return response()->json([
            'provider_configured' => $connector !== null,
            'provider'            => $connector?->provider(),
            'capabilities'        => $this->capabilitySupport(),
            'domains_by_health'   => $this->unscoped(EmailDomain::class)
                ->selectRaw('health_state, count(*) as c')
                ->groupBy('health_state')->pluck('c', 'health_state')->all(),
            'stale_usage_domains' => $this->unscoped(EmailDomain::class)
                ->whereNull('last_observed_at')->count(),
            'observed_at'         => now()->toIso8601String(),
        ]);
    }

    // ── shared projections ───────────────────────────────────────────────────

    private function providerSummary(): array
    {
        $connector = $this->engine->connector();

        return [
            'configured' => $connector !== null,
            // Operator surface, so the provider key IS shown. It is the single
            // most useful fact when diagnosing, and E3's only possible value is
            // a test double whose key is prefixed so it cannot be mistaken for
            // a real vendor.
            'provider'   => $connector?->provider(),
            'viable'     => $connector?->capabilitySet()->isViable(),
        ];
    }

    /** @return array<string,bool>|array{available:false,reason:string} */
    private function capabilitySupport(): array
    {
        $connector = $this->engine->connector();

        if ($connector === null) {
            return ['available' => false, 'reason' => 'No email provider is configured on this installation.'];
        }

        $set = $connector->capabilitySet();
        $out = [];

        foreach (EmailProviderCapability::all() as $capability) {
            $out[$capability] = $set->supports($capability);
        }

        return $out;
    }

    private function bindingSummary(string $ownerType, int $ownerId): array
    {
        $row = InfraProviderResource::withoutWorkspaceScope()
            ->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();

        if ($row === null) {
            return ['bound' => false];
        }

        return [
            'bound'            => true,
            'provider'         => $row->provider,
            // Opaque, and an operator concern only. Never in a customer payload.
            'provider_ref'     => $row->provider_resource_id,
            'normalized_state' => $row->normalized_state,
            'last_synced_at'   => $row->last_synced_at?->toIso8601String(),
            'stale'            => $row->isStale(),
        ];
    }

    private function operationRows(int $workspaceId, string $ownerType, int $ownerId): array
    {
        return InfraOperation::withoutWorkspaceScope()
            ->where('capability', BusinessEmailEngine::CAPABILITY)
            ->where('workspace_id', $workspaceId)
            ->where(function ($q) use ($ownerType, $ownerId) {
                $q->where(function ($inner) use ($ownerType, $ownerId) {
                    $inner->where('owner_type', $ownerType)->where('owner_id', $ownerId);
                });
            })
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (InfraOperation $o) => $this->operationRow($o))->all();
    }

    private function operationRow(InfraOperation $operation): array
    {
        return [
            'id'                   => (int) $operation->id,
            'workspace_id'         => (int) $operation->workspace_id,
            'capability'           => $operation->operation,
            'owner_type'           => $operation->owner_type,
            'owner_id'             => $operation->owner_id,
            'state'                => $operation->state,
            'terminal'             => OperationState::isTerminal((string) $operation->state),
            'needs_manual_review'  => $this->needsManualReview($operation),
            'idempotency_key'      => $operation->idempotency_key,
            'attempt_count'        => (int) $operation->attempt_count,
            'max_attempts'         => (int) $operation->max_attempts,
            'retry_classification' => $operation->retry_classification,
            'auto_retry_eligible'  => $operation->retry_classification === 'retryable'
                && $operation->state === OperationState::FAILED_RETRYABLE,
            'provider'             => $operation->provider,
            'provider_correlation_id' => $operation->provider_correlation_id,
            'failure_code'         => $operation->failure_code,
            'failure_summary'      => $operation->failure_summary,
            'actor_user_id'        => $operation->actor_user_id,
            'source'               => $operation->source,
            'started_at'           => $operation->started_at?->toIso8601String(),
            'finished_at'          => $operation->finished_at?->toIso8601String(),
            'created_at'           => $operation->created_at?->toIso8601String(),
        ];
    }

    private function auditRows(int $workspaceId, int $ownerId): array
    {
        return InfraEvent::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('event', 'like', 'email.%')
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (InfraEvent $e) => [
                'event'    => $e->event,
                'severity' => $e->severity,
                'summary'  => $e->summary,
                'actor'    => $e->actor_user_id,
                'at'       => $e->created_at?->toIso8601String(),
            ])->all();
    }

    /** @return array<int,string> */
    public function unresolvedStates(): array
    {
        return [
            OperationState::COMPENSATION_PENDING,
            OperationState::TIMED_OUT,
            OperationState::FAILED_RETRYABLE,
            OperationState::FAILED_TERMINAL,
        ];
    }

    private function needsManualReview(InfraOperation $operation): bool
    {
        return in_array($operation->state, [
            OperationState::COMPENSATION_PENDING,
            OperationState::TIMED_OUT,
            OperationState::FAILED_TERMINAL,
        ], true);
    }

    /**
     * What an operator is allowed to do with this operation, and what they are
     * explicitly not.
     *
     * Stating the forbidden list is as important as the allowed one. The single
     * most damaging thing an operator can do to an ambiguous quota-consuming
     * mutation is retry it, and a console that simply omits the button leaves
     * them wondering whether it is missing or broken.
     */
    private function resolutionOptions(InfraOperation $operation): array
    {
        $ambiguous = $operation->state === OperationState::COMPENSATION_PENDING
            || $operation->state === OperationState::TIMED_OUT;

        $allowed = ['acknowledge'];
        $forbidden = [];

        if (! OperationState::isTerminal((string) $operation->state)) {
            $allowed[] = 'confirm_by_read_back';
        }

        if ($operation->retry_classification === 'retryable'
            && $operation->state === OperationState::FAILED_RETRYABLE) {
            $allowed[] = 'retry';
        } else {
            $forbidden[] = [
                'action' => 'retry',
                'reason' => $ambiguous
                    ? 'The outcome of this request is unknown. Re-issuing it could duplicate a billable mailbox. Resolve it by reading provider truth back instead.'
                    : 'This operation is not classified as retryable.',
            ];
        }

        $forbidden[] = ['action' => 'force_success', 'reason' => 'An operation may only be completed by evidence.'];
        $forbidden[] = ['action' => 'edit_provider_reference', 'reason' => 'Provider references are opaque and are never edited by hand.'];
        $forbidden[] = ['action' => 'delete_audit', 'reason' => 'The infrastructure event log is append-only.'];

        return ['allowed' => $allowed, 'forbidden' => $forbidden];
    }

    private function meta($page): array
    {
        return [
            'total'        => $page->total(),
            'per_page'     => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
        ];
    }
}
