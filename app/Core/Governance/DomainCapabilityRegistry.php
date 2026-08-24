<?php

namespace App\Core\Governance;

use RuntimeException;

/**
 * Domain capability declarations — Phase 0.
 *
 * DECLARATION ONLY. Nothing in the runtime consults this class yet.
 *
 * It deliberately does NOT extend or register into CapabilityMapService: doing
 * so would make `domain.*` resolvable by EngineExecutionService and TaskService
 * and would therefore change runtime behaviour, which Phase 0 forbids. Wiring
 * this registry into the runtime is a later, separately authorised phase.
 *
 * Each declaration records what the capability WILL be governed by, and states
 * honestly which of those controls are enforced today versus merely declared.
 * A field marked 'declared' is a commitment, not a claim about current
 * behaviour — see ENFORCEMENT_STATE below.
 *
 * FAIL-CLOSED (AP-1): an unknown capability key throws. There is no default
 * policy and no permissive fallback.
 *
 * Namespace decision (approved 2026-07-29): capability families stay explicit —
 * `domain.*`, and later `hosting.*`, `dns.*`, `certificate.*`, `mailbox.*`.
 * They are NOT collapsed under a generic `infrastructure.*`.
 */
final class DomainCapabilityRegistry
{
    public const ENGINE = 'domain';

    /** Risk vocabulary — mirrors InfrastructureCapabilityRegistry. */
    public const RISK_READ         = 'read';
    public const RISK_LOW          = 'low';
    public const RISK_MEDIUM       = 'medium';
    public const RISK_HIGH         = 'high';
    public const RISK_IRREVERSIBLE = 'irreversible';

    /** Reversibility of the effect, independent of risk. */
    public const REV_NONE         = 'n/a';           // read-only
    public const REV_REVERSIBLE   = 'reversible';    // can be undone by us
    public const REV_COMPENSABLE  = 'compensable';   // cannot be undone; can be refunded
    public const REV_IRREVERSIBLE = 'irreversible';  // cannot be undone or compensated

    /** Does invoking this move money? */
    public const COST_FREE     = 'free';
    public const COST_METERED  = 'metered';
    public const COST_BILLABLE = 'billable';

    /** Approval requirement. */
    public const APPROVAL_NONE    = 'none';
    public const APPROVAL_CONFIRM = 'confirm';
    public const APPROVAL_REVIEW  = 'review';
    public const APPROVAL_SOD     = 'sod';

    /**
     * Whether the declared control is actually in force today.
     *
     * 'enforced'  — the running code already behaves this way
     * 'declared'  — committed to, NOT yet enforced anywhere in the runtime
     */
    public const ENFORCED = 'enforced';
    public const DECLARED = 'declared';

