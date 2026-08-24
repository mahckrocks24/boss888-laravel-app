<?php

namespace App\Engines\Infrastructure\Email\Customer;

use App\Engines\Infrastructure\Email\States\EmailCatchAllState;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailRoutingRuleState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;

/**
 * INFRA888 · E4 — internal lifecycle → customer-safe status.
 *
 * ─── WHY THIS MAPPING IS EXPLICIT AND NOT A STRING PASS-THROUGH ─────────────
 *
 * The engine's lifecycle names are operator vocabulary. `reconciling`,
 * `compensation_pending` and `provisioning_failed` are precise and useful to
 * someone who knows the machine — and to a customer they are alarming,
 * meaningless, or both. `reconciling` in particular tells a customer we are
 * unsure whether their mailbox exists, which is true internally and is not a
 * sentence anyone should read about their own email.
 *
 * So every internal state is mapped, by hand, to one of eight customer-safe
 * states. A state added later with no mapping resolves to UNAVAILABLE rather
 * than leaking its internal name — and a test asserts every declared state of
 * every machine has an explicit entry, so the fallback is a safety net rather
 * than the mechanism.
 *
 * ─── THE ONE MAPPING THAT MATTERS MOST ──────────────────────────────────────
 *
 * Both `reconciling` and `provisioning_failed` become ACTION_REQUIRED with the
 * SAME customer wording: we are reviewing it and nothing is needed from them
 * yet. That is deliberate. The difference between "we do not know" and "it
 * failed" changes what an OPERATOR does and changes nothing a customer can act
 * on, and exposing it would invite them to act on a state that is still moving.
 */
final class CustomerStatus
{
    // ── the eight customer-safe states ───────────────────────────────────────
    public const NOT_SET_UP = 'not_set_up';
    public const SETUP_REQUIRED = 'setup_required';
    public const VERIFYING = 'verifying';
    public const PROVISIONING = 'provisioning';
    /**
     * INFRA888 · E7. The mailbox exists and is waiting for its owner to accept
     * an invitation and choose a password. Distinct from PROVISIONING, which
     * says WE are still working — here the next move belongs to a person, and
     * telling them so is the difference between a mailbox that gets used and
     * one that quietly never does.
     */
    public const AWAITING_ACTIVATION = 'awaiting_activation';

    public const ACTIVE = 'active';
    public const ACTION_REQUIRED = 'action_required';
    public const SUSPENDED = 'suspended';
    public const UNAVAILABLE = 'unavailable';

    /** @return array<int,string> */
    public static function all(): array
    {
        return [
            self::NOT_SET_UP, self::SETUP_REQUIRED, self::VERIFYING, self::PROVISIONING,
            self::AWAITING_ACTIVATION, self::ACTIVE, self::ACTION_REQUIRED,
            self::SUSPENDED, self::UNAVAILABLE,
        ];
    }

    /**
     * Customer-facing wording. Never names a provider, a lifecycle or an
     * internal concept.
     *
     * @return array<string,array{label:string,detail:string,tone:string}>
     */
    public static function presentation(): array
    {
        return [
            self::NOT_SET_UP => [
                'label'  => 'Not set up',
                'detail' => 'Business Email has not been set up for this domain yet.',
                'tone'   => 'neutral',
            ],
            self::SETUP_REQUIRED => [
                'label'  => 'Setup required',
                'detail' => 'Add the DNS records below to finish setting up Business Email.',
                'tone'   => 'attention',
            ],
            self::VERIFYING => [
                'label'  => 'Verifying',
                'detail' => 'We are checking your DNS records. This can take a little while after you add them.',
                'tone'   => 'progress',
            ],
            self::PROVISIONING => [
                'label'  => 'Setting up',
                'detail' => 'We are finishing setup. Nothing is needed from you.',
                'tone'   => 'progress',
            ],
            self::AWAITING_ACTIVATION => [
                'label'  => 'Waiting for the owner',
                // Says who must act and what they should look for. It does not
                // imply a password exists here, because none does.
                'detail' => 'We have emailed an invitation. The mailbox will start working once its owner '
                    . 'opens it and chooses a password.',
                'tone'   => 'attention',
            ],
            self::ACTIVE => [
                'label'  => 'Active',
                'detail' => 'Business Email is working.',
                'tone'   => 'good',
            ],
            self::ACTION_REQUIRED => [
                // Deliberately the same wording for "we do not know" and "it
                // failed": neither is something a customer can act on yet, and
                // distinguishing them would invite action on a moving state.
                'label'  => 'Action required',
                'detail' => 'We are reviewing this. No action is needed from you yet — we will be in touch if that changes.',
                'tone'   => 'attention',
            ],
            self::SUSPENDED => [
                'label'  => 'Suspended',
                'detail' => 'Mail is paused. Contact LevelUp Growth support if this is unexpected.',
                'tone'   => 'attention',
            ],
            self::UNAVAILABLE => [
                'label'  => 'Temporarily unavailable',
                'detail' => 'Business Email is temporarily unavailable. Your settings are unchanged.',
                'tone'   => 'neutral',
            ],
        ];
    }

    // ── domain ───────────────────────────────────────────────────────────────

