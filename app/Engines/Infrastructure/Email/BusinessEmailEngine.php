<?php

namespace App\Engines\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\Values\AliasSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\CatchAllSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ForwarderSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxUsageSample;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Connectors\Infrastructure\ProviderResult;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Models\EmailUsage;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\BusinessEmailFailure as Failure;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Email\Support\EmailFlightPlan;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Engines\Infrastructure\Email\Support\ProviderOutcome;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Models\InfraProviderResource;
use App\Engines\Infrastructure\Observation\Custody;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * INFRA888 · E1/E2 — BUSINESS EMAIL ENGINE.
 *
 * The provider-agnostic authority for Business Email. Everything a customer or
 * an operator asks for passes through here and is either refused with a typed
 * reason or admitted as an authorised, recorded, idempotent operation.
 *
 * ─── THE THREE ENTRY POINTS ─────────────────────────────────────────────────
 *
 *   authorize()  validate, check entitlement, custody, lifecycle, provider
 *                capability; open a governed operation. Calls NO provider.
 *   execute()    authorize, then perform the mutation and interpret the answer.
 *   confirm()    read provider truth back and settle an operation that
 *                authorize/execute left unresolved.
 *
 * They are separate because the middle one is the only one that can go wrong in
 * an interesting way. A caller may authorize without executing (to check
 * whether an action is available), and MUST be able to confirm without
 * re-executing — re-issuing a mutation whose outcome is unknown is precisely
 * the duplicate-provisioning bug this design exists to prevent.
 *
 * ─── ACCEPTED IS NOT VERIFIED ───────────────────────────────────────────────
 *
 * A provider saying "yes" is not evidence that anything happened. Only a
 * read-back is. So `accepted` leaves the operation open and the subject in its
 * in-flight state; nothing customer-visible says "working" until confirm()
 * reads it back. Every success transition in every state machine is declared
 * evidence-required, and this class is where that declaration is honoured.
 *
 * ─── AMBIGUITY IS NEITHER SUCCESS NOR FAILURE ───────────────────────────────
 *
 * A timeout on a mutating call means the mutation may have happened. It is
 * never retried automatically. The operation goes to compensation_pending and
 * the subject — where its very existence is in doubt — goes to reconciling.
 * See EmailFlightPlan for why suspend and restore deliberately do NOT move the
 * subject on ambiguity while create and delete do.
 *
 * ─── WHAT STILL DOES NOT EXIST ──────────────────────────────────────────────
 *
 * No vendor adapter, no route, no job, no scheduler. With nothing bound to the
 * `email` capability this engine still answers PROVIDER_NOT_CONFIGURED to every
 * mutating request, exactly as it did in E1. E2 proved it against a fake; E5
 * gives it a provider.
 */
class BusinessEmailEngine
{
    /** The connector capability key this engine resolves. */
    public const CAPABILITY = InfraProviderConnection::CAPABILITY_EMAIL;

    /** provider_resource_type values written to infra_provider_resources. */
    public const RESOURCE_DOMAIN = 'email_domain';
    public const RESOURCE_MAILBOX = 'email_mailbox';
    public const RESOURCE_ALIAS = 'email_alias';
    public const RESOURCE_FORWARDER = 'email_forwarder';
    public const RESOURCE_CATCHALL = 'email_catchall';

    public function __construct(
        private readonly InfrastructureConnectorResolver $resolver,
    ) {
    }

    // ── capability surface ───────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    public function capabilities(): array
    {
        return Registry::capabilities();
    }

    public function describe(string $capability): ?array
    {
        return Registry::get($capability);
    }

    // ── provider availability ────────────────────────────────────────────────

    /**
     * The configured email connector, or null when none is configured.
     *
     * Returns null rather than throwing so callers handle absence as a state.
     * With no adapter and nothing injected, this always returns null:
     * config/infrastructure.php deliberately registers no `email` connector.
     */
    public function connector(): ?EmailProviderConnector
    {
        try {
            $connector = $this->resolver->resolve(self::CAPABILITY);
        } catch (RuntimeException) {
            return null;
        }

        // A connector registered under `email` that does not implement the
        // email contract is a misconfiguration, not a usable provider. Treating
        // it as absent is the fail-closed reading.
        return $connector instanceof EmailProviderConnector ? $connector : null;
    }

    /**
     * Whether Business Email can act at all. Configuration only — no network,
     * no provider round-trip, no health probe.
     */
    public function providerAvailability(): ProviderResult
    {
        return $this->connector() === null
            ? $this->refuse(Failure::PROVIDER_NOT_CONFIGURED)
            : ProviderResult::accepted('available');
    }

    public function isAvailable(): bool
    {
        return $this->connector() !== null;
    }

    // ── 1. authorize ─────────────────────────────────────────────────────────

    /**
     * Validate, authorise and record one Business Email request.
     *
     * @return ProviderResult failed() for every refusal; accepted() when the
     *                        request is authorised and an operation is open.
     *                        NEVER verified() — authorisation confirms nothing.
     */
    public function authorize(EmailOperationContext $context): ProviderResult
    {
        $meta = Registry::get($context->capability);

        if ($meta === null) {
            return $this->refuse(Failure::UNKNOWN_CAPABILITY);
        }

        if ($context->workspaceId <= 0) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        // Tenancy is established for the WHOLE request, not per query.
        //
        // The alternative — wrapping each individual database call — looks
        // equivalent and is not: a lazily-loaded relationship (an alias reading
        // its target mailbox, a catch-all reading its target) fires wherever it
        // is first touched, which may be outside any wrapped block. That threw
        // "WorkspaceContext is not set" on exactly two capabilities and would
        // have thrown on more as relationships were added. Establishing context
        // once, here, makes the whole operation tenanted by construction.
        return WorkspaceContext::run($context->workspaceId, fn () => $this->authorizeWithin($context, $meta));
    }

    /** @param array<string,mixed> $meta */
    private function authorizeWithin(EmailOperationContext $context, array $meta): ProviderResult
    {
        $denial = $this->validate($context, $meta);

        if ($denial !== null) {
            $this->recordDenial($context, $denial);

            return $denial;
        }

        // Read-only capabilities change nothing anywhere and therefore open no
        // operation. The test is the idempotency strategy, NOT provider_mutation:
        // usage sync mutates no provider but WRITES email_usage rows, and
        // onboarding mutates no local record but must be governed. Only genuine
        // observation is exempt.
        if ($meta['idempotency'] === Registry::IDEMPOTENCY_READ_ONLY) {
            $availability = $this->providerAvailability();

            if (! $availability->success) {
                $this->recordDenial($context, $availability);
            }

            return $availability;
        }

        $operation = $this->openOperation($context, $meta);

        // A completed operation under this key is the answer. This is what the
        // idempotency key is FOR: a retried job must not act twice.
        if (OperationState::isTerminal((string) $operation->state)) {
            return $this->replay($operation);
        }

        $availability = $this->providerAvailability();

        if (! $availability->success) {
            $this->parkOperation($operation, $availability, $context);

            return $availability;
        }

        // Authorised, recorded and idempotent. `accepted` and not `verified`:
        // nothing has happened at a provider yet.
        return ProviderResult::accepted(
            normalizedState: 'authorized',
            data: [
                'operation_id'    => (int) $operation->id,
                'capability'      => $context->capability,
                'idempotency_key' => (string) $operation->idempotency_key,
            ],
        );
    }