    /** Fields every declaration must carry. Asserted by the E1 test. */
    public const REQUIRED_FIELDS = [
        'capability_key', 'engine', 'action', 'callers', 'risk', 'reversibility',
        'cost_class', 'approval', 'separation_of_duties', 'entitlement',
        'idempotency', 'emits', 'audit', 'notification', 'memory',
        'timeout_seconds', 'retry', 'provider_verification',
        'agent_execute', 'enforcement',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [

            /* ─────────────────────────── READS ─────────────────────────────
             * No approval, no credits, no task. Reads and writes are NOT
             * treated identically: a read may never create an approval and may
             * never emit a billable event.
             */

            'domain.search' => [
                'capability_key' => 'domain.search',
                'engine' => self::ENGINE, 'action' => 'search',
                'callers' => ['customer', 'agent', 'operator', 'system', 'api'],
                'risk' => self::RISK_READ,
                'reversibility' => self::REV_NONE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'custom_domain',
                // Reads are naturally idempotent; results are cacheable.
                'idempotency' => 'not_required',
                'emits' => [],
                // Sampled, not per-keystroke: auditing every search would add
                // ~10x to audit_logs for no forensic value.
                'audit' => 'sampled',
                'notification' => 'none',
                'memory' => 'search_intent_aggregate',
                'timeout_seconds' => 30,
                'retry' => 'safe',
                'provider_verification' => 'not_applicable',
                'agent_execute' => true,
                'enforcement' => [
                    'entitlement' => self::DECLARED,   // no plan gate on the route today
                    'audit'       => self::DECLARED,   // writes infra_events only
                    'memory'      => self::DECLARED,
                ],
            ],

            'domain.price' => [
                'capability_key' => 'domain.price',
                'engine' => self::ENGINE, 'action' => 'price',
                'callers' => ['customer', 'agent', 'operator', 'system', 'api'],
                'risk' => self::RISK_READ,
                'reversibility' => self::REV_NONE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'custom_domain',
                'idempotency' => 'not_required',
                'emits' => [],
                'audit' => 'sampled',
                'notification' => 'none',
                'memory' => 'none',
                'timeout_seconds' => 30,
                'retry' => 'safe',
                'provider_verification' => 'not_applicable',
                'agent_execute' => true,
                'enforcement' => ['entitlement' => self::DECLARED, 'audit' => self::DECLARED],
            ],

            'domain.list' => [
                'capability_key' => 'domain.list',
                'engine' => self::ENGINE, 'action' => 'list',
                'callers' => ['customer', 'agent', 'operator'],
                'risk' => self::RISK_READ,
                'reversibility' => self::REV_NONE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'none',
                'idempotency' => 'not_required',
                'emits' => [],
                'audit' => 'none',
                'notification' => 'none',
                'memory' => 'none',
                'timeout_seconds' => 15,
                'retry' => 'safe',
                'provider_verification' => 'not_applicable',
                'agent_execute' => true,
                // Tenancy IS enforced today (forWorkspace scope + tests).
                'enforcement' => ['tenancy' => self::ENFORCED],
            ],

            'domain.view' => [
                'capability_key' => 'domain.view',
                'engine' => self::ENGINE, 'action' => 'view',
                'callers' => ['customer', 'agent', 'operator'],
                'risk' => self::RISK_READ,
                'reversibility' => self::REV_NONE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'none',
                'idempotency' => 'not_required',
                'emits' => [],
                'audit' => 'none',
                'notification' => 'none',
                'memory' => 'none',
                'timeout_seconds' => 15,
                'retry' => 'safe',
                'provider_verification' => 'not_applicable',
                'agent_execute' => true,
                'enforcement' => ['tenancy' => self::ENFORCED],
            ],

            'domain.transfer.status' => [
                'capability_key' => 'domain.transfer.status',
                'engine' => self::ENGINE, 'action' => 'transfer.status',
                'callers' => ['customer', 'agent', 'operator', 'system'],
                'risk' => self::RISK_READ,
                'reversibility' => self::REV_NONE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'none',
                'idempotency' => 'not_required',
                'emits' => [],
                'audit' => 'none',
                'notification' => 'none',
                'memory' => 'none',
                'timeout_seconds' => 30,
                'retry' => 'safe',
                'provider_verification' => 'not_applicable',
                'agent_execute' => true,
                // Not reachable from any route today — declared for completeness.
                'enforcement' => ['route' => self::DECLARED],
            ],

            /* ───────────── WRITES — reversible, non-billable ───────────── */

            'domain.sync' => [
                'capability_key' => 'domain.sync',
                'engine' => self::ENGINE, 'action' => 'sync',
                'callers' => ['customer', 'agent', 'operator', 'system'],
                'risk' => self::RISK_LOW,
                'reversibility' => self::REV_REVERSIBLE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'none',
                'idempotency' => 'operation_key',
                'emits' => ['domain.synced'],
                'audit' => 'always',
                'notification' => 'none',
                'memory' => 'infrastructure_health_history',
                'timeout_seconds' => 120,
                'retry' => 'safe',
                'provider_verification' => 'read_back',
                'agent_execute' => true,
                'enforcement' => [
                    'tenancy' => self::ENFORCED,
                    'audit'   => self::DECLARED,  // infra_events only today
                    'emits'   => self::DECLARED,  // no outbox yet
                    'memory'  => self::DECLARED,
                ],
            ],

            'domain.nameservers.update' => [
                'capability_key' => 'domain.nameservers.update',
                'engine' => self::ENGINE, 'action' => 'nameservers.update',
                // Deliberately NOT agent-callable. Risk is measured in customer
                // impact, not in money: wrong nameservers take a customer's
                // website and email offline immediately.
                'callers' => ['customer', 'operator'],
                'risk' => self::RISK_HIGH,
                'reversibility' => self::REV_REVERSIBLE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_CONFIRM,
                'separation_of_duties' => false,
                'entitlement' => 'custom_domain',
                'idempotency' => 'operation_key',
                'emits' => ['domain.nameservers.changed'],
                'audit' => 'always',
                'notification' => 'customer',
                'memory' => 'preferred_dns_provider',
                'timeout_seconds' => 60,
                'retry' => 'safe',
                'provider_verification' => 'read_back_required',
                'agent_execute' => false,
                'enforcement' => [
                    'route'          => self::DECLARED,  // no customer route today
                    'approval'       => self::DECLARED,
                    'read_back'      => self::ENFORCED,  // adapter already verifies
                    'agent_execute'  => self::DECLARED,
                ],
            ],

            'domain.privacy.update' => [
                'capability_key' => 'domain.privacy.update',
                'engine' => self::ENGINE, 'action' => 'privacy.update',
                'callers' => ['customer', 'operator'],
                'risk' => self::RISK_LOW,
                'reversibility' => self::REV_REVERSIBLE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'custom_domain',
                'idempotency' => 'operation_key',
                'emits' => ['domain.privacy.changed'],
                'audit' => 'always',
                'notification' => 'none',
                'memory' => 'privacy_preference',
                'timeout_seconds' => 60,
                'retry' => 'safe',
                'provider_verification' => 'read_back',
                'agent_execute' => false,
                'enforcement' => ['route' => self::DECLARED, 'audit' => self::DECLARED],
            ],

            'domain.autorenew.update' => [
                'capability_key' => 'domain.autorenew.update',
                'engine' => self::ENGINE, 'action' => 'autorenew.update',
                'callers' => ['customer', 'agent', 'operator'],
                'risk' => self::RISK_MEDIUM,
                'reversibility' => self::REV_REVERSIBLE,
                'cost_class' => self::COST_FREE,
                'approval' => self::APPROVAL_NONE,
                'separation_of_duties' => false,
                'entitlement' => 'none',
                'idempotency' => 'operation_key',
                'emits' => ['domain.autorenew.changed'],
                'audit' => 'always',
                'notification' => 'customer_on_enable',
                'memory' => 'renewal_preference',
                'timeout_seconds' => 60,
                'retry' => 'safe',
                // The registrar returns Status="OK" for a change it does not
                // apply (observed 2026-07-29). Read-back is mandatory, and an
                // unconfirmed write must report 'accepted', never 'verified'.
                'provider_verification' => 'read_back_required',
                'agent_execute' => true,
                'enforcement' => [
                    'tenancy'   => self::ENFORCED,
                    'read_back' => self::ENFORCED,
                    'audit'     => self::DECLARED,
                    'emits'     => self::DECLARED,
                ],
            ],

            /* ───────────────── BILLABLE — money leaves ───────────────── */

            'domain.register' => [
                'capability_key' => 'domain.register',
                'engine' => self::ENGINE, 'action' => 'register',
                'callers' => ['customer', 'operator'],
                'risk' => self::RISK_IRREVERSIBLE,
                'reversibility' => self::REV_IRREVERSIBLE,
                'cost_class' => self::COST_BILLABLE,
                // Waived by a completed payment: the paid order IS the approval.
                'approval' => self::APPROVAL_REVIEW,
                'separation_of_duties' => false,
                'entitlement' => 'custom_domain',
                'idempotency' => 'lvl-order-{order}-item-{item}',
                'emits' => [
                    'domain.registration.requested',
                    'domain.registered',
                    'domain.registration.failed',
                    'domain.refund.required',
                ],
                'audit' => 'always',
                'notification' => 'customer_and_operator',
                'memory' => 'purchase_pattern',
                'timeout_seconds' => 60,
                // An ambiguous failure may already have charged. Never auto-retry.
                'retry' => 'no_auto_retry_on_ambiguous',
                'provider_verification' => 'ownership_precheck_required',
                // An agent may PROPOSE a registration; it may never execute one.
                // Never envelope-eligible: registration creates a new obligation.
                'agent_execute' => false,
                'enforcement' => [
                    'idempotency'    => self::ENFORCED,  // five layers, crash-proven
                    'no_auto_retry'  => self::ENFORCED,
                    'tenancy'        => self::ENFORCED,
                    'ownership_check'=> self::ENFORCED,
                    'approval'       => self::DECLARED,
                    'audit'          => self::DECLARED,
                    'emits'          => self::DECLARED,
                    'notification'   => self::DECLARED,
                    'memory'         => self::DECLARED,
                    'agent_execute'  => self::DECLARED,
                ],
            ],

            'domain.renew' => [
                'capability_key' => 'domain.renew',
                'engine' => self::ENGINE, 'action' => 'renew',
                'callers' => ['customer', 'operator', 'system', 'agent'],
                'risk' => self::RISK_HIGH,
                'reversibility' => self::REV_IRREVERSIBLE,
                'cost_class' => self::COST_BILLABLE,
                'approval' => self::APPROVAL_REVIEW,
                'separation_of_duties' => false,
                'entitlement' => 'custom_domain',
                'idempotency' => 'renew-{domain}-{term}',
                'emits' => [
                    'domain.renewal.requested', 'domain.renewed',
                    'domain.renewal.failed', 'domain.expiring',
                ],
                'audit' => 'always',
                'notification' => 'customer_and_operator',
                'memory' => 'renewal_behaviour',
                'timeout_seconds' => 60,
                'retry' => 'no_auto_retry_on_ambiguous',
                'provider_verification' => 'expiry_advanced_required',
                // The ONLY billable capability eligible for a budget envelope:
                // renewal preserves an asset the customer already owns.
                'agent_execute' => 'budget_envelope_only',
                'enforcement' => [
                    'route'          => self::DECLARED,  // not built
                    'approval'       => self::DECLARED,
                    'budget_envelope'=> self::DECLARED,
                    'audit'          => self::DECLARED,
                ],
            ],

            'domain.transfer.request' => [
                'capability_key' => 'domain.transfer.request',
                'engine' => self::ENGINE, 'action' => 'transfer.request',
                'callers' => ['customer', 'operator'],
                'risk' => self::RISK_IRREVERSIBLE,
                'reversibility' => self::REV_IRREVERSIBLE,
                'cost_class' => self::COST_BILLABLE,
                // Transfers are the standard vector for domain theft. The
                // requester must never be the approver.
                'approval' => self::APPROVAL_SOD,
                'separation_of_duties' => true,
                'entitlement' => 'custom_domain',
                'idempotency' => 'transfer-{domain}',
                'emits' => ['domain.transfer.requested', 'domain.transfer.completed', 'domain.transfer.failed'],
                'audit' => 'always',
                'notification' => 'customer_and_operator',
                'memory' => 'none',
                'timeout_seconds' => 60,
                'retry' => 'never',
                'provider_verification' => 'transfer_status_poll',
                // Never agent-executable under any envelope.
                'agent_execute' => false,
                'enforcement' => [
                    'route'    => self::DECLARED,
                    'sod'      => self::DECLARED,
                    'approval' => self::DECLARED,
                ],
            ],
        ];
    }

    /** Fail-closed: an unknown capability key throws. */
    public static function forKey(string $key): array
    {
        $all = self::all();

        if (! isset($all[$key])) {
            throw new RuntimeException(
                "DENIED: '{$key}' is not a declared domain capability. "
                . 'Per AP-1 an unlisted capability is denied; declare it in '
                . self::class . ' before use.'
            );
        }

        return $all[$key];
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** Capabilities that move money — the set agents may not execute freely. */
    public static function billableKeys(): array
    {
        return array_keys(array_filter(
            self::all(),
            static fn (array $d): bool => $d['cost_class'] === self::COST_BILLABLE
        ));
    }

    /** Every control declared but not yet in force, for reporting. */
    public static function declaredNotEnforced(): array
    {
        $out = [];

        foreach (self::all() as $key => $decl) {
            foreach (($decl['enforcement'] ?? []) as $control => $state) {
                if ($state === self::DECLARED) {
                    $out[] = $key . ' :: ' . $control;
                }
            }
        }

        sort($out);

        return $out;
    }
}
