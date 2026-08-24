<?php

namespace App\Engines\Infrastructure\Email\Registry;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Models\InfraProviderConnection;

/**
 * INFRA888 · E1/E2 — BUSINESS EMAIL CAPABILITY REGISTRY.
 *
 * Every Business Email operation is declared ONCE, here, with the governance
 * metadata that decides who may run it, whether it needs approval, what it
 * costs, how it stays idempotent and what it emits. Phase 0 found that a new
 * platform action previously had to be registered in nine hand-synced arrays
 * with no test enforcing agreement, and that drift was already live.
 *
 * ─── WHAT E2 CHANGED ────────────────────────────────────────────────────────
 *
 * E1 shipped this registry with five capabilities whose contract method did not
 * exist. They were declared as gaps rather than invented, and that list was the
 * E2 worklist. E2 closed all five, so `contractGaps()` now returns an empty
 * array and a test pins it there.
 *
 * E2 also added a SIXTEENTH capability — `email.catchall.clear`. E1 modelled
 * clearing a catch-all as configuring it with no target. That is the shape that
 * makes a single mistyped field the difference between "deliver everything
 * here" and "deliver nothing anywhere", so clearing became its own capability
 * with its own contract method, its own risk class and its own provider flag.
 *
 * And every capability now declares `provider_capability`: the flag the engine
 * checks BEFORE opening a governed operation. An action a provider cannot
 * perform is refused as unsupported, not attempted and reported as a provider
 * failure — the difference matters because one is a configuration fact and the
 * other looks like an outage.
 *
 * ─── WHY THIS IS STILL SEPARATE FROM InfrastructureCapabilityRegistry ───────
 *
 * That class is the registry of operations INFRA888 can execute against a
 * PRODUCTION provider today. Business Email has a finalised contract and a
 * proven engine, but no vendor adapter — E5. Merging these entries now would
 * make that registry claim sixteen production-ready operations that resolve to
 * nothing, which is the `keyword_research` defect the Phase 0 audit named.
 * The merge is an explicit E3 exit criterion.
 */
final class BusinessEmailCapabilityRegistry
{
    public const ENGINE = 'business_email';

    // ── capability slugs ─────────────────────────────────────────────────────
    public const DOMAIN_ONBOARD     = 'email.domain.onboard';
    public const DOMAIN_VERIFY      = 'email.domain.verify';
    public const MAILBOX_CREATE     = 'email.mailbox.create';
    public const MAILBOX_UPDATE     = 'email.mailbox.update';
    public const MAILBOX_SUSPEND    = 'email.mailbox.suspend';
    public const MAILBOX_RESTORE    = 'email.mailbox.restore';
    public const MAILBOX_DELETE     = 'email.mailbox.delete';
    public const PASSWORD_RESET     = 'email.password.reset';
    public const ALIAS_CREATE       = 'email.alias.create';
    public const ALIAS_DELETE       = 'email.alias.delete';
    public const FORWARDER_CREATE   = 'email.forwarder.create';
    public const FORWARDER_DELETE   = 'email.forwarder.delete';
    public const CATCHALL_CONFIGURE = 'email.catchall.configure';
    /** E2: clearing is not configuring-with-a-null. */
    public const CATCHALL_CLEAR     = 'email.catchall.clear';
    public const USAGE_SYNC         = 'email.usage.sync';
    public const HEALTH_OBSERVE     = 'email.health.observe';

    // ── risk (shared vocabulary with InfrastructureCapabilityRegistry) ───────
    public const RISK_LOW          = 'low';
    public const RISK_MEDIUM       = 'medium';
    public const RISK_HIGH         = 'high';
    public const RISK_IRREVERSIBLE = 'irreversible';

    // ── approval policy ──────────────────────────────────────────────────────
    public const APPROVAL_AUTOMATIC = 'automatic';
    public const APPROVAL_PROTECTED = 'protected';
    public const APPROVAL_SEPARATION_OF_DUTIES = 'protected_sod';