    /** @return array<string,string> internal lifecycle => customer-safe state */
    public static function domainMap(): array
    {
        return [
            EmailDomainState::CONNECTED           => self::SETUP_REQUIRED,
            EmailDomainState::VERIFYING_DNS       => self::VERIFYING,
            EmailDomainState::DNS_VERIFIED        => self::PROVISIONING,
            EmailDomainState::PROVISIONING        => self::PROVISIONING,
            EmailDomainState::ACTIVE              => self::ACTIVE,
            EmailDomainState::SUSPENDED           => self::SUSPENDED,
            // Customer-fixable: their records are missing or wrong.
            EmailDomainState::VERIFICATION_FAILED => self::SETUP_REQUIRED,
            // Operator-fixable. The customer is told we are on it.
            EmailDomainState::PROVISIONING_FAILED => self::ACTION_REQUIRED,
            EmailDomainState::RECONCILING         => self::ACTION_REQUIRED,
            EmailDomainState::TERMINATED          => self::NOT_SET_UP,
        ];
    }

    public static function forDomain(?string $lifecycle): string
    {
        return self::domainMap()[(string) $lifecycle] ?? self::UNAVAILABLE;
    }

    // ── mailbox ──────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    public static function mailboxMap(): array
    {
        return [
            EmailMailboxState::REQUESTED           => self::PROVISIONING,
            EmailMailboxState::PROVISIONING        => self::PROVISIONING,
            EmailMailboxState::AWAITING_ACTIVATION => self::AWAITING_ACTIVATION,
            EmailMailboxState::ACTIVE              => self::ACTIVE,
            EmailMailboxState::SUSPENDED           => self::SUSPENDED,
            EmailMailboxState::RECONCILING         => self::ACTION_REQUIRED,
            EmailMailboxState::PROVISIONING_FAILED => self::ACTION_REQUIRED,
            EmailMailboxState::DELETING            => self::PROVISIONING,
            EmailMailboxState::DELETED             => self::NOT_SET_UP,
        ];
    }

    public static function forMailbox(?string $lifecycle): string
    {
        return self::mailboxMap()[(string) $lifecycle] ?? self::UNAVAILABLE;
    }

    // ── alias / forwarder ────────────────────────────────────────────────────

    /** @return array<string,string> */
    public static function routingMap(): array
    {
        return [
            EmailRoutingRuleState::REQUESTED           => self::PROVISIONING,
            EmailRoutingRuleState::PROVISIONING        => self::PROVISIONING,
            EmailRoutingRuleState::ACTIVE              => self::ACTIVE,
            EmailRoutingRuleState::RECONCILING         => self::ACTION_REQUIRED,
            EmailRoutingRuleState::PROVISIONING_FAILED => self::ACTION_REQUIRED,
            EmailRoutingRuleState::REMOVING            => self::PROVISIONING,
            EmailRoutingRuleState::REMOVED             => self::NOT_SET_UP,
        ];
    }

    public static function forRoutingRule(?string $lifecycle): string
    {
        return self::routingMap()[(string) $lifecycle] ?? self::UNAVAILABLE;
    }

    // ── catch-all ────────────────────────────────────────────────────────────

    /** @return array<string,string> */
    public static function catchAllMap(): array
    {
        return [
            EmailCatchAllState::DISABLED             => self::NOT_SET_UP,
            EmailCatchAllState::CONFIGURING          => self::PROVISIONING,
            EmailCatchAllState::ENABLED              => self::ACTIVE,
            EmailCatchAllState::DISABLING            => self::PROVISIONING,
            EmailCatchAllState::CONFIGURATION_FAILED => self::ACTION_REQUIRED,
            EmailCatchAllState::RECONCILING          => self::ACTION_REQUIRED,
        ];
    }

    public static function forCatchAll(?string $state): string
    {
        return self::catchAllMap()[(string) $state] ?? self::UNAVAILABLE;
    }

    // ── verification (shown alongside domain status) ─────────────────────────

    /** @return array<string,string> */
    public static function verificationMap(): array
    {
        return [
            EmailVerificationState::UNVERIFIED      => 'not_started',
            EmailVerificationState::PENDING_RECORDS => 'awaiting_your_records',
            EmailVerificationState::CHECKING        => 'checking',
            EmailVerificationState::VERIFIED        => 'verified',
            EmailVerificationState::FAILED          => 'not_found',
            EmailVerificationState::DRIFTED         => 'changed',
        ];
    }

    public static function forVerification(?string $state): string
    {
        return self::verificationMap()[(string) $state] ?? 'unknown';
    }

    /**
     * The full customer-safe status object for one entity.
     *
     * @return array{state:string,label:string,detail:string,tone:string}
     */
    public static function describe(string $customerState): array
    {
        $p = self::presentation()[$customerState] ?? self::presentation()[self::UNAVAILABLE];

        return ['state' => $customerState] + $p;
    }

    /** States that mean the customer must do something. */
    public static function needsCustomerAction(): array
    {
        // AWAITING_ACTIVATION is included: somebody has to open an email, and a
        // mailbox nobody claims is a support ticket waiting to happen.
        return [self::SETUP_REQUIRED, self::ACTION_REQUIRED, self::AWAITING_ACTIVATION];
    }
}
