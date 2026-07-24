<?php

namespace App\Engines\Infrastructure\Registry;

use App\Connectors\Infrastructure\Contracts\HostingProviderConnector;
use App\Engines\Infrastructure\Models\InfraProviderConnection;

/**
 * The single source of truth for INFRA888 operations.
 *
 * Phase 0 found that a new platform action must be registered in NINE separate
 * hardcoded arrays, hand-synced with no test enforcing it, and that drift was
 * already live. INFRA888 declares each operation ONCE here, with full governance
 * metadata; drift tests assert every other registration point matches.
 *
 * PHASE 2A-2 GOVERNANCE POLICY
 * ----------------------------
 * Not every catalog change is approval-gated, and that is deliberate:
 *
 *   UNGOVERNED — creating and editing DRAFTS. A draft is not sellable, has no
 *   subscribers, and cannot affect a customer. Gating it would make the catalog
 *   unusable without improving safety.
 *
 *   GOVERNED (protected) — the moments a change becomes commercially real:
 *   publishing a product or plan, deprecating, retiring, withdrawing, and
 *   planning a subscriber migration. These change what customers can buy, or
 *   what existing customers are on.
 *
 * All infrastructure operations cost ZERO AI credits. That is not a statement
 * about price — see the commercial model.
 */
final class InfrastructureCapabilityRegistry
{
    public const ENGINE = 'infrastructure';

    // Phase 1B
    public const OP_PROVISION_HOSTING = 'provision_hosting';

    // Phase 2A-2 — catalog authoring
    public const OP_PUBLISH_PRODUCT    = 'publish_product';
    public const OP_DEPRECATE_PRODUCT  = 'deprecate_product';
    public const OP_RETIRE_PRODUCT     = 'retire_product';
    public const OP_PUBLISH_PLAN       = 'publish_plan';
    public const OP_WITHDRAW_PLAN      = 'withdraw_plan';
    public const OP_PLAN_MIGRATION     = 'plan_subscriber_migration';

    // Phase 2B — provider control plane.
    // NOTE what is deliberately ABSENT: register_provider and
    // create_credential are NOT here. A draft provider routes nothing and a
    // pending_verification credential is inert, so gating them would add
    // friction without removing risk. revoke_credential is also absent, and
    // that is a security decision - see ProviderCredentialService::revoke().
    public const OP_ACTIVATE_PROVIDER            = 'activate_provider';
    public const OP_ENABLE_PROVIDER_CAPABILITY   = 'enable_provider_capability';
    public const OP_ACTIVATE_CREDENTIAL          = 'activate_provider_credential';
    public const OP_ROTATE_CREDENTIAL            = 'rotate_provider_credential';

    public const RISK_LOW          = 'low';
    public const RISK_MEDIUM       = 'medium';
    public const RISK_HIGH         = 'high';
    public const RISK_IRREVERSIBLE = 'irreversible';

