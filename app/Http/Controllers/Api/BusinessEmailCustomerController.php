<?php

namespace App\Http\Controllers\Api;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Customer\BusinessEmailCustomerGate as Gate;
use App\Engines\Infrastructure\Email\Customer\CustomerEntitlements;
use App\Engines\Infrastructure\Email\Customer\CustomerHealth;
use App\Engines\Infrastructure\Email\Customer\CustomerStatus;
use App\Engines\Infrastructure\Email\Customer\CustomerTimeline;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Models\EmailUsage;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailRoutingRuleState;
use App\Engines\Infrastructure\Models\InfraEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * INFRA888 · E4 — the customer Business Email portal. READ SIDE.
 *
 * ─── THE RULE THIS WHOLE CLASS IS BUILT AROUND ──────────────────────────────
 *
 * A customer never learns that a provider exists. Not its name, not its
 * identifiers, not its error codes, not its capabilities, not its plan, not its
 * availability except as "the Business Email service". Every value returned
 * here passes through a customer projection — `toCustomerArray()`,
 * `CustomerStatus`, `CustomerHealth`, `CustomerTimeline` — and no model is
 * serialised directly.
 *
 * ─── AND THE ONE THAT MAKES IT ENFORCEABLE ──────────────────────────────────
 *
 * The workspace is resolved from the AUTHENTICATED TOKEN, never from the
 * request. There is no `workspace_id` parameter on any endpoint in this file.
 * A customer cannot name a workspace, so they cannot name someone else's.
 *
 * ─── WHAT IS DELIBERATELY ABSENT ────────────────────────────────────────────
 *
 * No provider registry, no credentials, no operations list, no reconciliation
 * findings, no observation rows, no eligibility, no idempotency keys, no retry
 * classifications, no provider bindings. Those are E3's operator plane and stay
 * there. A guard test asserts none of their field names can appear here.
 */
class BusinessEmailCustomerController
{
    public function __construct(
        private readonly BusinessEmailEngine $engine,
    ) {
    }

    // ── guard ────────────────────────────────────────────────────────────────

    /**
     * The gate, then entitlement. Returns a response to send, or null.
     *
     * A closed gate answers 404 with a customer-safe reason, matching how every
     * other unavailable module behaves — and deliberately NOT 403, which would
     * imply the feature exists and they are excluded from it.
     */
    private function unavailable(Request $request): ?JsonResponse
    {
        if (! Gate::isEnabled()) {
            return response()->json([
                'success'   => false,
                'available' => false,
                'reason'    => Gate::unavailableReason(),
            ], 404);
        }

        $entitlements = CustomerEntitlements::forWorkspace($this->workspaceId($request));

        if (($entitlements[CustomerEntitlements::ACCESS] ?? false) !== true) {
            return response()->json([
                'success'   => false,
                'available' => false,
                'reason'    => 'Business Email is not included in your plan.',
            ], 403);
        }

        return null;
    }

    /**
     * The workspace, from the token.
     *
     * JwtAuthMiddleware sets this from the verified `ws` claim after confirming
     * membership. Reading it from the request body instead would let a customer
     * name any workspace, which is the single most damaging thing a
     * multi-tenant read endpoint can allow.
     */
    private function workspaceId(Request $request): int
    {
        return (int) $request->attributes->get('workspace_id', 0);
    }

    // ── overview ─────────────────────────────────────────────────────────────