    // ── cost class (what the operation does to LevelUp's provider bill) ──────
    public const COST_NONE = 'none';
    public const COST_OPERATIONAL = 'operational';
    public const COST_BILLABLE = 'billable';

    // ── idempotency strategy ─────────────────────────────────────────────────
    public const IDEMPOTENCY_CALLER_KEY = 'caller_key';
    public const IDEMPOTENCY_DERIVED = 'derived_from_identity';
    public const IDEMPOTENCY_READ_ONLY = 'read_only';

    // ── entitlement keys ─────────────────────────────────────────────────────
    public const ENTITLEMENT_ACCESS = 'business_email_access';
    public const ENTITLEMENT_MAILBOX_LIMIT = 'business_email_mailbox_limit';
    public const ENTITLEMENT_DOMAIN_LIMIT = 'business_email_domain_limit';

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function capabilities(): array
    {
        return [
            // ── domain ───────────────────────────────────────────────────────
            self::DOMAIN_ONBOARD => [
                'capability'           => self::DOMAIN_ONBOARD,
                'summary'              => 'Register a domain for Business Email and issue its required DNS records.',
                'resource_type'        => EmailDomain::OWNER_TYPE,
                'risk'                 => self::RISK_LOW,
                'reversible'           => true,
                'cost_class'           => self::COST_NONE,
                'approval_mode'        => self::APPROVAL_AUTOMATIC,
                'idempotency'          => self::IDEMPOTENCY_DERIVED,
                'events'               => ['email.domain.onboarded'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                // E2 correction: E1 recorded this as false because it mapped
                // onboarding to a status read. Registering a domain at a
                // provider is a real mutation almost everywhere.
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'onboardDomain',
                'provider_capability'  => EmailProviderCapability::DOMAIN_ONBOARD,
                'contract_gap'         => false,
            ],

            self::DOMAIN_VERIFY => [
                'capability'           => self::DOMAIN_VERIFY,
                'summary'              => 'Observe whether the required DNS records are present and correct.',
                'resource_type'        => EmailDomain::OWNER_TYPE,
                'risk'                 => self::RISK_LOW,
                'reversible'           => true,
                'cost_class'           => self::COST_NONE,
                'approval_mode'        => self::APPROVAL_AUTOMATIC,
                // Read-only: it changes nothing anywhere, so it opens no
                // governed operation. What it produces is evidence.
                'idempotency'          => self::IDEMPOTENCY_READ_ONLY,
                'events'               => ['email.domain.verification_observed'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => false,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'verifyDomain',
                'provider_capability'  => EmailProviderCapability::DOMAIN_VERIFY,
                'contract_gap'         => false,
            ],

            // ── mailbox ──────────────────────────────────────────────────────
            self::MAILBOX_CREATE => [
                'capability'           => self::MAILBOX_CREATE,
                'summary'              => 'Create a mailbox at the provider.',
                'resource_type'        => EmailMailbox::OWNER_TYPE,
                'risk'                 => self::RISK_MEDIUM,
                'reversible'           => true,
                'cost_class'           => self::COST_BILLABLE,
                'approval_mode'        => self::APPROVAL_AUTOMATIC,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.mailbox.created'],
                'entitlement'          => self::ENTITLEMENT_MAILBOX_LIMIT,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'createMailbox',
                'provider_capability'  => EmailProviderCapability::MAILBOX_CREATE,
                'contract_gap'         => false,
            ],

            self::MAILBOX_UPDATE => [
                'capability'           => self::MAILBOX_UPDATE,
                'summary'              => 'Change a mailbox display name or storage quota.',
                'resource_type'        => EmailMailbox::OWNER_TYPE,
                'risk'                 => self::RISK_LOW,
                'reversible'           => true,
                'cost_class'           => self::COST_BILLABLE,
                'approval_mode'        => self::APPROVAL_AUTOMATIC,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.mailbox.updated'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                // E2 correction: E1 pointed this at setMailboxQuota, which
                // could not change a display name — the capability named
                // something the method did not do. Replaced by updateMailbox.
                'connector_method'     => 'updateMailbox',
                'provider_capability'  => EmailProviderCapability::MAILBOX_UPDATE,
                'contract_gap'         => false,
            ],

            self::MAILBOX_SUSPEND => [
                'capability'           => self::MAILBOX_SUSPEND,
                'summary'              => 'Stop mail delivery for a mailbox while retaining its data.',
                'resource_type'        => EmailMailbox::OWNER_TYPE,
                'risk'                 => self::RISK_HIGH,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.mailbox.suspended'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'suspendMailbox',
                'provider_capability'  => EmailProviderCapability::MAILBOX_SUSPEND,
                'contract_gap'         => false,
            ],

            self::MAILBOX_RESTORE => [
                'capability'           => self::MAILBOX_RESTORE,
                'summary'              => 'Resume mail delivery for a suspended mailbox.',
                'resource_type'        => EmailMailbox::OWNER_TYPE,
                'risk'                 => self::RISK_MEDIUM,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.mailbox.restored'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                // E2 GAP CLOSED. A contract that could stop mail but not start
                // it again was a one-way door.
                'connector_method'     => 'restoreMailbox',
                'provider_capability'  => EmailProviderCapability::MAILBOX_RESTORE,
                'contract_gap'         => false,
            ],

            self::MAILBOX_DELETE => [
                'capability'           => self::MAILBOX_DELETE,
                'summary'              => 'Delete a mailbox and its stored mail at the provider.',
                'resource_type'        => EmailMailbox::OWNER_TYPE,
                'risk'                 => self::RISK_IRREVERSIBLE,
                'reversible'           => false,
                'cost_class'           => self::COST_BILLABLE,
                'approval_mode'        => self::APPROVAL_SEPARATION_OF_DUTIES,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.mailbox.deleted'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'deleteMailbox',
                'provider_capability'  => EmailProviderCapability::MAILBOX_DELETE,
                'contract_gap'         => false,
            ],

            self::PASSWORD_RESET => [
                'capability'           => self::PASSWORD_RESET,
                'summary'              => 'Request a password reset for a mailbox. No password is ever returned.',
                'resource_type'        => EmailMailbox::OWNER_TYPE,
                'risk'                 => self::RISK_HIGH,
                'reversible'           => false,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.mailbox.password_reset_requested'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'requestPasswordReset',
                'provider_capability'  => EmailProviderCapability::MAILBOX_PASSWORD_RESET,
                'contract_gap'         => false,
                // Load-bearing: no credential material may reach a log, an
                // event payload, an operation record or a customer response.
                'never_log_result'     => true,
            ],

            // ── aliases ──────────────────────────────────────────────────────
            self::ALIAS_CREATE => [
                'capability'           => self::ALIAS_CREATE,
                'summary'              => 'Route an additional address into a mailbox.',
                'resource_type'        => EmailAlias::OWNER_TYPE,
                'risk'                 => self::RISK_LOW,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_AUTOMATIC,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.alias.created'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'createAlias',
                'provider_capability'  => EmailProviderCapability::ALIAS_CREATE,
                'contract_gap'         => false,
            ],

            self::ALIAS_DELETE => [
                'capability'           => self::ALIAS_DELETE,
                'summary'              => 'Stop routing an address into a mailbox.',
                'resource_type'        => EmailAlias::OWNER_TYPE,
                'risk'                 => self::RISK_MEDIUM,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.alias.deleted'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                // E2 GAP CLOSED. Takes the ALIAS's own opaque reference, not
                // the mailbox's — the shape the old contract implied would have
                // made deletion impossible for any provider that models an
                // alias as a first-class object.
                'connector_method'     => 'deleteAlias',
                'provider_capability'  => EmailProviderCapability::ALIAS_DELETE,
                'contract_gap'         => false,
            ],

            // ── forwarders ───────────────────────────────────────────────────
            self::FORWARDER_CREATE => [
                'capability'           => self::FORWARDER_CREATE,
                'summary'              => 'Relay mail from an address to an external destination.',
                'resource_type'        => EmailForwarder::OWNER_TYPE,
                'risk'                 => self::RISK_HIGH,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.forwarder.created'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'createForwarder',
                'provider_capability'  => EmailProviderCapability::FORWARDER_CREATE,
                'contract_gap'         => false,
                // Engine precondition: ForwarderLoopSafety must have cleared it.
                'requires_loop_check'  => true,
            ],

            self::FORWARDER_DELETE => [
                'capability'           => self::FORWARDER_DELETE,
                'summary'              => 'Stop relaying mail to an external destination.',
                'resource_type'        => EmailForwarder::OWNER_TYPE,
                'risk'                 => self::RISK_MEDIUM,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.forwarder.deleted'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                // E2 GAP CLOSED.
                'connector_method'     => 'deleteForwarder',
                'provider_capability'  => EmailProviderCapability::FORWARDER_DELETE,
                'contract_gap'         => false,
            ],

            // ── catch-all ────────────────────────────────────────────────────
            self::CATCHALL_CONFIGURE => [
                'capability'           => self::CATCHALL_CONFIGURE,
                'summary'              => 'Set or change the destination for unassigned addresses.',
                'resource_type'        => EmailCatchAll::OWNER_TYPE,
                'risk'                 => self::RISK_HIGH,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.catchall.configured'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                // E2 GAP CLOSED.
                'connector_method'     => 'configureCatchAll',
                // The masterplan records catch-all support as varying by
                // provider. This flag is what lets a future UI hide the action
                // rather than offer one that fails.
                'provider_capability'  => EmailProviderCapability::CATCHALL_CONFIGURE,
                'contract_gap'         => false,
            ],

            self::CATCHALL_CLEAR => [
                'capability'           => self::CATCHALL_CLEAR,
                'summary'              => 'Turn off catch-all delivery for a domain.',
                'resource_type'        => EmailCatchAll::OWNER_TYPE,
                // Turning a catch-all OFF starts rejecting mail that was
                // previously being delivered. Reversible, but silently
                // consequential to anyone still writing to an old address.
                'risk'                 => self::RISK_HIGH,
                'reversible'           => true,
                'cost_class'           => self::COST_OPERATIONAL,
                'approval_mode'        => self::APPROVAL_PROTECTED,
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.catchall.cleared'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => true,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => true,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'clearCatchAll',
                'provider_capability'  => EmailProviderCapability::CATCHALL_CLEAR,
                'contract_gap'         => false,
            ],

            // ── observation ──────────────────────────────────────────────────
            self::USAGE_SYNC => [
                'capability'           => self::USAGE_SYNC,
                'summary'              => 'Sample storage and message counters from the provider.',
                'resource_type'        => EmailDomain::OWNER_TYPE,
                'risk'                 => self::RISK_LOW,
                'reversible'           => true,
                'cost_class'           => self::COST_NONE,
                'approval_mode'        => self::APPROVAL_AUTOMATIC,
                // Reads the provider but WRITES email_usage rows, so a retried
                // sync must not double-insert.
                'idempotency'          => self::IDEMPOTENCY_CALLER_KEY,
                'events'               => ['email.usage.sampled'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => false,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => false,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                // E2 GAP CLOSED. Note the method is a READ: "sync" is what the
                // engine does with the answer, which is why there is no
                // syncUsage() on the contract.
                'connector_method'     => 'getUsage',
                'provider_capability'  => EmailProviderCapability::USAGE_SYNC,
                'contract_gap'         => false,
            ],

            self::HEALTH_OBSERVE => [
                'capability'           => self::HEALTH_OBSERVE,
                'summary'              => 'Observe provider reachability.',
                'resource_type'        => EmailDomain::OWNER_TYPE,
                'risk'                 => self::RISK_LOW,
                'reversible'           => true,
                'cost_class'           => self::COST_NONE,
                'approval_mode'        => self::APPROVAL_AUTOMATIC,
                'idempotency'          => self::IDEMPOTENCY_READ_ONLY,
                'events'               => ['email.health.observed'],
                'entitlement'          => self::ENTITLEMENT_ACCESS,
                'customer_available'   => false,
                'admin_available'      => true,
                'requires_provider'    => true,
                'provider_mutation'    => false,
                'connector_capability' => InfraProviderConnection::CAPABILITY_EMAIL,
                'connector_contract'   => EmailProviderConnector::class,
                'connector_method'     => 'healthCheck',
                // No flag: healthCheck is on the base infrastructure contract,
                // so every connector has it by construction.
                'provider_capability'  => null,
                'contract_gap'         => false,
            ],
        ];
    }

    public static function has(string $capability): bool
    {
        return array_key_exists($capability, self::capabilities());
    }

    public static function get(string $capability): ?array
    {
        return self::capabilities()[$capability] ?? null;
    }

    /** @return array<int,string> */
    public static function slugs(): array
    {
        return array_keys(self::capabilities());
    }

    /** Operations that mutate provider state. */
    public static function mutating(): array
    {
        return array_keys(array_filter(
            self::capabilities(),
            fn (array $m) => $m['provider_mutation'] === true
        ));
    }

    /** Operations that open a governed infra_operations row. */
    public static function governed(): array
    {
        return array_keys(array_filter(
            self::capabilities(),
            fn (array $m) => $m['idempotency'] !== self::IDEMPOTENCY_READ_ONLY
        ));
    }

    /** Operations that may not run without an approval record. */
    public static function requiringApproval(): array
    {
        return array_keys(array_filter(
            self::capabilities(),
            fn (array $m) => $m['approval_mode'] !== self::APPROVAL_AUTOMATIC
        ));
    }

    /** Operations a requester may never approve for themselves. */
    public static function requiringSeparationOfDuties(): array
    {
        return array_keys(array_filter(
            self::capabilities(),
            fn (array $m) => $m['approval_mode'] === self::APPROVAL_SEPARATION_OF_DUTIES
        ));
    }

    /** Operations a customer surface may offer. */
    public static function customerFacing(): array
    {
        return array_keys(array_filter(
            self::capabilities(),
            fn (array $m) => $m['customer_available'] === true
        ));
    }

    /**
     * Capabilities whose contract method does not exist.
     *
     * This was the E2 worklist. It is now empty, and a test keeps it that way:
     * a future capability added without a contract method fails the build
     * rather than becoming permanently unimplementable.
     */
    public static function contractGaps(): array
    {
        return array_keys(array_filter(
            self::capabilities(),
            fn (array $m) => $m['contract_gap'] === true
        ));
    }

    /** The provider capability flag an operation needs, or null when always available. */
    public static function providerCapabilityFor(string $capability): ?string
    {
        return self::capabilities()[$capability]['provider_capability'] ?? null;
    }

    /** Every event name this engine may emit. */
    public static function events(): array
    {
        $events = [];

        foreach (self::capabilities() as $meta) {
            foreach ($meta['events'] as $event) {
                $events[$event] = true;
            }
        }

        return array_keys($events);
    }

    /** Every metadata key a capability entry must declare. */
    public static function requiredMetadataKeys(): array
    {
        return [
            'capability', 'summary', 'resource_type', 'risk', 'reversible',
            'cost_class', 'approval_mode', 'idempotency', 'events', 'entitlement',
            'customer_available', 'admin_available', 'requires_provider',
            'provider_mutation', 'connector_capability', 'connector_contract',
            'connector_method', 'provider_capability', 'contract_gap',
        ];
    }

    public static function risks(): array
    {
        return [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH, self::RISK_IRREVERSIBLE];
    }

    public static function approvalModes(): array
    {
        return [self::APPROVAL_AUTOMATIC, self::APPROVAL_PROTECTED, self::APPROVAL_SEPARATION_OF_DUTIES];
    }

    public static function costClasses(): array
    {
        return [self::COST_NONE, self::COST_OPERATIONAL, self::COST_BILLABLE];
    }

    public static function idempotencyStrategies(): array
    {
        return [self::IDEMPOTENCY_CALLER_KEY, self::IDEMPOTENCY_DERIVED, self::IDEMPOTENCY_READ_ONLY];
    }
}
