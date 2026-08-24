<?php

namespace App\Engines\Infrastructure\Email\Support;

use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailCatchAllState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailRoutingRuleState;

/**
 * INFRA888 · E2 — where each capability moves its subject, and when.
 *
 * One declaration per capability of four things:
 *
 *   in_flight  entered BEFORE the provider is called, so a crash mid-call
 *              leaves a state that says "something was being done"
 *   success    entered ONLY on a verified read-back
 *   failure    entered on a permanent provider refusal
 *   ambiguous  entered when we do not know whether the mutation happened
 *
 * WHY SOME CAPABILITIES HAVE NO AMBIGUOUS STATE, AND THAT IS CORRECT
 *
 * There are two genuinely different kinds of uncertainty, and collapsing them
 * would either lose information or lie:
 *
 *   EXISTENCE uncertainty — a create or a delete timed out. We do not know
 *   whether the object is there. The SUBJECT is in doubt, so the subject enters
 *   `reconciling` and a read-back settles it. This is the case that causes
 *   duplicate provisioning, and it is the reason the state exists.
 *
 *   ATTRIBUTE uncertainty — a suspend, restore or quota change timed out. The
 *   object definitely exists; only one of its fields is unknown. Moving the
 *   subject to `reconciling` here would say "we are not sure this mailbox is
 *   real", which is false and would show a customer a scarier status than the
 *   truth. The uncertainty lives on the OPERATION (compensation_pending) and
 *   the subject keeps its last confirmed state until a read-back updates it.
 *
 * NO ENTRY MAY LIST A TRANSITION ITS STATE MACHINE FORBIDS. A test walks every
 * plan and asserts the machine permits each declared edge, so a plan cannot
 * quietly describe an impossible flow.
 */
final class EmailFlightPlan
{
    /**
     * @return array<string,array{in_flight:?string,success:?string,failure:?string,ambiguous:?string,binds:bool}>
     */
    public static function all(): array
    {
        return [
            // ── mailbox ──────────────────────────────────────────────────────
            Registry::MAILBOX_CREATE => [
                'in_flight' => EmailMailboxState::PROVISIONING,
                'success'   => EmailMailboxState::ACTIVE,
                // INFRA888 · E7. Where the provider invites the owner to set
                // their own password, a successful create produces a mailbox
                // that EXISTS but cannot yet be used. The engine prefers this
                // state when the provider says so, and falls back to `success`
                // for a provider that activates immediately.
                'success_pending' => EmailMailboxState::AWAITING_ACTIVATION,
                'failure'   => EmailMailboxState::PROVISIONING_FAILED,
                // Existence uncertainty.
                'ambiguous' => EmailMailboxState::RECONCILING,
                'binds'     => true,
            ],

            Registry::MAILBOX_UPDATE => [
                // Attribute change on a live mailbox: no lifecycle movement at
                // all. A mailbox being renamed is not "provisioning".
                'in_flight' => null,
                'success'   => null,
                'failure'   => null,
                'ambiguous' => null,
                'binds'     => false,
            ],

            Registry::MAILBOX_SUSPEND => [
                'in_flight' => null,
                // Only a read-back may say mail has actually stopped.
                'success'   => EmailMailboxState::SUSPENDED,
                'failure'   => null,
                'ambiguous' => null,
                'binds'     => false,
            ],

            Registry::MAILBOX_RESTORE => [
                'in_flight' => null,
                'success'   => EmailMailboxState::ACTIVE,
                'failure'   => null,
                'ambiguous' => null,
                'binds'     => false,
            ],

            Registry::MAILBOX_DELETE => [
                'in_flight' => EmailMailboxState::DELETING,
                'success'   => EmailMailboxState::DELETED,
                'failure'   => null,
                // Existence uncertainty again — and the more dangerous
                // direction, because assuming failure would leave a deleted
                // mailbox showing as active.
                'ambiguous' => EmailMailboxState::RECONCILING,
                'binds'     => false,
            ],

            Registry::PASSWORD_RESET => [
                'in_flight' => null,
                'success'   => null,
                'failure'   => null,
                'ambiguous' => null,
                'binds'     => false,
            ],

            // ── routing rules ────────────────────────────────────────────────
            Registry::ALIAS_CREATE => [
                'in_flight' => EmailRoutingRuleState::PROVISIONING,
                'success'   => EmailRoutingRuleState::ACTIVE,
                'failure'   => EmailRoutingRuleState::PROVISIONING_FAILED,
                'ambiguous' => EmailRoutingRuleState::RECONCILING,
                'binds'     => true,
            ],

            Registry::ALIAS_DELETE => [
                'in_flight' => EmailRoutingRuleState::REMOVING,
                'success'   => EmailRoutingRuleState::REMOVED,
                'failure'   => null,
                'ambiguous' => EmailRoutingRuleState::RECONCILING,
                'binds'     => false,
            ],

            Registry::FORWARDER_CREATE => [
                'in_flight' => EmailRoutingRuleState::PROVISIONING,
                'success'   => EmailRoutingRuleState::ACTIVE,
                'failure'   => EmailRoutingRuleState::PROVISIONING_FAILED,
                'ambiguous' => EmailRoutingRuleState::RECONCILING,
                'binds'     => true,
            ],

            Registry::FORWARDER_DELETE => [
                'in_flight' => EmailRoutingRuleState::REMOVING,
                'success'   => EmailRoutingRuleState::REMOVED,
                'failure'   => null,
                'ambiguous' => EmailRoutingRuleState::RECONCILING,
                'binds'     => false,
            ],

            // ── catch-all ────────────────────────────────────────────────────
            Registry::CATCHALL_CONFIGURE => [
                'in_flight' => EmailCatchAllState::CONFIGURING,
                'success'   => EmailCatchAllState::ENABLED,
                'failure'   => EmailCatchAllState::CONFIGURATION_FAILED,
                'ambiguous' => EmailCatchAllState::RECONCILING,
                'binds'     => true,
            ],

            Registry::CATCHALL_CLEAR => [
                'in_flight' => EmailCatchAllState::DISABLING,
                'success'   => EmailCatchAllState::DISABLED,
                'failure'   => EmailCatchAllState::CONFIGURATION_FAILED,
                'ambiguous' => EmailCatchAllState::RECONCILING,
                'binds'     => false,
            ],

            // ── usage ────────────────────────────────────────────────────────
            Registry::USAGE_SYNC => [
                // Measures a domain; moves nothing.
                'in_flight' => null,
                'success'   => null,
                'failure'   => null,
                'ambiguous' => null,
                'binds'     => false,
            ],
        ];
    }

    public static function for(string $capability): ?array
    {
        return self::all()[$capability] ?? null;
    }

    /** Capabilities that create a provider object we must bind a reference to. */
    public static function binding(): array
    {
        return array_keys(array_filter(self::all(), fn (array $p) => $p['binds']));
    }

    /** Capabilities whose subject enters a reconciling state on ambiguity. */
    public static function reconcilable(): array
    {
        return array_keys(array_filter(self::all(), fn (array $p) => $p['ambiguous'] !== null));
    }
}