    /** GET /api/infrastructure/business-email/overview */
    public function overview(Request $request): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);
        $entitlements = CustomerEntitlements::forWorkspace($workspaceId);

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($entitlements) {
            $domains = EmailDomain::query()->get();
            $mailboxes = EmailMailbox::query()->get();

            $actionRequired = 0;
            $rows = [];

            foreach ($domains as $domain) {
                $state = CustomerStatus::forDomain((string) $domain->lifecycle_state);

                if (in_array($state, CustomerStatus::needsCustomerAction(), true)) {
                    $actionRequired++;
                }

                $rows[] = $this->domainSummary($domain, $mailboxes);
            }

            $storageUsed = (int) $mailboxes->sum('storage_used_mb');

            return [
                'success'   => true,
                'available' => true,
                'domains'   => $rows,
                'summary'   => [
                    'domain_count'    => $domains->count(),
                    'mailbox_count'   => $mailboxes->whereIn('lifecycle_state', EmailMailboxState::billable())->count(),
                    'storage_used_mb' => $storageUsed,
                    'action_required' => $actionRequired,
                    'last_checked'    => optional($domains->max('last_observed_at'))?->toIso8601String(),
                ],
                'allowances' => [
                    'domains'   => CustomerEntitlements::allowance($domains->count(), $entitlements[CustomerEntitlements::DOMAIN_LIMIT]),
                    'mailboxes' => CustomerEntitlements::allowance(
                        $mailboxes->whereIn('lifecycle_state', EmailMailboxState::billable())->count(),
                        $entitlements[CustomerEntitlements::MAILBOX_LIMIT]
                    ),
                    'storage'   => CustomerEntitlements::allowance($storageUsed, $entitlements[CustomerEntitlements::STORAGE_MB]),
                ],
                'actions'   => $this->availableActions(),
            ];
        }));
    }

    /** GET /api/infrastructure/business-email/domains/{id} */
    public function domain(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($id, $workspaceId) {
            // Scoped by the global workspace scope, so a domain belonging to
            // another workspace is simply not found. The customer cannot tell
            // "not yours" from "does not exist", which is correct.
            $domain = EmailDomain::query()->find($id);

            if ($domain === null) {
                return ['success' => false, 'reason' => 'Not found.'];
            }

            $mailboxes = EmailMailbox::query()->where('email_domain_id', $id)->get();

            return [
                'success'  => true,
                'domain'   => $this->domainSummary($domain, $mailboxes),
                'setup'    => $this->setupPayload($domain),
                'health'   => CustomerHealth::forDomain($domain, $this->observedChecks($domain)),
                'timeline' => CustomerTimeline::project(
                    InfraEvent::query()
                        ->where('workspace_id', $workspaceId)
                        ->where('event', 'like', 'email.%')
                        ->orderByDesc('id')->limit(50)->get()
                ),
            ];
        }));
    }

    /** GET /api/infrastructure/business-email/domains/{id}/setup */
    public function setup(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        return response()->json(WorkspaceContext::run($this->workspaceId($request), function () use ($id) {
            $domain = EmailDomain::query()->find($id);

            return $domain === null
                ? ['success' => false, 'reason' => 'Not found.']
                : ['success' => true, 'setup' => $this->setupPayload($domain)];
        }));
    }

    // ── mailboxes ────────────────────────────────────────────────────────────

    /** GET /api/infrastructure/business-email/mailboxes */
    public function mailboxes(Request $request): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);
        $entitlements = CustomerEntitlements::forWorkspace($workspaceId);

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($request, $entitlements) {
            $query = EmailMailbox::query()->with('domain');

            if (($domainId = (int) $request->query('domain_id', 0)) > 0) {
                $query->where('email_domain_id', $domainId);
            }

            $page = $query->orderBy('local_part')->paginate($this->perPage($request));

            $rows = array_map(fn (EmailMailbox $m) => $this->mailboxSummary($m), $page->items());

            $billable = EmailMailbox::query()
                ->whereIn('lifecycle_state', EmailMailboxState::billable())->count();

            return [
                'success'   => true,
                'mailboxes' => $rows,
                'allowance' => CustomerEntitlements::allowance($billable, $entitlements[CustomerEntitlements::MAILBOX_LIMIT]),
                'meta'      => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
                'actions'   => $this->availableActions(),
            ];
        }));
    }

    /** GET /api/infrastructure/business-email/mailboxes/{id} */
    public function mailbox(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($id, $workspaceId) {
            $mailbox = EmailMailbox::query()->with('domain')->find($id);

            if ($mailbox === null) {
                return ['success' => false, 'reason' => 'Not found.'];
            }

            return [
                'success' => true,
                'mailbox' => $this->mailboxSummary($mailbox),
                'usage'   => EmailUsage::query()
                    ->where('email_mailbox_id', $id)
                    ->orderByDesc('observed_at')->limit(12)->get()
                    ->map(fn (EmailUsage $u) => $this->usageSummary($u))->all(),
                'timeline' => CustomerTimeline::project(
                    InfraEvent::query()
                        ->where('workspace_id', $workspaceId)
                        ->where('owner_type', EmailMailbox::OWNER_TYPE)
                        ->where('owner_id', $id)
                        ->orderByDesc('id')->limit(25)->get()
                ),
                // Stated plainly so the UI never has to imply otherwise.
                'password' => [
                    'retrievable' => false,
                    'note' => 'We never store or display mailbox passwords. A reset sends recovery '
                        . 'instructions through the mailbox recovery process.',
                ],
                'actions' => $this->availableActions(),
            ];
        }));
    }

    // ── routing ──────────────────────────────────────────────────────────────

    /** GET /api/infrastructure/business-email/aliases */
    public function aliases(Request $request): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);
        $entitlements = CustomerEntitlements::forWorkspace($workspaceId);

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($request, $entitlements) {
            $query = EmailAlias::query();

            if (($domainId = (int) $request->query('domain_id', 0)) > 0) {
                $query->where('email_domain_id', $domainId);
            }

            $rows = $query->orderBy('source_local_part')->limit(200)->get()
                ->map(fn (EmailAlias $a) => [
                    'id'         => (int) $a->id,
                    'address'    => $a->sourceAddress(),
                    'local_part' => (string) $a->source_local_part,
                    'delivers_to' => $this->aliasTarget($a),
                    'status'     => CustomerStatus::describe(CustomerStatus::forRoutingRule((string) $a->lifecycle_state)),
                ])->all();

            $active = EmailAlias::query()->where('lifecycle_state', EmailRoutingRuleState::ACTIVE)->count();

            return [
                'success'   => true,
                'aliases'   => $rows,
                'allowance' => CustomerEntitlements::allowance($active, $entitlements[CustomerEntitlements::ALIAS_LIMIT]),
                'actions'   => $this->availableActions(),
            ];
        }));
    }

    /** GET /api/infrastructure/business-email/forwarders */
    public function forwarders(Request $request): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);
        $entitlements = CustomerEntitlements::forWorkspace($workspaceId);

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($request, $entitlements) {
            $query = EmailForwarder::query();

            if (($domainId = (int) $request->query('domain_id', 0)) > 0) {
                $query->where('email_domain_id', $domainId);
            }

            $rows = $query->orderBy('source_local_part')->limit(200)->get()
                ->map(fn (EmailForwarder $f) => [
                    'id'          => (int) $f->id,
                    'address'     => $f->sourceAddress(),
                    'local_part'  => (string) $f->source_local_part,
                    'forwards_to' => (string) $f->destination_address,
                    'external'    => true,
                    'status'      => CustomerStatus::describe(CustomerStatus::forRoutingRule((string) $f->lifecycle_state)),
                    // The verdict is translated. `unchecked`, `chain_detected`
                    // and the hop count are our vocabulary, not theirs.
                    'delivery_warning' => $this->loopWarning($f),
                ])->all();

            $active = EmailForwarder::query()->where('lifecycle_state', EmailRoutingRuleState::ACTIVE)->count();

            return [
                'success'    => true,
                'forwarders' => $rows,
                'allowance'  => CustomerEntitlements::allowance($active, $entitlements[CustomerEntitlements::FORWARDER_LIMIT]),
                'notice'     => 'Forwarding sends a copy of your mail outside LevelUp Growth. '
                    . 'The receiving service decides what happens to it after that.',
                'actions'    => $this->availableActions(),
            ];
        }));
    }

    /** GET /api/infrastructure/business-email/catch-all */
    public function catchAll(Request $request): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);
        $entitlements = CustomerEntitlements::forWorkspace($workspaceId);

        // Two independent reasons it may be unavailable, and the customer is
        // told the same thing either way: the product does not offer it here.
        // Naming provider capability would leak the provider's shape.
        $supported = $this->supports(EmailProviderCapability::CATCHALL_CONFIGURE)
            && ($entitlements[CustomerEntitlements::CATCHALL_ALLOWED] ?? false) === true;

        if (! $supported) {
            return response()->json([
                'success'   => true,
                'available' => false,
                'reason'    => 'Catch-all is not available on this account.',
            ]);
        }

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($request) {
            $query = EmailCatchAll::query();

            if (($domainId = (int) $request->query('domain_id', 0)) > 0) {
                $query->where('email_domain_id', $domainId);
            }

            return [
                'success'   => true,
                'available' => true,
                'catch_all' => $query->get()->map(fn (EmailCatchAll $c) => [
                    'id'             => (int) $c->id,
                    'domain_id'      => (int) $c->email_domain_id,
                    'enabled'        => $c->isEnabled(),
                    'delivers_to'    => $c->target_address ?: ($c->targetMailbox?->address()),
                    'status'         => CustomerStatus::describe(CustomerStatus::forCatchAll((string) $c->state)),
                ])->all(),
                'warning' => 'A catch-all accepts mail sent to any address on your domain, including '
                    . 'addresses that do not exist. It usually increases unwanted mail.',
                'actions' => $this->availableActions(),
            ];
        }));
    }

    // ── usage & health ───────────────────────────────────────────────────────

    /** GET /api/infrastructure/business-email/usage */
    public function usage(Request $request): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        $workspaceId = $this->workspaceId($request);
        $entitlements = CustomerEntitlements::forWorkspace($workspaceId);

        return response()->json(WorkspaceContext::run($workspaceId, function () use ($entitlements) {
            $mailboxes = EmailMailbox::query()->with('domain')->get();
            $used = (int) $mailboxes->sum('storage_used_mb');
            $latest = EmailUsage::query()->orderByDesc('observed_at')->first();

            $allowance = CustomerEntitlements::allowance($used, $entitlements[CustomerEntitlements::STORAGE_MB]);

            // Stale is a first-class answer. A figure we have not refreshed is
            // shown WITH its age rather than presented as current truth.
            $observedAt = $latest?->observed_at;
            $stale = $observedAt === null || $observedAt->lt(now()->subHours(48));

            return [
                'success'   => true,
                'storage'   => $allowance + ['unit' => 'MB'],
                'approaching_limit' => CustomerEntitlements::isApproaching($allowance),
                'per_mailbox' => $mailboxes->map(fn (EmailMailbox $m) => [
                    'address'            => $m->address(),
                    'storage_used_mb'    => $m->storage_used_mb,
                    'quota_mb'           => (int) $m->quota_mb,
                    'quota_used_percent' => $m->quotaUsedPercent(),
                    'observed_at'        => optional($m->usage_observed_at)->toIso8601String(),
                ])->all(),
                'last_synchronized' => optional($observedAt)->toIso8601String(),
                'stale'             => $stale,
                'stale_note'        => $stale
                    ? 'These figures may be out of date. We refresh them regularly.'
                    : null,
            ];
        }));
    }

    /** GET /api/infrastructure/business-email/health */
    public function health(Request $request): JsonResponse
    {
        if ($deny = $this->unavailable($request)) {
            return $deny;
        }

        return response()->json(WorkspaceContext::run($this->workspaceId($request), function () {
            $domains = EmailDomain::query()->get();

            return [
                'success' => true,
                'domains' => $domains->map(fn (EmailDomain $d) => [
                    'id'     => (int) $d->id,
                    'domain' => (string) $d->domain,
                    'health' => CustomerHealth::forDomain($d, $this->observedChecks($d)),
                ])->all(),
            ];
        }));
    }

    // ── projections ──────────────────────────────────────────────────────────

    private function domainSummary(EmailDomain $domain, $mailboxes): array
    {
        $count = $mailboxes->where('email_domain_id', $domain->id)
            ->whereIn('lifecycle_state', EmailMailboxState::billable())->count();

        return [
            'id'            => (int) $domain->id,
            'domain'        => (string) $domain->domain,
            'status'        => CustomerStatus::describe(CustomerStatus::forDomain((string) $domain->lifecycle_state)),
            'verification'  => CustomerStatus::forVerification((string) $domain->verification_state),
            'mailbox_count' => $count,
            'last_checked'  => optional($domain->last_observed_at)->toIso8601String(),
            'created_at'    => optional($domain->created_at)->toIso8601String(),
        ];
    }

    private function mailboxSummary(EmailMailbox $mailbox): array
    {
        return [
            'id'                 => (int) $mailbox->id,
            'address'            => $mailbox->address(),
            'local_part'         => (string) $mailbox->local_part,
            'display_name'       => $mailbox->display_name,
            'status'             => CustomerStatus::describe(CustomerStatus::forMailbox((string) $mailbox->lifecycle_state)),
            'quota_mb'           => (int) $mailbox->quota_mb,
            'storage_used_mb'    => $mailbox->storage_used_mb,
            'quota_used_percent' => $mailbox->quotaUsedPercent(),
            'last_activity'      => optional($mailbox->last_activity_at)->toIso8601String(),
            'usage_observed_at'  => optional($mailbox->usage_observed_at)->toIso8601String(),
            // Whether THIS customer may lift the suspension themselves.
            'can_restore'        => $mailbox->isCustomerRestorable(),
        ];
    }

    private function usageSummary(EmailUsage $usage): array
    {
        return [
            'observed_at'        => optional($usage->observed_at)->toIso8601String(),
            'storage_used_mb'    => $usage->storage_used_mb,
            'quota_used_percent' => $usage->quotaUsedPercent(),
        ];
    }

    private function setupPayload(EmailDomain $domain): array
    {
        $records = $domain->settings_json['dns_requirements'] ?? [];
        $labels = CustomerHealth::checks();

        return [
            'status'       => CustomerStatus::describe(CustomerStatus::forDomain((string) $domain->lifecycle_state)),
            'verification' => CustomerStatus::forVerification((string) $domain->verification_state),
            'verified_at'  => optional($domain->dns_verified_at)->toIso8601String(),
            'records'      => array_map(function (array $r) use ($labels) {
                $purpose = (string) ($r['purpose'] ?? '');

                return [
                    'purpose'   => $purpose,
                    // Our words plus the standard label. Both are permitted and
                    // both are useful when pasting into a DNS panel.
                    'label'     => $labels[$purpose]['label'] ?? 'Record',
                    'technical' => $r['type'] ?? '',
                    'type'      => $r['type'] ?? '',
                    'name'      => $r['name'] ?? '',
                    // Published verbatim — the one thing that has to travel
                    // unchanged, and the accepted limit of the white label.
                    'value'     => $r['value'] ?? '',
                    'priority'  => $r['priority'] ?? null,
                    'ttl'       => $r['ttl'] ?? null,
                    'required'  => (bool) ($r['required'] ?? true),
                ];
            }, is_array($records) ? $records : []),
            'instructions' => 'Add these records with your DNS provider, then run a DNS check. '
                . 'Changes can take a little while to appear.',
        ];
    }

    /** @return array<string,bool|null> */
    private function observedChecks(EmailDomain $domain): array
    {
        // E4 reads only what the engine has already recorded. It triggers no
        // provider call from a customer request — a read endpoint that reaches
        // a provider lets a customer drive our rate limit.
        $settings = $domain->settings_json ?? [];

        return is_array($settings['dns_observed'] ?? null) ? $settings['dns_observed'] : [];
    }

    private function aliasTarget(EmailAlias $alias): ?string
    {
        return $alias->target_address ?: $alias->targetMailbox?->address();
    }

    private function loopWarning(EmailForwarder $forwarder): ?string
    {
        if (! $forwarder->isLoopBlocked()) {
            return null;
        }

        // Deliberately one message for every blocking verdict. `unchecked`,
        // `self_reference`, `chain_detected` and `undetermined` are our
        // vocabulary; what the customer needs is that it is not delivering and
        // why in plain terms.
        return 'This rule is not delivering. Forwarding back to an address on the same domain, '
            . 'or in a loop, would send mail round in circles.';
    }

    /**
     * Which actions this customer may take, given the registry's own
     * `customer_available` fact AND the provider's capability support.
     *
     * An action the provider cannot perform is reported unavailable so the UI
     * hides it rather than offering something that will predictably fail.
     *
     * @return array<string,bool>
     */
    private function availableActions(): array
    {
        $out = [];

        foreach (Registry::capabilities() as $slug => $meta) {
            if ($meta['customer_available'] !== true) {
                continue;
            }

            $flag = $meta['provider_capability'] ?? null;

            $out[$this->actionName($slug)] = $flag === null || $this->supports($flag);
        }

        return $out;
    }

    private function supports(string $capability): bool
    {
        $connector = $this->engine->connector();

        return $connector !== null && $connector->supports($capability);
    }

    /** Registry slug -> the customer-facing action name used by the portal. */
    private function actionName(string $slug): string
    {
        return str_replace(['email.', '.'], ['', '_'], $slug);
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->query('per_page', 25), 100));
    }
}