    public static function operations(): array
    {
        return [
            // ── Phase 1B: provisioning ────────────────────────────────────
            self::OP_PROVISION_HOSTING => [
                'capability_key'       => 'infrastructure.provision_hosting',
                'resource_type'        => 'hosting_account',
                'action'               => self::OP_PROVISION_HOSTING,
                'risk'                 => self::RISK_MEDIUM,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'owner',
                'reversible'           => true,
                'provider_mutation'    => true,
                'billing_impact'       => true,
                'audit_category'       => 'infrastructure.provisioning',
                'connector_capability' => InfraProviderConnection::CAPABILITY_HOSTING,
                'connector_contract'   => HostingProviderConnector::class,
                'handler'              => 'provisionHosting',
            ],

            // ── Phase 2A-2: catalog authoring ─────────────────────────────
            // Platform-level commercial changes. No provider mutation, but they
            // determine what every customer can buy.
            self::OP_PUBLISH_PRODUCT => [
                'capability_key'       => 'infrastructure.publish_product',
                'resource_type'        => 'infra_product',
                'action'               => self::OP_PUBLISH_PRODUCT,
                'risk'                 => self::RISK_MEDIUM,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => true,   // can be deprecated again
                'provider_mutation'    => false,
                'billing_impact'       => true,
                'audit_category'       => 'infrastructure.catalog',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'publishProduct',
            ],

            self::OP_DEPRECATE_PRODUCT => [
                'capability_key'       => 'infrastructure.deprecate_product',
                'resource_type'        => 'infra_product',
                'action'               => self::OP_DEPRECATE_PRODUCT,
                'risk'                 => self::RISK_MEDIUM,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => true,
                'provider_mutation'    => false,
                'billing_impact'       => true,
                'audit_category'       => 'infrastructure.catalog',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'deprecateProduct',
            ],

            self::OP_RETIRE_PRODUCT => [
                'capability_key'       => 'infrastructure.retire_product',
                'resource_type'        => 'infra_product',
                'action'               => self::OP_RETIRE_PRODUCT,
                // Terminal in the product state machine. Requires a successor
                // when subscribers exist.
                'risk'                 => self::RISK_IRREVERSIBLE,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => false,
                'provider_mutation'    => false,
                'billing_impact'       => true,
                'audit_category'       => 'infrastructure.catalog',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'retireProduct',
            ],

            self::OP_PUBLISH_PLAN => [
                'capability_key'       => 'infrastructure.publish_plan',
                'resource_type'        => 'infra_plan',
                'action'               => self::OP_PUBLISH_PLAN,
                // Publishing supersedes the previous current version and sets
                // the price new customers pay.
                'risk'                 => self::RISK_HIGH,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => true,   // a superseded version can return
                'provider_mutation'    => false,
                'billing_impact'       => true,
                'audit_category'       => 'infrastructure.catalog',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'publishPlan',
            ],

            self::OP_WITHDRAW_PLAN => [
                'capability_key'       => 'infrastructure.withdraw_plan',
                'resource_type'        => 'infra_plan',
                'action'               => self::OP_WITHDRAW_PLAN,
                'risk'                 => self::RISK_IRREVERSIBLE,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => false,
                'provider_mutation'    => false,
                'billing_impact'       => true,
                'audit_category'       => 'infrastructure.catalog',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'withdrawPlan',
            ],

            self::OP_PLAN_MIGRATION => [
                'capability_key'       => 'infrastructure.plan_subscriber_migration',
                'resource_type'        => 'infra_subscription_migration',
                'action'               => self::OP_PLAN_MIGRATION,
                // Planning is not executing. Still protected, because approving
                // a plan is the decision that authorises moving real customers.
                'risk'                 => self::RISK_HIGH,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => true,
                'provider_mutation'    => false,
                'billing_impact'       => true,
                'audit_category'       => 'infrastructure.catalog',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'planSubscriberMigration',
            ],

            // == Phase 2B: provider control plane ==========================
            // These four are the moments a provider or credential gains the
            // power to act on real infrastructure. Every one is protected AND
            // separation-of-duties (see ApprovalPolicyRegistry) - unlike the
            // catalog operations above, which permit self-confirmation.
            //
            // That difference is deliberate. A mispriced plan costs money and
            // can be corrected. A wrongly-activated credential can delete a
            // customer's DNS. The blast radius is not comparable, so the
            // governance is not either.
            self::OP_ACTIVATE_PROVIDER => [
                'capability_key'       => 'infrastructure.activate_provider',
                'resource_type'        => 'infra_provider',
                'action'               => self::OP_ACTIVATE_PROVIDER,
                'risk'                 => self::RISK_HIGH,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => true,
                'provider_mutation'    => false,
                'billing_impact'       => false,
                'audit_category'       => 'infrastructure.provider_control_plane',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'activateProvider',
            ],

            self::OP_ENABLE_PROVIDER_CAPABILITY => [
                'capability_key'       => 'infrastructure.enable_provider_capability',
                'resource_type'        => 'infra_provider_capability',
                'action'               => self::OP_ENABLE_PROVIDER_CAPABILITY,
                'risk'                 => self::RISK_HIGH,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => true,
                'provider_mutation'    => false,
                'billing_impact'       => false,
                'audit_category'       => 'infrastructure.provider_control_plane',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'enableProviderCapability',
            ],

            // The single most consequential operation in INFRA888: the moment
            // a secret becomes able to act on customer infrastructure.
            self::OP_ACTIVATE_CREDENTIAL => [
                'capability_key'       => 'infrastructure.activate_provider_credential',
                'resource_type'        => 'infra_provider_credential',
                'action'               => self::OP_ACTIVATE_CREDENTIAL,
                'risk'                 => self::RISK_IRREVERSIBLE,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                // Irreversible in the sense that matters: once a credential
                // has been live, it must be treated as exposed. Revocation
                // stops future use but cannot undo past use.
                'reversible'           => false,
                'provider_mutation'    => false,
                'billing_impact'       => false,
                'audit_category'       => 'infrastructure.provider_control_plane',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'activateProviderCredential',
            ],

            self::OP_ROTATE_CREDENTIAL => [
                'capability_key'       => 'infrastructure.rotate_provider_credential',
                'resource_type'        => 'infra_provider_credential',
                'action'               => self::OP_ROTATE_CREDENTIAL,
                'risk'                 => self::RISK_HIGH,
                'approval_mode'        => 'protected',
                'credit_cost'          => 0,
                'required_role'        => 'platform_admin',
                'reversible'           => true,
                'provider_mutation'    => false,
                'billing_impact'       => false,
                'audit_category'       => 'infrastructure.provider_control_plane',
                'connector_capability' => null,
                'connector_contract'   => null,
                'handler'              => 'rotateProviderCredential',
            ],
        ];
    }

    public static function has(string $operation): bool
    {
        return array_key_exists($operation, self::operations());
    }

    public static function get(string $operation): ?array
    {
        return self::operations()[$operation] ?? null;
    }

    public static function actions(): array
    {
        return array_keys(self::operations());
    }

    public static function capabilityMapRows(): array
    {
        $rows = [];

        foreach (self::operations() as $slug => $meta) {
            $rows[$slug] = [
                'engine'        => self::ENGINE,
                'connector'     => null,
                'action'        => $meta['action'],
                'approval_mode' => $meta['approval_mode'],
                'credit_cost'   => $meta['credit_cost'],
            ];
        }

        return $rows;
    }

    public static function protectedOperations(): array
    {
        return array_keys(array_filter(
            self::operations(),
            fn ($m) => $m['approval_mode'] === 'protected'
        ));
    }

    /** Catalog operations that mutate no provider and no customer resource. */
    public static function catalogOperations(): array
    {
        return array_keys(array_filter(
            self::operations(),
            fn ($m) => $m['audit_category'] === 'infrastructure.catalog'
        ));
    }
}
