<?php

namespace App\Core\PlatformEvents;

use App\Core\PlatformEvents\Subscribers\AuditSubscriber;
use RuntimeException;

/**
 * THE central subscriber registry.
 *
 * Every subscriber is declared here and nowhere else. A producer never learns
 * which subscribers exist — its responsibility ends at recording the typed
 * event — and no module may register a subscriber of its own. An architecture
 * test enforces both.
 *
 * A subscriber is only ACTIVE when its declaration exists here AND its feature
 * flag is on. Both, always. A declaration alone changes nothing.
 */
final class SubscriberRegistry
{
    /*
    | ── ELIGIBILITY IS NOT EXECUTION (Phase 1C.1) ──
    |
    | There used to be one notion, active() = declared AND flag on, and fan-out
    | used it to decide whether to create a delivery row. That conflated two
    | questions and cost us Phase 1C event #1: the audit execution flag was off at
    | fan-out time, so no delivery row was created, and because fan-out still
    | stamped fanned_out_at the event became permanently undeliverable.
    |
    |   eligible()   — may this subscriber acquire a delivery OBLIGATION?
    |                  Governance only: declaration, activation, disable, retire.
    |                  Never consults a feature flag or worker state.
    |
    |   executable() — may this subscriber's handler RUN right now?
    |                  Operational: the per-subscriber execution flag.
    |
    | Fan-out uses eligible(). The delivery worker uses executable().
    |
    | FOUR states, four values — never one boolean standing in for all of them.
    */

    /** Eligible for new obligations, and execution permitted. */
    public const STATE_ACTIVE = 'active';

    /** Eligible for new obligations; handler paused. Deliveries accrue as pending. */
    public const STATE_EXECUTION_PAUSED = 'execution_paused';

    /** Events after the disable timestamp get no delivery; existing rows preserved. */
    public const STATE_DISABLED_FOR_FUTURE = 'disabled_for_future_eligibility';

    /** No new delivery rows ever; historical evidence immutable. */
    public const STATE_RETIRED = 'retired';

    /** @return array<string, SubscriberDeclaration> keyed by subscriber key */
    public static function all(): array
    {
        return [
            AuditSubscriber::KEY => new SubscriberDeclaration(
                key: AuditSubscriber::KEY,
                version: AuditSubscriber::VERSION,
                accepts: [
                    'domain.order.created' => [1],
                    // Phase 1E. Audit is the one subscriber that must see a money
                    // fact: an audit trail with orders but no payments cannot
                    // answer the first question anyone asks.
                    'domain.order.paid' => [1],
                ],
                // Prospective delivery boundary. Events recorded BEFORE this
                // instant are never automatically delivered — only an explicit,
                // approved replay can reach them.
                activatedAt: '2026-07-29 20:00:00',
                maxAttempts: 5,
                backoff: [30, 120, 600, 3600],
                // Audit is the one subscriber that legitimately sees everything:
                // an audit trail with gaps is not an audit trail.
                sensitivityAllowance: [
                    PlatformEvent::SENSITIVITY_PUBLIC,
                    PlatformEvent::SENSITIVITY_INTERNAL,
                    PlatformEvent::SENSITIVITY_RESTRICTED,
                ],
                tenancy: SubscriberDeclaration::TENANCY_WORKSPACE_SCOPED,
                unsupportedSchemaPolicy: SubscriberDeclaration::UNSUPPORTED_SCHEMA_SKIP,
                emitsCustomerFacingSideEffects: false,
                description: 'Writes one platform audit record per event into audit_logs.',
            ),
        ];
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** Fail-closed: an unknown subscriber key throws. */
    public static function declaration(string $key): SubscriberDeclaration
    {
        $all = self::all();

        if (! isset($all[$key])) {
            throw new RuntimeException(
                "DENIED: '{$key}' is not a declared subscriber. Declare it in " . self::class . ' before use.'
            );
        }

        return $all[$key];
    }

    /**
     * Subscribers that may acquire NEW delivery obligations.
     *
     * Consults governance only. It does NOT consult platform_events.subscribers.*
     * (execution) nor platform_events.fanout.enabled (whether fan-out runs at all,
     * which EventFanOut::run() checks for itself). Adding either check back here
     * would reintroduce the Phase 1C event #1 defect.
     *
     * @return array<string, SubscriberDeclaration>
     */
    public static function eligible(): array
    {
        return array_filter(
            self::all(),
            static fn (SubscriberDeclaration $d): bool => $d->acquiresNewObligations()
        );
    }

    /**
     * Subscribers whose handler may run right now: eligible AND execution-enabled.
     *
     * @return array<string, SubscriberDeclaration>
     */
    public static function executable(): array
    {
        $flags = (array) config('platform_events.subscribers', []);

        return array_filter(
            self::eligible(),
            static fn (SubscriberDeclaration $d): bool => ($flags[$d->key] ?? false) === true
        );
    }

    /** Whether this subscriber's handler may currently execute. */
    public static function executionEnabled(string $key): bool
    {
        $flags = (array) config('platform_events.subscribers', []);

        return ($flags[$key] ?? false) === true;
    }

    /**
     * The one explicit state of a declared subscriber. Four meanings, four values —
     * never one boolean standing in for all of them.
     */
    public static function state(string $key): string
    {
        $decl = self::declaration($key);

        if ($decl->isRetired()) {
            return self::STATE_RETIRED;
        }

        if ($decl->disabledForFutureEligibilityAt !== null) {
            return self::STATE_DISABLED_FOR_FUTURE;
        }

        return self::executionEnabled($key)
            ? self::STATE_ACTIVE
            : self::STATE_EXECUTION_PAUSED;
    }

    /** @return array<string,string> subscriber key => state */
    public static function states(): array
    {
        $out = [];

        foreach (array_keys(self::all()) as $key) {
            $out[$key] = self::state($key);
        }

        return $out;
    }

    /**
     * The concrete handler for a declared subscriber, resolved from configuration.
     *
     * Configuration rather than a hardcoded match, so that
     * `platform-events:verify` checks the same map the worker actually calls. A
     * hardcoded match cannot be verified against anything.
     */
    public static function handler(string $key): object
    {
        return self::handlerCallable($key)[0];
    }

    /**
     * [instance, method] for a declared subscriber. Fail-closed at every step.
     *
     * @return array{0:object,1:string}
     */
    public static function handlerCallable(string $key): array
    {
        // An undeclared key never reaches configuration.
        self::declaration($key);

        $map = (array) config('platform_events.subscriber_handlers', []);

        if (! isset($map[$key])) {
            throw new RuntimeException(
                "DENIED: no handler is configured for subscriber '{$key}'. "
                . 'Add it to config/platform_events.php subscriber_handlers.'
            );
        }

        [$class, $method] = array_pad((array) $map[$key], 2, null);

        if (! is_string($class) || ! class_exists($class)) {
            throw new RuntimeException(
                "Subscriber '{$key}' names handler class '" . (is_string($class) ? $class : gettype($class))
                . "', which does not exist."
            );
        }

        if (! is_string($method) || ! method_exists($class, $method)) {
            throw new RuntimeException(
                "Subscriber '{$key}' handler {$class}::" . (is_string($method) ? $method : gettype($method))
                . '() does not exist.'
            );
        }

        return [app($class), $method];
    }
}