    // ── 2. execute ───────────────────────────────────────────────────────────

    /**
     * Authorise, then actually perform the request against the provider.
     *
     * @return ProviderResult verified() only when the provider confirmed the
     *                        effect; accepted() when acknowledged but unproven;
     *                        failed() for every refusal, failure and ambiguity.
     */
    public function execute(EmailOperationContext $context): ProviderResult
    {
        $meta = Registry::get($context->capability);

        if ($meta === null) {
            return $this->refuse(Failure::UNKNOWN_CAPABILITY);
        }

        if ($context->workspaceId <= 0) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        return WorkspaceContext::run($context->workspaceId, fn () => $this->executeWithin($context, $meta));
    }

    /** @param array<string,mixed> $meta */
    private function executeWithin(EmailOperationContext $context, array $meta): ProviderResult
    {
        $authorization = $this->authorize($context);

        if (! $authorization->success) {
            return $authorization;
        }

        $connector = $this->connector();

        if ($connector === null) {
            // authorize() already proved availability; reaching here would mean
            // the configuration changed underneath us mid-request.
            return $this->refuse(Failure::PROVIDER_NOT_CONFIGURED);
        }

        if ($meta['idempotency'] === Registry::IDEMPOTENCY_READ_ONLY) {
            return $this->observe($context, $meta, $connector);
        }

        $operationId = (int) ($authorization->data['operation_id'] ?? 0);
        $operation = $this->loadOperation($context->workspaceId, $operationId);

        if ($operation === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        if (OperationState::isTerminal((string) $operation->state)) {
            return $this->replay($operation);
        }

        // Already dispatched and awaiting a read-back. Calling the provider
        // again here is the exact double-mutation this guard exists to stop.
        if (in_array($operation->state, [OperationState::RUNNING, OperationState::COMPENSATION_PENDING], true)) {
            return ProviderResult::accepted('awaiting_confirmation', data: [
                'operation_id' => (int) $operation->id,
                'note'         => 'This request has already been sent and is awaiting confirmation.',
            ]);
        }

        return $this->dispatch($context, $meta, $connector, $operation);
    }

    // ── 3. confirm ───────────────────────────────────────────────────────────

    /**
     * Read provider truth back and settle an operation that execute() left
     * open — either `accepted` (acknowledged, unproven) or ambiguous.
     *
     * This is the ONLY way a Business Email object reaches a state that tells a
     * customer something is working.
     */
    public function confirm(EmailOperationContext $context): ProviderResult
    {
        $meta = Registry::get($context->capability);

        if ($meta === null) {
            return $this->refuse(Failure::UNKNOWN_CAPABILITY);
        }

        if ($context->workspaceId <= 0) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        return WorkspaceContext::run($context->workspaceId, fn () => $this->confirmWithin($context, $meta));
    }

    /** @param array<string,mixed> $meta */
    private function confirmWithin(EmailOperationContext $context, array $meta): ProviderResult
    {
        $connector = $this->connector();

        if ($connector === null) {
            return $this->refuse(Failure::PROVIDER_NOT_CONFIGURED);
        }

        $operation = $this->findOperationByKey($context, $meta);

        if ($operation === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        if (OperationState::isTerminal((string) $operation->state)) {
            return $this->replay($operation);
        }

        return $context->capability === Registry::DOMAIN_ONBOARD
            ? $this->confirmDomainOnboarding($context, $operation, $connector)
            : $this->confirmSubject($context, $meta, $operation, $connector);
    }

    // ── validation ───────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $meta
     * @return ProviderResult|null null when the request is valid
     */
    private function validate(EmailOperationContext $context, array $meta): ?ProviderResult
    {
        if ($context->workspaceId <= 0) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        // Tenancy, checked structurally rather than trusted. BelongsToWorkspace
        // is a blast-radius reducer, not an authorization control — its own
        // docblock says so — and a domain handed in from another workspace must
        // be refused here, not silently scoped away.
        if ($context->domain !== null && (int) $context->domain->workspace_id !== $context->workspaceId) {
            return $this->refuse(Failure::CROSS_TENANT);
        }

        if ($context->subject !== null) {
            $subjectWorkspace = $context->subject->getAttribute('workspace_id');

            if ($subjectWorkspace !== null && (int) $subjectWorkspace !== $context->workspaceId) {
                return $this->refuse(Failure::CROSS_TENANT);
            }
        }

        if (! $context->hasEntitlement($meta['entitlement'])) {
            return $this->refuse(Failure::NOT_ENTITLED);
        }

        // E2: an action the provider cannot perform is refused BEFORE a
        // governed operation exists. It is a configuration fact, not a provider
        // failure, and classifying it as one would put a retryable-looking
        // error in the queue for something that can never succeed.
        if (($denial = $this->validateProviderCapability($meta)) !== null) {
            return $denial;
        }

        // Separation of duties: the requester may not be the approver.
        if ($meta['approval_mode'] === Registry::APPROVAL_SEPARATION_OF_DUTIES) {
            if (! $context->approved || $context->approvedBy === null) {
                return $this->refuse(Failure::APPROVAL_REQUIRED);
            }

            if ($context->actorUserId !== null && $context->approvedBy === $context->actorUserId) {
                return $this->refuse(Failure::APPROVAL_REQUIRED);
            }
        } elseif ($meta['approval_mode'] === Registry::APPROVAL_PROTECTED && ! $context->approved) {
            return $this->refuse(Failure::APPROVAL_REQUIRED);
        }

        if (($denial = $this->validateDomain($context, $meta)) !== null) {
            return $denial;
        }

        if (($denial = $this->validateAddresses($context)) !== null) {
            return $denial;
        }

        if (($denial = $this->validateLifecycle($context)) !== null) {
            return $denial;
        }

        return $this->validatePreconditions($context, $meta);
    }

    /** @param array<string,mixed> $meta */
    private function validateProviderCapability(array $meta): ?ProviderResult
    {
        $flag = $meta['provider_capability'] ?? null;

        if ($flag === null) {
            return null;
        }

        $connector = $this->connector();

        // With no provider configured there is nothing to ask. Availability is
        // checked later and produces the more specific refusal.
        if ($connector === null) {
            return null;
        }

        return $connector->supports($flag) ? null : $this->refuse(Failure::CAPABILITY_NOT_SUPPORTED);
    }

    /** @param array<string,mixed> $meta */
    private function validateDomain(EmailOperationContext $context, array $meta): ?ProviderResult
    {
        $domain = $context->domain;

        if ($domain === null) {
            // Every capability in the registry acts on or beneath a domain.
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        // Custody governs what we may know and do. `unknown` means no platform
        // record ties this hostname to this workspace — we cannot responsibly
        // operate mail for something we cannot place. Observation is exempt:
        // looking is safe under any custody, and refusing to look is how a
        // misattributed domain stays misattributed.
        $changesSomething = $meta['idempotency'] !== Registry::IDEMPOTENCY_READ_ONLY;

        if ($domain->custody === Custody::UNKNOWN && $changesSomething) {
            return $this->refuse(Failure::CUSTODY_INSUFFICIENT);
        }

        // Nothing may be provisioned beneath a domain whose DNS has not been
        // observed. `accepted != verified`: EmailDomain::isDnsVerified() requires
        // both the evidence state AND the timestamp the evidence produced.
        $requiresVerifiedDomain = $meta['provider_mutation'] === true
            && $context->capability !== Registry::DOMAIN_ONBOARD;

        if ($requiresVerifiedDomain && ! $domain->isDnsVerified()) {
            return $this->refuse(Failure::DOMAIN_NOT_VERIFIED);
        }

        if ($domain->isTerminal()) {
            return $this->refuse(Failure::ILLEGAL_TRANSITION);
        }

        return null;
    }

    private function validateAddresses(EmailOperationContext $context): ?ProviderResult
    {
        $localPart = $context->input['local_part'] ?? $context->input['source_local_part'] ?? null;

        if ($localPart !== null) {
            if (! EmailAddress::isValidLocalPart((string) $localPart)) {
                return $this->refuse(Failure::INVALID_ADDRESS);
            }

            // Reserved addresses are administrative. They are assignable, but
            // only deliberately — never through a self-service create.
            if (EmailAddress::isReservedLocalPart((string) $localPart) && $context->source === 'manual') {
                return $this->refuse(Failure::RESERVED_LOCAL_PART);
            }
        }

        foreach (['target_address', 'destination_address'] as $key) {
            $address = $context->input[$key] ?? null;

            if ($address !== null && ! EmailAddress::isValid((string) $address)) {
                return $this->refuse(Failure::INVALID_ADDRESS);
            }
        }

        return null;
    }

    /**
     * A requested lifecycle move must be legal for the subject's own machine.
     * Illegal transitions fail closed with a typed refusal rather than an
     * uncaught InvalidArgumentException from the state machine.
     */
    private function validateLifecycle(EmailOperationContext $context): ?ProviderResult
    {
        if ($context->targetState === null) {
            return null;
        }

        $subject = $context->subject ?? $context->domain;

        if ($subject === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        $machine = $this->machineFor($subject);

        if ($machine === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        $current = (string) ($subject->getAttribute('lifecycle_state') ?? $subject->getAttribute('state'));

        if (! $machine::canTransition($current, $context->targetState)) {
            return $this->refuse(Failure::ILLEGAL_TRANSITION);
        }

        return null;
    }

    /** @param array<string,mixed> $meta */
    private function validatePreconditions(EmailOperationContext $context, array $meta): ?ProviderResult
    {
        // A forwarder may not be created until loop safety has cleared it, and
        // `unchecked` counts as blocking. The cost of being wrong here is mail
        // amplification against a third party.
        if (($meta['requires_loop_check'] ?? false) === true) {
            $forwarder = $context->subject;

            if (! $forwarder instanceof EmailForwarder || $forwarder->isLoopBlocked()) {
                return $this->refuse(Failure::LOOP_RISK);
            }
        }

        // Plan limits. A null limit means unlimited; zero means none.
        if ($context->capability === Registry::MAILBOX_CREATE) {
            $limit = $context->limit(Registry::ENTITLEMENT_MAILBOX_LIMIT);

            if ($limit !== null && $this->mailboxCount($context) >= $limit) {
                return $this->refuse(Failure::QUOTA_EXCEEDED);
            }
        }

        return null;
    }

    private function mailboxCount(EmailOperationContext $context): int
    {
        return WorkspaceContext::run($context->workspaceId, function () {
            return EmailMailbox::query()
                ->whereIn('lifecycle_state', \App\Engines\Infrastructure\Email\States\EmailMailboxState::billable())
                ->count();
        });
    }

    /**
     * @return class-string<\App\Engines\Infrastructure\States\StateMachine>|null
     */
    private function machineFor(object $subject): ?string
    {
        return match ($subject::class) {
            EmailDomain::class => EmailDomainState::class,
            EmailMailbox::class => \App\Engines\Infrastructure\Email\States\EmailMailboxState::class,
            \App\Engines\Infrastructure\Email\Models\EmailAlias::class
                => \App\Engines\Infrastructure\Email\States\EmailAliasState::class,
            EmailForwarder::class
                => \App\Engines\Infrastructure\Email\States\EmailForwarderState::class,
            EmailCatchAll::class
                => \App\Engines\Infrastructure\Email\States\EmailCatchAllState::class,
            default => null,
        };
    }

    // ── dispatch ─────────────────────────────────────────────────────────────

    /**
     * Move the operation into flight, call the provider once, and interpret.
     *
     * @param array<string,mixed> $meta
     */
    private function dispatch(
        EmailOperationContext $context,
        array $meta,
        EmailProviderConnector $connector,
        InfraOperation $operation
    ): ProviderResult {
        $subject = $context->subject;
        $plan = EmailFlightPlan::for($context->capability);

        // Most capabilities act on an entity beneath the domain and cannot
        // proceed without one. Domain-scoped capabilities act on the domain
        // itself and correctly have no subject.
        $domainScoped = in_array($context->capability, [Registry::DOMAIN_ONBOARD, Registry::USAGE_SYNC], true);

        if (! $domainScoped && $subject === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        return WorkspaceContext::run($context->workspaceId, function () use (
            $context, $meta, $connector, $operation, $subject, $plan
        ) {
            // The operation reaches RUNNING BEFORE the provider is called, so a
            // crash mid-flight leaves a record that says so.
            $this->advanceOperation($operation, [
                OperationState::APPROVED,
                OperationState::QUEUED,
                OperationState::RUNNING,
            ]);

            $operation->forceFill([
                'provider'   => $connector->provider(),
                'started_at' => now(),
            ])->save();

            // In-flight lifecycle, also before the call.
            if ($plan !== null && $plan['in_flight'] !== null && $subject !== null) {
                $this->moveSubject($subject, $plan['in_flight'], $context);
            }

            $callContext = $this->callContext($context, $meta, $operation);
            $result = $this->invoke($connector, $context, $meta, $callContext);

            return $this->interpret($result, $context, $meta, $operation, $subject, $plan, $callContext);
        });
    }

    /**
     * The single place a provider method is chosen. An explicit match rather
     * than reflection: a capability whose arguments change must be edited here,
     * visibly, rather than silently binding to a differently-shaped method.
     *
     * @param array<string,mixed> $meta
     */
    private function invoke(
        EmailProviderConnector $connector,
        EmailOperationContext $context,
        array $meta,
        ProviderCallContext $callContext
    ): ProviderResult {
        $domain = (string) $context->domain->domain;
        $subject = $context->subject;
        $input = $context->input;

        return match ($context->capability) {
            Registry::DOMAIN_ONBOARD => $connector->onboardDomain($domain, $callContext),

            Registry::MAILBOX_CREATE => $connector->createMailbox(
                $domain,
                new MailboxSpec(
                    (string) ($input['local_part'] ?? $subject->local_part),
                    $input['display_name'] ?? $subject->display_name,
                    (int) ($input['quota_mb'] ?? $subject->quota_mb),
                ),
                $callContext
            ),

            Registry::MAILBOX_UPDATE => $connector->updateMailbox(
                $this->requireRef($subject),
                new MailboxSpec(
                    (string) $subject->local_part,
                    $input['display_name'] ?? null,
                    isset($input['quota_mb']) ? (int) $input['quota_mb'] : null,
                ),
                $callContext
            ),

            Registry::MAILBOX_SUSPEND => $connector->suspendMailbox($this->requireRef($subject), $callContext),
            Registry::MAILBOX_RESTORE => $connector->restoreMailbox($this->requireRef($subject), $callContext),
            Registry::MAILBOX_DELETE  => $connector->deleteMailbox($this->requireRef($subject), $callContext),
            Registry::PASSWORD_RESET  => $connector->requestPasswordReset($this->requireRef($subject), $callContext),

            Registry::ALIAS_CREATE => $connector->createAlias(
                $domain,
                new AliasSpec(
                    (string) $subject->source_local_part,
                    (string) ($input['target_address'] ?? $this->resolveAliasTarget($subject, $domain)),
                    $this->providerRef($subject->targetMailbox ?? null),
                ),
                $callContext
            ),

            Registry::ALIAS_DELETE => $connector->deleteAlias($this->requireRef($subject), $callContext),

            Registry::FORWARDER_CREATE => $connector->createForwarder(
                $domain,
                new ForwarderSpec(
                    (string) $subject->source_local_part,
                    (string) $subject->destination_address,
                ),
                $callContext
            ),

            Registry::FORWARDER_DELETE => $connector->deleteForwarder($this->requireRef($subject), $callContext),

            Registry::CATCHALL_CONFIGURE => $connector->configureCatchAll(
                $domain,
                new CatchAllSpec((string) ($input['target_address'] ?? $this->resolveCatchAllTarget($subject, $domain))),
                $callContext
            ),

            Registry::CATCHALL_CLEAR => $connector->clearCatchAll($domain, $callContext),

            Registry::USAGE_SYNC => $connector->getUsage($domain, $callContext),

            default => $this->refuse(Failure::UNKNOWN_CAPABILITY),
        };
    }

    /**
     * Turn a provider answer into operation state, subject state, a binding and
     * a customer-safe result. The heart of the engine.
     *
     * @param array<string,mixed>      $meta
     * @param array<string,mixed>|null $plan
     */
    private function interpret(
        ProviderResult $result,
        EmailOperationContext $context,
        array $meta,
        InfraOperation $operation,
        ?Model $subject,
        ?array $plan,
        ProviderCallContext $callContext
    ): ProviderResult {
        $binds = (bool) ($plan['binds'] ?? false);

        // A success we cannot bind to is not a success. Storing an empty
        // provider reference would make the object unreachable forever, and is
        // indistinguishable from never having been provisioned.
        if (ProviderOutcome::isUnbindableSuccess($result, $binds)) {
            $result = ProviderResult::failed(
                'provider_malformed_response',
                'We could not confirm whether this completed. It is being checked.',
                'manual',
                'ambiguous',
                $result->correlationId,
            );
        }

        $outcome = ProviderOutcome::classify($result);

        // DOMAIN ONBOARDING IS TWO-PHASE, AND A VERIFIED FIRST PHASE IS STILL
        // NOT DONE. A provider confirming it registered the domain says nothing
        // about whether mail flows: the customer's DNS gate sits between. So
        // onboarding treats a verified answer as `accepted` and leaves the
        // operation open for confirm(), which runs only after DNS has been
        // observed. Closing it here would let a domain reach `active` without
        // anyone ever checking its MX records.
        if ($context->capability === Registry::DOMAIN_ONBOARD && $outcome === ProviderOutcome::VERIFIED) {
            $outcome = ProviderOutcome::ACCEPTED;
        }

        $operation->forceFill([
            'attempt_count'           => (int) $operation->attempt_count + 1,
            'provider_correlation_id' => $result->correlationId,
            'retry_classification'    => $result->retryClassification,
            // Password reset results are never persisted, whatever they contain.
            'result_json'             => ($meta['never_log_result'] ?? false)
                ? ['redacted' => true, 'outcome' => $outcome]
                : $result->toArray(),
        ]);

        switch ($outcome) {
            case ProviderOutcome::VERIFIED:
                if ($binds) {
                    $this->bindProviderResource($context, $subject, $result, $operation);
                }

                if ($plan !== null && $plan['success'] !== null && $subject !== null) {
                    $this->moveSubject($subject, $this->successStateFor($plan, $result), $context);
                }

                if ($context->capability === Registry::USAGE_SYNC) {
                    $this->recordUsage($context, $result, $operation);
                }

                $this->closeOperation($operation, OperationState::SUCCEEDED, null, null);
                $this->event($context, $meta['events'][0], InfraEvent::SEVERITY_SUCCESS, 'Completed and confirmed.', $operation->id);

                return $result;

            case ProviderOutcome::ACCEPTED:
                // Bind now if we were given a reference: the object may exist
                // even though we have not proven it, and losing the reference
                // would orphan it.
                if ($binds) {
                    $this->bindProviderResource($context, $subject, $result, $operation);
                }

                // Deliberately NO success transition. The subject stays in its
                // in-flight state until confirm() reads provider truth back.
                $operation->save();
                $this->event($context, 'email.operation_accepted', InfraEvent::SEVERITY_INFO,
                    'The service acknowledged the request. Awaiting confirmation.', $operation->id);

                return $result;

            case ProviderOutcome::AMBIGUOUS:
                // Never retried. The operation goes to compensation_pending —
                // reconcile against provider truth, never blind retry.
                $this->advanceOperation($operation, [OperationState::TIMED_OUT, OperationState::COMPENSATION_PENDING]);

                if ($plan !== null && $plan['ambiguous'] !== null && $subject !== null) {
                    $this->moveSubject($subject, $plan['ambiguous'], $context);
                }

                $operation->forceFill([
                    'failure_code'    => $result->errorCode,
                    'failure_summary' => $result->errorSummary,
                ])->save();

                $this->event($context, 'email.operation_ambiguous', InfraEvent::SEVERITY_WARNING,
                    $result->errorSummary, $operation->id, ['failure_code' => $result->errorCode]);

                return $result;

            case ProviderOutcome::RETRYABLE:
                // The subject stays in flight: the request may still succeed.
                $this->advanceOperation($operation, [OperationState::FAILED_RETRYABLE]);
                $operation->forceFill([
                    'failure_code'    => $result->errorCode,
                    'failure_summary' => $result->errorSummary,
                    'next_retry_at'   => now()->addMinutes(5),
                ])->save();

                $this->event($context, 'email.operation_failed', InfraEvent::SEVERITY_WARNING,
                    $result->errorSummary, $operation->id, ['failure_code' => $result->errorCode, 'retryable' => true]);

                return $result;

            default: // PERMANENT
                if ($plan !== null && $plan['failure'] !== null && $subject !== null) {
                    $this->moveSubject($subject, $plan['failure'], $context);
                }

                $this->closeOperation($operation, OperationState::FAILED_TERMINAL, $result->errorCode, $result->errorSummary);
                $this->event($context, 'email.operation_failed', InfraEvent::SEVERITY_ERROR,
                    $result->errorSummary, $operation->id, ['failure_code' => $result->errorCode]);

                return $result;
        }
    }

    // ── confirmation (read-back) ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $meta
     */
    private function confirmSubject(
        EmailOperationContext $context,
        array $meta,
        InfraOperation $operation,
        EmailProviderConnector $connector
    ): ProviderResult {
        $subject = $context->subject;
        $plan = EmailFlightPlan::for($context->capability);

        if ($subject === null || $plan === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        return WorkspaceContext::run($context->workspaceId, function () use (
            $context, $meta, $operation, $connector, $subject, $plan
        ) {
            $callContext = $this->callContext($context, $meta, $operation);
            $ref = $this->providerRef($subject);

            // A create whose reference we never received is confirmed by
            // enumeration instead: the object may exist under a reference the
            // provider never told us about, and that is exactly what a
            // malformed response leaves behind.
            $readBack = $ref === null
                ? $this->confirmByInventory($connector, $context, $callContext, $subject)
                : $this->confirmByReference($connector, $context, $callContext, $ref);

            $expectedGone = in_array($context->capability, [
                Registry::MAILBOX_DELETE, Registry::ALIAS_DELETE, Registry::FORWARDER_DELETE,
            ], true);

            $present = $readBack['present'];
            $confirmed = $expectedGone ? ! $present : $present;

            if (! $confirmed) {
                $this->closeOperation(
                    $operation,
                    OperationState::FAILED_TERMINAL,
                    'provider_object_missing',
                    'The service could not confirm this change.'
                );

                if ($plan['failure'] !== null) {
                    $this->moveSubject($subject, $plan['failure'], $context);
                }

                $this->event($context, 'email.confirmation_failed', InfraEvent::SEVERITY_ERROR,
                    'Read-back did not confirm the change.', $operation->id);

                return $this->refuse(Failure::PROVIDER_UNAVAILABLE);
            }

            // Bind late when the reference only became knowable now.
            if ($ref === null && $readBack['ref'] !== null && $plan['binds']) {
                $this->bindProviderResource(
                    $context,
                    $subject,
                    ProviderResult::verified('active', $readBack['ref'], 'confirmed'),
                    $operation
                );
            }

            if ($plan['success'] !== null) {
                $this->moveSubjectVia(
                    $subject,
                    $readBack['awaiting_activation'] ?? false
                        ? ($plan['success_pending'] ?? $plan['success'])
                        : $plan['success'],
                    $context
                );
            }

            // COMPENSATED, not SUCCEEDED, when the operation had been ambiguous:
            // the record must show that the outcome was recovered by
            // reconciliation rather than reported cleanly the first time.
            $terminal = $operation->state === OperationState::COMPENSATION_PENDING
                ? OperationState::COMPENSATED
                : OperationState::SUCCEEDED;

            $this->closeOperation($operation, $terminal, null, null);

            $this->event($context, 'email.confirmed', InfraEvent::SEVERITY_SUCCESS,
                'Confirmed against the service.', $operation->id, ['resolution' => $terminal]);

            return ProviderResult::verified(
                $expectedGone ? 'removed' : 'active',
                $readBack['ref'],
                'confirmed',
                ['operation_id' => (int) $operation->id, 'resolution' => $terminal],
            );
        });
    }

    /** @return array{present:bool,ref:?string} */
    private function confirmByReference(
        EmailProviderConnector $connector,
        EmailOperationContext $context,
        ProviderCallContext $callContext,
        string $ref
    ): array {
        $result = $context->subject instanceof EmailMailbox
            ? $connector->getMailboxStatus($ref, $callContext)
            : $connector->verify($ref);

        return [
            'present' => $result->success,
            'ref'     => $result->success ? $ref : null,
            // A mailbox whose owner has not yet accepted their invitation is
            // present but not usable, and the difference has to survive the
            // read-back or the engine will report it as working.
            'awaiting_activation' => $result->normalizedState === 'awaiting_activation',
        ];
    }

    /** @return array{present:bool,ref:?string,awaiting_activation?:bool} */
    private function confirmByInventory(
        EmailProviderConnector $connector,
        EmailOperationContext $context,
        ProviderCallContext $callContext,
        Model $subject
    ): array {
        $result = $connector->getInventory((string) $context->domain->domain, $callContext);

        if (! $result->success) {
            return ['present' => false, 'ref' => null];
        }

        $inventory = $result->data['inventory'] ?? null;

        if ($inventory === null) {
            return ['present' => false, 'ref' => null];
        }

        // An incomplete enumeration cannot prove absence — see
        // ProviderInventory::$complete. Refusing to conclude is the honest
        // answer; concluding "missing" would recreate an object that exists.
        if (! $inventory->complete) {
            return ['present' => false, 'ref' => null];
        }

        $localPart = strtolower((string) ($subject->local_part ?? $subject->source_local_part ?? ''));

        $found = $inventory->mailboxesByLocalPart()[$localPart]
            ?? $inventory->aliasesBySourceLocalPart()[$localPart]
            ?? null;

        return ['present' => $found !== null, 'ref' => $found?->providerRef];
    }

    /**
     * Domain onboarding completes differently from every other capability: the
     * DNS gate sits between the request and the finish, so confirmation is what
     * moves a verified domain through provisioning to active.
     */
    private function confirmDomainOnboarding(
        EmailOperationContext $context,
        InfraOperation $operation,
        EmailProviderConnector $connector
    ): ProviderResult {
        $domain = $context->domain;

        if ($domain === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        return WorkspaceContext::run($context->workspaceId, function () use ($context, $domain, $operation, $connector) {
            // A domain whose DNS has not been observed cannot be finished. This
            // is the gate that makes `accepted != verified` real for domains.
            if (! $domain->isDnsVerified()) {
                return $this->refuse(Failure::DOMAIN_NOT_VERIFIED);
            }

            if ($domain->lifecycle_state === EmailDomainState::DNS_VERIFIED) {
                $domain->transitionTo(EmailDomainState::PROVISIONING);
                $domain->save();
            }

            $meta = Registry::get(Registry::DOMAIN_ONBOARD);
            $callContext = $this->callContext($context, $meta, $operation);
            $status = $connector->getDomainAuthStatus((string) $domain->domain, $callContext);

            $active = (bool) ($status->data['active'] ?? false);

            if (! $status->success || ! $active) {
                $domain->transitionTo(EmailDomainState::PROVISIONING_FAILED);
                $domain->save();

                $this->closeOperation($operation, OperationState::FAILED_TERMINAL,
                    'provider_object_missing', 'The service has not finished setting up this domain.');

                return $this->refuse(Failure::PROVIDER_UNAVAILABLE);
            }

            $domain->transitionTo(EmailDomainState::ACTIVE);
            $domain->health_state = 'healthy';
            $domain->save();

            $terminal = $operation->state === OperationState::COMPENSATION_PENDING
                ? OperationState::COMPENSATED
                : OperationState::SUCCEEDED;

            $this->closeOperation($operation, $terminal, null, null);

            $this->event($context, 'email.domain.onboarded', InfraEvent::SEVERITY_SUCCESS,
                'Business Email is active for this domain.', $operation->id);

            return ProviderResult::verified('active', null, 'confirmed', [
                'operation_id' => (int) $operation->id,
                'resolution'   => $terminal,
            ]);
        });
    }

    // ── observation (read-only capabilities) ─────────────────────────────────

    /**
     * Read-only capabilities: they open no operation and change no provider.
     * DNS verification is the important one — it is the only thing that may
     * move a domain's verification state, and only on observed evidence.
     *
     * @param array<string,mixed> $meta
     */
    private function observe(
        EmailOperationContext $context,
        array $meta,
        EmailProviderConnector $connector
    ): ProviderResult {
        $domain = $context->domain;

        if ($domain === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        return WorkspaceContext::run($context->workspaceId, function () use ($context, $meta, $connector, $domain) {
            $callContext = $this->callContext($context, $meta, null);

            if ($context->capability === Registry::HEALTH_OBSERVE) {
                $health = $connector->healthCheck();

                $domain->health_state = $health->success ? 'healthy' : 'degraded';
                $domain->last_observed_at = now();
                $domain->save();

                return $health;
            }

            // DOMAIN_VERIFY.
            $result = $connector->verifyDomain((string) $domain->domain, $callContext);

            $domain->transitionVerificationTo(EmailVerificationState::CHECKING);
            $domain->save();

            // ONLY a verified answer is evidence. An `accepted` answer means the
            // provider queued a check — it says nothing about the records.
            if ($result->verified) {
                $domain->transitionVerificationTo(EmailVerificationState::VERIFIED);
                $domain->dns_verified_at = now();
                $domain->last_observed_at = now();

                if ($domain->lifecycle_state === EmailDomainState::VERIFYING_DNS) {
                    $domain->transitionTo(EmailDomainState::DNS_VERIFIED);
                }

                $domain->save();

                $this->event($context, 'email.domain.verification_observed', InfraEvent::SEVERITY_SUCCESS,
                    'DNS records observed and correct.', null);

                return $result;
            }

            $domain->transitionVerificationTo(EmailVerificationState::FAILED);
            $domain->last_observed_at = now();

            if ($domain->lifecycle_state === EmailDomainState::VERIFYING_DNS) {
                $domain->transitionTo(EmailDomainState::VERIFICATION_FAILED);
            }

            $domain->save();

            $this->event($context, 'email.domain.verification_observed', InfraEvent::SEVERITY_WARNING,
                'The required DNS records were not found.', null);

            return $this->refuse(Failure::DOMAIN_NOT_VERIFIED);
        });
    }

    /**
     * Ask the provider what the customer must publish, and persist it in our own
     * vocabulary. Separate from onboarding so a customer can re-read the
     * instructions without re-issuing a governed operation.
     */
    public function dnsRequirements(EmailOperationContext $context): ProviderResult
    {
        $connector = $this->connector();

        if ($connector === null) {
            return $this->refuse(Failure::PROVIDER_NOT_CONFIGURED);
        }

        $domain = $context->domain;

        if ($domain === null) {
            return $this->refuse(Failure::INVALID_CONTEXT);
        }

        return WorkspaceContext::run($context->workspaceId, function () use ($context, $connector, $domain) {
            $meta = Registry::get(Registry::DOMAIN_ONBOARD);
            $result = $connector->getDnsRequirements((string) $domain->domain, $this->callContext($context, $meta, null));

            if (! $result->success) {
                return $result;
            }

            /** @var array<int,MailDnsRecord> $records */
            $records = $result->data['records'] ?? [];

            $settings = $domain->settings_json ?? [];
            $settings['dns_requirements'] = MailDnsRecord::toCustomerArrayList($records);
            $settings['dns_requirements_observed_at'] = now()->toIso8601String();
            $domain->settings_json = $settings;

            if ($domain->verification_state === EmailVerificationState::UNVERIFIED) {
                $domain->transitionVerificationTo(EmailVerificationState::PENDING_RECORDS);
            }

            if ($domain->lifecycle_state === EmailDomainState::CONNECTED) {
                $domain->transitionTo(EmailDomainState::VERIFYING_DNS);
            }

            $domain->save();

            return $result;
        });
    }

    // ── provider resource bindings ───────────────────────────────────────────

    /**
     * Record the mapping between our business object and the provider's.
     *
     * Uses infra_provider_resources — the platform's existing binding spine —
     * rather than a `provider_*_ref` column on each email table. Rebinding to a
     * different provider changes this row and nothing else: the LevelUp
     * identity of the mailbox is its own primary key and never moves.
     */
    private function bindProviderResource(
        EmailOperationContext $context,
        ?Model $subject,
        ProviderResult $result,
        InfraOperation $operation
    ): void {
        if ($subject === null || $result->providerResourceId === null) {
            return;
        }

        $ownerType = $this->ownerTypeOf($subject);

        InfraProviderResource::withoutWorkspaceScope()
            ->updateOrCreate(
                [
                    'workspace_id' => $context->workspaceId,
                    'owner_type'   => $ownerType,
                    'owner_id'     => $subject->getKey(),
                ],
                [
                    'provider'               => (string) $operation->provider,
                    'provider_resource_type' => $ownerType,
                    'provider_resource_id'   => $result->providerResourceId,
                    'normalized_state'       => $result->normalizedState,
                    'provider_state'         => $result->providerState,
                    'last_synced_at'         => now(),
                    'last_operation_id'      => $operation->id,
                    'idempotency_reference'  => $operation->idempotency_key,
                ]
            );
    }

    /** The opaque provider reference bound to a business object, if any. */
    public function providerRef(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $row = InfraProviderResource::withoutWorkspaceScope()
            ->where('owner_type', $this->ownerTypeOf($subject))
            ->where('owner_id', $subject->getKey())
            ->first();

        return $row?->provider_resource_id;
    }

    private function requireRef(?Model $subject): string
    {
        $ref = $this->providerRef($subject);

        if ($ref === null) {
            // Acting on an object we never bound would mean guessing a provider
            // identifier. Refusing loudly is the only safe answer.
            throw new RuntimeException(
                'Business Email: no provider binding exists for this object, so it cannot be acted on. '
                . 'It was either never provisioned or its binding was lost.'
            );
        }

        return $ref;
    }

    private function ownerTypeOf(Model $subject): string
    {
        return match ($subject::class) {
            EmailDomain::class => self::RESOURCE_DOMAIN,
            EmailMailbox::class => self::RESOURCE_MAILBOX,
            \App\Engines\Infrastructure\Email\Models\EmailAlias::class => self::RESOURCE_ALIAS,
            EmailForwarder::class => self::RESOURCE_FORWARDER,
            EmailCatchAll::class => self::RESOURCE_CATCHALL,
            default => 'email_object',
        };
    }

    // ── usage ────────────────────────────────────────────────────────────────

    /**
     * Turn provider samples into email_usage rows.
     *
     * Copies nulls through unchanged. A provider that does not report message
     * counters produces null, and rendering that as zero would be a fabricated
     * number the customer cannot detect.
     */
    private function recordUsage(EmailOperationContext $context, ProviderResult $result, InfraOperation $operation): void
    {
        /** @var array<int,MailboxUsageSample> $samples */
        $samples = $result->data['samples'] ?? [];
        $domain = $context->domain;

        if ($domain === null) {
            return;
        }

        $mailboxes = EmailMailbox::query()
            ->where('email_domain_id', $domain->id)
            ->get()
            ->keyBy(fn (EmailMailbox $m) => strtolower((string) $m->local_part));

        foreach ($samples as $index => $sample) {
            $mailbox = $mailboxes[$sample->normalizedLocalPart()] ?? null;

            if ($mailbox === null) {
                continue;
            }

            EmailUsage::create([
                'workspace_id'      => $context->workspaceId,
                'email_domain_id'   => $domain->id,
                'scope'             => EmailUsage::SCOPE_MAILBOX,
                'email_mailbox_id'  => $mailbox->id,
                'storage_used_mb'   => $sample->storageUsedMb,
                'storage_quota_mb'  => $sample->storageQuotaMb,
                'messages_sent'     => $sample->messagesSent,
                'messages_received' => $sample->messagesReceived,
                'source'            => EmailUsage::SOURCE_PROVIDER,
                'confidence'        => \App\Engines\Infrastructure\Observation\AssetLifecycle::C_VERIFIED,
                'observed_at'       => $sample->observedAt,
                'operation_id'      => $operation->id,
                'idempotency_key'   => $operation->idempotency_key . ':' . $index,
            ]);

            $mailbox->forceFill([
                'storage_used_mb'   => $sample->storageUsedMb,
                'usage_observed_at' => $sample->observedAt,
            ])->save();
        }
    }

    // ── operations ───────────────────────────────────────────────────────────

    /**
     * Find or create the infra_operations row for this request.
     *
     * Created BEFORE anything is attempted, matching the house doctrine on
     * InfraOperation: a crash mid-flight must leave a recoverable record rather
     * than an invisible orphan.
     *
     * @param array<string,mixed> $meta
     */
    private function openOperation(EmailOperationContext $context, array $meta): InfraOperation
    {
        $key = $this->idempotencyKey($context, $meta);

        return WorkspaceContext::run($context->workspaceId, function () use ($context, $meta, $key) {
            $existing = InfraOperation::query()
                ->where('workspace_id', $context->workspaceId)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return InfraOperation::create([
                'workspace_id'    => $context->workspaceId,
                'owner_type'      => $meta['resource_type'],
                // Null when the entity does not exist yet — a create has no
                // subject. Writing the domain's id here instead would make the
                // owner column point at the wrong kind of thing.
                'owner_id'        => $context->subject?->getKey(),
                'operation'       => $context->capability,
                'state'           => OperationState::initial(),
                'capability'      => self::CAPABILITY,
                'provider'        => null,
                'idempotency_key' => $key,
                'max_attempts'    => (int) config('infrastructure.operations.default_max_attempts', 3),
                'actor_user_id'   => $context->actorUserId,
                'source'          => $context->source,
                'request_json'    => $context->toAuditArray(),
            ]);
        });
    }

    private function loadOperation(int $workspaceId, int $operationId): ?InfraOperation
    {
        if ($operationId <= 0) {
            return null;
        }

        return WorkspaceContext::run($workspaceId, fn () => InfraOperation::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $operationId)
            ->first());
    }

    /** @param array<string,mixed> $meta */
    private function findOperationByKey(EmailOperationContext $context, array $meta): ?InfraOperation
    {
        $key = $this->idempotencyKey($context, $meta);

        return WorkspaceContext::run($context->workspaceId, fn () => InfraOperation::query()
            ->where('workspace_id', $context->workspaceId)
            ->where('idempotency_key', $key)
            ->first());
    }

    /**
     * A stable key per logical request.
     *
     * @param array<string,mixed> $meta
     */
    private function idempotencyKey(EmailOperationContext $context, array $meta): string
    {
        if ($context->idempotencyKey !== null && $context->idempotencyKey !== '') {
            return $context->idempotencyKey;
        }

        if ($meta['idempotency'] === Registry::IDEMPOTENCY_DERIVED) {
            $subject = $context->subject ?? $context->domain;

            return $context->capability . ':' . ($subject?->getKey() ?? 'none');
        }

        // A mutating capability with no key would let a retried job act twice.
        // Refusing to invent one is the point: an unstable key is worse than no
        // idempotency at all, because it looks like protection and is not.
        throw new RuntimeException(
            "Business Email capability '{$context->capability}' requires an idempotency key from the caller."
        );
    }

    /** @param array<int,string> $states */
    private function advanceOperation(InfraOperation $operation, array $states): void
    {
        foreach ($states as $state) {
            if ($operation->state === $state) {
                continue;
            }

            $operation->transitionTo($state);
        }

        $operation->save();
    }

    private function closeOperation(InfraOperation $operation, string $terminal, ?string $code, ?string $summary): void
    {
        if (! OperationState::isTerminal((string) $operation->state)) {
            $operation->transitionTo($terminal);
        }

        $operation->forceFill([
            'failure_code'    => $code,
            'failure_summary' => $summary,
            'finished_at'     => now(),
        ])->save();
    }

    /**
     * Record that a legitimate request cannot proceed because no provider is
     * configured, WITHOUT closing the operation.
     */
    private function parkOperation(
        InfraOperation $operation,
        ProviderResult $result,
        EmailOperationContext $context
    ): void {
        WorkspaceContext::run($context->workspaceId, function () use ($operation, $result, $context) {
            $operation->forceFill([
                'failure_code'         => $result->errorCode,
                'failure_summary'      => $result->errorSummary,
                'retry_classification' => $result->retryClassification,
                'result_json'          => $result->toArray(),
            ])->save();

            $this->event(
                $context,
                'email.operation_unavailable',
                InfraEvent::SEVERITY_WARNING,
                $result->errorSummary,
                $operation->id,
                ['failure_code' => $result->errorCode]
            );
        });
    }

    /** The recorded outcome of an already-completed operation. */
    private function replay(InfraOperation $operation): ProviderResult
    {
        if (in_array($operation->state, [OperationState::SUCCEEDED, OperationState::COMPENSATED], true)) {
            // Replaying a completed operation is not a fresh confirmation.
            return ProviderResult::accepted(
                normalizedState: 'already_completed',
                data: ['operation_id' => (int) $operation->id, 'resolution' => $operation->state],
            );
        }

        $code = (string) ($operation->failure_code ?: Failure::PROVIDER_NOT_CONFIGURED);

        return ProviderResult::failed(
            errorCode: $code,
            errorSummary: (string) ($operation->failure_summary ?: Failure::message($code)),
            retryClassification: (string) ($operation->retry_classification ?: Failure::retryClassification($code)),
            normalizedState: Failure::normalizedState($code),
        );
    }

    /** @param array<string,mixed> $meta */
    private function callContext(EmailOperationContext $context, array $meta, ?InfraOperation $operation): ProviderCallContext
    {
        return new ProviderCallContext(
            workspaceId: $context->workspaceId,
            ownerType: $meta['resource_type'],
            ownerId: $context->subject?->getKey(),
            idempotencyKey: $operation?->idempotency_key ?? $this->idempotencyKeyOrObservation($context, $meta),
            correlationId: null,
            operationId: $operation?->id,
            actorUserId: $context->actorUserId,
        );
    }

    /**
     * Read-only capabilities have no operation and therefore no stored key, but
     * ProviderCallContext requires one. A key derived from the observation's own
     * identity is honest: repeating the same observation IS the same request.
     *
     * @param array<string,mixed> $meta
     */
    private function idempotencyKeyOrObservation(EmailOperationContext $context, array $meta): string
    {
        if ($context->idempotencyKey !== null && $context->idempotencyKey !== '') {
            return $context->idempotencyKey;
        }

        return $context->capability . ':observe:' . ($context->domain?->getKey() ?? 'none');
    }

    // ── subject transitions ──────────────────────────────────────────────────

    /**
     * Which success state a confirmed operation should land in.
     *
     * INFRA888 · E7. Most operations have exactly one. Creating a mailbox has
     * two, because a provider that invites the owner to set their own password
     * returns an object that exists and cannot yet be used.
     */
    private function successStateFor(array $plan, ProviderResult $result): string
    {
        if (($plan['success_pending'] ?? null) !== null
            && $result->normalizedState === 'awaiting_activation') {
            return $plan['success_pending'];
        }

        return $plan['success'];
    }

    private function moveSubject(Model $subject, string $state, EmailOperationContext $context): void
    {
        $current = (string) ($subject->getAttribute('lifecycle_state') ?? $subject->getAttribute('state'));

        if ($current === $state) {
            return;
        }

        $subject->transitionTo($state);
        $subject->state_changed_by_user_id = $context->actorUserId;
        $subject->save();
    }

    /**
     * Move to a state that may not be directly reachable from where we are.
     *
     * Reconciliation is the case: a create that ended ambiguous sits in
     * `reconciling`, and the machine deliberately forbids reconciling ->
     * provisioning (that is the duplicate-create path) while allowing
     * reconciling -> active. Where a single hop is illegal but a legal path
     * exists, take it explicitly rather than forcing the state — every
     * intermediate step stays audited.
     */
    private function moveSubjectVia(Model $subject, string $target, EmailOperationContext $context): void
    {
        $machine = $this->machineFor($subject);
        $current = (string) ($subject->getAttribute('lifecycle_state') ?? $subject->getAttribute('state'));

        if ($machine === null || $current === $target) {
            return;
        }

        if ($machine::canTransition($current, $target)) {
            $this->moveSubject($subject, $target, $context);

            return;
        }

        // One intermediate hop is enough for every machine in this engine.
        foreach ($machine::transitions()[$current] ?? [] as $intermediate) {
            if ($machine::canTransition($intermediate, $target)) {
                $this->moveSubject($subject, $intermediate, $context);
                $this->moveSubject($subject, $target, $context);

                return;
            }
        }

        throw new RuntimeException(
            "Business Email: no legal path from '{$current}' to '{$target}'. Forcing the state would hide a "
            . 'lifecycle defect rather than surface it.'
        );
    }

    private function resolveAliasTarget(Model $alias, string $domain): string
    {
        if ($alias->target_address !== null) {
            return (string) $alias->target_address;
        }

        $mailbox = $alias->targetMailbox;

        return (string) EmailAddress::compose((string) $mailbox?->local_part, $domain);
    }

    private function resolveCatchAllTarget(Model $catchAll, string $domain): string
    {
        if ($catchAll->target_address !== null) {
            return (string) $catchAll->target_address;
        }

        $mailbox = $catchAll->targetMailbox;

        return (string) EmailAddress::compose((string) $mailbox?->local_part, $domain);
    }

    // ── audit ────────────────────────────────────────────────────────────────

    private function recordDenial(EmailOperationContext $context, ProviderResult $result): void
    {
        if ($context->workspaceId <= 0) {
            return;
        }

        WorkspaceContext::run($context->workspaceId, function () use ($context, $result) {
            $this->event(
                $context,
                'email.request_denied',
                InfraEvent::SEVERITY_WARNING,
                $result->errorSummary,
                null,
                ['failure_code' => $result->errorCode]
            );
        });
    }

    /** @param array<string,mixed> $extra */
    private function event(
        EmailOperationContext $context,
        string $event,
        string $severity,
        ?string $summary,
        ?int $operationId,
        array $extra = []
    ): void {
        $meta = Registry::get($context->capability);

        $ownerType = $context->subject !== null
            ? ($meta['resource_type'] ?? EmailDomain::OWNER_TYPE)
            : EmailDomain::OWNER_TYPE;

        InfraEvent::create([
            'workspace_id'  => $context->workspaceId,
            'owner_type'    => $ownerType,
            'owner_id'      => $context->subject?->getKey() ?? $context->domain?->getKey(),
            'event'         => $event,
            'severity'      => $severity,
            'operation_id'  => $operationId,
            'actor_user_id' => $context->actorUserId,
            'source'        => $context->source,
            'provider'      => null,
            'summary'       => $summary,
            'context_json'  => $context->toAuditArray() + $extra,
            'created_at'    => now(),
        ]);
    }

    // ── refusals ─────────────────────────────────────────────────────────────

    /**
     * The only way this class produces a failure. Centralised so every refusal
     * carries a customer-safe message, a correct retry classification and a
     * normalized state — and so no code path can invent an untyped one.
     */
    private function refuse(string $code): ProviderResult
    {
        return ProviderResult::failed(
            errorCode: $code,
            errorSummary: Failure::message($code),
            retryClassification: Failure::retryClassification($code),
            normalizedState: Failure::normalizedState($code),
        );
    }
}
