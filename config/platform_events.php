<?php

/*
|--------------------------------------------------------------------------
| Platform events — transactional outbox
|--------------------------------------------------------------------------
|
| Phase 1A: outbox production and recoverability only.
|
| There are NO subscribers. Audit, notification, memory and analytics are
| explicitly NOT integrated. An event that reaches the dispatcher today ends
| in the 'no_subscribers' state, which is the truthful outcome.
|
*/

return [

    // Master switch. Off means no event is ever recorded, by any producer.
    'enabled' => env('PLATFORM_EVENTS_ENABLED', false),

    /*
    | PHASE 1C — WORKSPACE GATE.
    |
    | A second, independent restriction on top of the producer flag. Config flags
    | are global; production activation must not be. With this list non-empty,
    | event production is IMPOSSIBLE outside the listed workspaces even when the
    | producer flag is on.
    |
    | Empty list + producer flag on = no workspace may produce. Fail-closed:
    | forgetting to populate the list disables production rather than enabling it
    | everywhere.
    |
    | Comma-separated workspace ids, e.g. PLATFORM_EVENTS_WORKSPACES=1
    */
    'producer_workspace_allowlist' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('PLATFORM_EVENTS_WORKSPACES', ''))
    ))),

    /*
    | Per-producer flags. Every producer is default-OFF and must be enabled
    | deliberately. Disabling a producer must never require rolling back the
    | business record it would have described.
    */
    'producers' => [
        'domain.order.created' => env('PLATFORM_EVENTS_DOMAIN_ORDER_CREATED', false),
        // Phase 1E. Ships OFF: the producer sits inside the money path, so it is
        // enabled deliberately and only after the chain has been verified.
        'domain.order.paid' => env('PLATFORM_EVENTS_DOMAIN_ORDER_PAID', false),
    ],

    /*
    | Phase 1B — fan-out, delivery worker and subscribers.
    |
    | FOUR independent switches. Each defaults to false in committed config, and
    | an architecture test reads THIS FILE (not runtime config, which a sibling
    | test can mutate) to prove it.
    */

    // Creates per-subscriber delivery rows from recorded events.
    'fanout' => [
        'enabled' => env('PLATFORM_EVENTS_FANOUT_ENABLED', false),
        'batch_size' => (int) env('PLATFORM_EVENTS_FANOUT_BATCH', 100),
    ],

    // Executes subscribers against due delivery rows.
    'delivery_worker' => [
        'enabled' => env('PLATFORM_EVENTS_DELIVERY_ENABLED', false),
        'batch_size' => (int) env('PLATFORM_EVENTS_DELIVERY_BATCH', 50),
        // A row left 'processing' beyond this belongs to a crashed worker.
        'stale_claim_minutes' => (int) env('PLATFORM_EVENTS_STALE_CLAIM_MIN', 15),
    ],

    /*
    | Per-subscriber switches. A subscriber is ACTIVE only when it is declared in
    | SubscriberRegistry AND its flag here is true AND fanout.enabled is true.
    | A declaration on its own changes nothing.
    */
    'subscribers' => [
        'platform.audit' => env('PLATFORM_EVENTS_SUBSCRIBER_AUDIT', false),
    ],

    /*
    | PHASE 1D — RESOLVED DECLARATIONS.
    |
    | These two maps are not documentation. Both are loaded at runtime — Outbox
    | refuses to record an event whose class disagrees with `event_classes`, and the
    | delivery worker invokes exactly the callable named in `subscriber_handlers`.
    | `platform-events:verify` then proves by reflection that every entry resolves.
    |
    | A configuration string that nothing ever resolves is worse than no
    | configuration: it reads like a guarantee and enforces nothing.
    */

    // event type => the PlatformEvent subclass that produces it.
    'event_classes' => [
        'domain.order.created' => \App\Core\PlatformEvents\Types\DomainOrderCreated::class,
        'domain.order.paid' => \App\Core\PlatformEvents\Types\DomainOrderPaid::class,
    ],

    // subscriber key => [handler class, method]. The worker calls THIS method.
    'subscriber_handlers' => [
        'platform.audit' => [\App\Core\PlatformEvents\Subscribers\AuditSubscriber::class, 'handle'],
    ],

    'dispatcher' => [
        // Rows claimed per pass. Small: this is a correctness milestone.
        'batch_size' => (int) env('PLATFORM_EVENTS_BATCH', 50),

        'max_attempts' => (int) env('PLATFORM_EVENTS_MAX_ATTEMPTS', 5),

        // Backoff per attempt, seconds. Beyond the last entry the final value
        // repeats until max_attempts is reached.
        'backoff' => [30, 120, 600, 3600],

        // A row stuck in 'dispatching' for longer than this is assumed to
        // belong to a crashed worker and is returned to 'pending'.
        'stale_lock_minutes' => (int) env('PLATFORM_EVENTS_STALE_LOCK_MIN', 15),

        'queue' => env('PLATFORM_EVENTS_QUEUE', 'tasks-low'),
    ],

    /*
    | PHASE 1C — SCHEDULING AND MONITORING.
    |
    | The processing command runs every 5 minutes, so thresholds are expressed in
    | multiples of that interval. Under 5 minutes of lag is normal, not an alert.
    */
    'processing' => [
        // Minutes between scheduled runs. Thresholds below derive from this.
        'interval_minutes' => (int) env('PLATFORM_EVENTS_INTERVAL_MIN', 5),
        // Overlap lock. Longer than a run should ever take, shorter than the gap
        // between runs plus a margin, so a crashed run cannot block forever.
        'lock_seconds' => (int) env('PLATFORM_EVENTS_LOCK_SECONDS', 240),
    ],

    'monitoring' => [
        // Age of the oldest un-fanned event / pending delivery, in minutes.
        'warning_age_minutes'  => (int) env('PLATFORM_EVENTS_WARN_AGE_MIN', 15),   // 3 intervals
        'critical_age_minutes' => (int) env('PLATFORM_EVENTS_CRIT_AGE_MIN', 30),   // 6 intervals
        // Any permanently failed delivery is at least a warning; this many is critical.
        'critical_permanent_failures' => (int) env('PLATFORM_EVENTS_CRIT_PERM_FAIL', 3),
        // Stale processing claims older than the reaper threshold.
        'critical_stale_claims' => (int) env('PLATFORM_EVENTS_CRIT_STALE', 1),
    ],

    /*
    | Payload field names that must never appear in an event, at any depth.
    | The Outbox service refuses to record a payload containing one of these,
    | and an architecture test asserts the list is enforced.
    |
    | This is a name-based guard, not a value scanner: it is cheap, total, and
    | catches the realistic mistake (someone passing a whole request body or a
    | provider response through).
    */
    'blocked_payload_keys' => [
        'password', 'secret', 'token', 'api_key', 'apikey', 'authorization',
        'auth_code', 'epp', 'epp_code', 'credential', 'credentials',
        'private_key', 'client_secret', 'stripe_secret', 'card', 'card_number',
        'cvv', 'cvc', 'pan', 'access_token', 'refresh_token', 'bearer',
        'session', 'cookie', 'raw_request', 'raw_response', 'request_body',
    ],
];
