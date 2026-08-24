<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domain fulfilment
    |--------------------------------------------------------------------------
    |
    | PHASE 1E.1 — FULFILMENT CONTAINMENT.
    |
    | Payment truth and fulfilment execution are separate concerns. Before this
    | flag existed, a successful payment dispatched RegisterDomainJob for every
    | item unconditionally, which made a controlled paid-webhook validation
    | impossible: it would have queued a real Namecheap registration.
    |
    | The CODE default is true, preserving the existing intended production
    | behaviour. The DEPLOYED value is currently false, set deliberately in .env
    | for the controlled Phase 1E validation.
    |
    | When disabled: payment still commits, domain.order.paid is still recorded,
    | and the order stays `paid` (NOT `provisioning`) with a durable
    | fulfilment_suppressed marker. Nothing is queued, no registrar is contacted
    | and no customer is notified. Fulfilment is deferred, never faked.
    |
    | Disabling does NOT mark registration complete, and re-enabling does NOT
    | replay a backlog — there is no automatic catch-up in this milestone.
    */
    'fulfilment' => [
        'enabled' => env('DOMAINS_FULFILMENT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time fixture webhook replay  (PHASE 1E.2 — TEMPORARY)
    |--------------------------------------------------------------------------
    |
    | A NARROW, FAIL-CLOSED path that lets exactly ONE authorised test-mode Stripe
    | event pass the real signature verifier using a secret that is NOT the live
    | webhook secret.
    |
    | Every value below ships blank/false. The verifier requires ALL of them to be
    | present AND to match exactly; a single blank, mismatch or missing condition
    | means the fixture path does not exist. There is no wildcard and no prefix
    | match. This is not a second webhook secret: it authenticates one event id,
    | for one order, in one workspace, from loopback only, while fulfilment is off.
    |
    | RETIRE IT after the validation: set enabled=false and blank every value.
    */
    'fixture_replay' => [
        'enabled' => env('DOMAINS_FIXTURE_REPLAY_ENABLED', false),
        'secret' => env('DOMAINS_FIXTURE_REPLAY_SECRET', ''),
        'event_id' => env('DOMAINS_FIXTURE_EVENT_ID', ''),
        'session_id' => env('DOMAINS_FIXTURE_SESSION_ID', ''),
        'payment_intent_id' => env('DOMAINS_FIXTURE_PAYMENT_INTENT_ID', ''),
        'order_id' => (int) env('DOMAINS_FIXTURE_ORDER_ID', 0),
        'workspace_id' => (int) env('DOMAINS_FIXTURE_WORKSPACE_ID', 0),
        // Only this event type is ever accepted through the fixture path.
        'event_type' => 'checkout.session.completed',
        // The approved environments. Production behaviour is unchanged; this is
        // explicit so the control can be exercised by the test suite instead of
        // being the one condition nothing can prove.
        'environments' => ['staging', 'production'],
    ],

];
