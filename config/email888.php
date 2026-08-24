<?php

/**
 * EMAIL888 — outbound email policy.
 *
 * THE PROBLEM THIS FILE REPLACES
 * Sender identity used to be decided at each call site. One path read
 * MAIL_FROM_ADDRESS, another hardcoded noreply@levelupgrowth.io, a third fell
 * back to a config default when its env var was unset — which is exactly what
 * happened: BUSINESS_EMAIL_FROM_ADDRESS was never set, so onboarding quietly
 * used a default nobody had chosen. Message stream was never selected at all,
 * so marketing and password resets shared one reputation.
 *
 * Here, a caller declares a PURPOSE. The purpose decides the sender and the
 * stream. Call sites no longer get an opinion.
 *
 * NO SECRETS IN THIS FILE.
 */
return [

    /*
     |--------------------------------------------------------------------------
     | Delivery ledger
     |--------------------------------------------------------------------------
     | ON by default, unlike most INFRA888 gates. This one only observes — it
     | writes a row and can never refuse or alter a message — and leaving it off
     | is what allowed 37 sends to disappear unnoticed. The kill switch exists
     | for an emergency, not as the normal state.
     */
    'ledger_enabled' => env('EMAIL888_LEDGER_ENABLED', true),

    /*
     |--------------------------------------------------------------------------
     | Stream enforcement
     |--------------------------------------------------------------------------
     | When on, a message whose purpose is KNOWN gets an explicit Postmark
     | message stream. A message whose purpose is 'unclassified' is left exactly
     | as it is today, so switching this on changes nothing until a call site is
     | deliberately converted. That is what makes the rollout reversible.
     */
    'enforce_streams' => env('EMAIL888_ENFORCE_STREAMS', true),

    /*
     |--------------------------------------------------------------------------
     | Provider stream names
     |--------------------------------------------------------------------------
     | Postmark server 19161739 has exactly these two outbound streams. The
     | left-hand side is ours and provider-neutral; the right-hand side is the
     | vendor's name for it and the only place that name appears.
     */
    'streams' => [
        'transactional' => env('EMAIL888_STREAM_TRANSACTIONAL', 'outbound'),
        'broadcast'     => env('EMAIL888_STREAM_BROADCAST', 'broadcast'),
    ],

    /*
     |--------------------------------------------------------------------------
     | Sender registry
     |--------------------------------------------------------------------------
     | Every address here must be a real, monitored destination on a
     | DKIM-verified domain. Two exist because two are genuinely needed:
     | one the platform speaks from, one a human answers.
     |
     | noreply@ is deliberately absent. It was never a verified sender, nothing
     | monitors it, and a bounce sent to it is a bounce nobody reads.
     */
    'senders' => [
        'platform' => [
            'address' => env('EMAIL888_SENDER_PLATFORM', 'hello@levelupgrowth.io'),
            'name'    => env('EMAIL888_SENDER_PLATFORM_NAME', 'LevelUp Growth'),
        ],
        'support' => [
            'address' => env('EMAIL888_SENDER_SUPPORT', 'support@levelupgrowth.io'),
            'name'    => env('EMAIL888_SENDER_SUPPORT_NAME', 'LevelUp Growth'),
        ],
    ],

    /*
     |--------------------------------------------------------------------------
     | Purpose registry
     |--------------------------------------------------------------------------
     | sender  — key into 'senders' above
     | stream  — 'transactional' or 'broadcast'
     | reply_to— sender key whose address should receive replies, or null
     |
     | A security or account email must never travel on the broadcast stream: a
     | recipient who unsubscribes from marketing would stop receiving password
     | resets. A campaign must never travel on the transactional stream: bulk
     | complaints would poison the reputation that delivers those resets.
     */
    'purposes' => [
        // ── transactional ────────────────────────────────────────────────
        'password_reset'     => ['sender' => 'support',  'stream' => 'transactional', 'reply_to' => 'support'],
        'account_security'   => ['sender' => 'support',  'stream' => 'transactional', 'reply_to' => 'support'],
        'mailbox_onboarding' => ['sender' => 'support',  'stream' => 'transactional', 'reply_to' => 'support'],
        'billing'            => ['sender' => 'support',  'stream' => 'transactional', 'reply_to' => 'support'],
        'notification'       => ['sender' => 'platform', 'stream' => 'transactional', 'reply_to' => 'support'],
        'booking'            => ['sender' => 'platform', 'stream' => 'transactional', 'reply_to' => 'support'],
        // reply_to is deliberately NULL: an intake notification must reply to the
        // person who submitted the form, and the call site sets that. A registry
        // reply-to would silently redirect every answer to support.
        'intake'             => ['sender' => 'platform', 'stream' => 'transactional', 'reply_to' => null],
        'domain_event'       => ['sender' => 'platform', 'stream' => 'transactional', 'reply_to' => 'support'],
        'hosting_event'      => ['sender' => 'platform', 'stream' => 'transactional', 'reply_to' => 'support'],

        // ── broadcast ────────────────────────────────────────────────────
        // Operator probe. Registered so email888:probe cannot send from an
        // unclassified identity, and so its traffic is filterable in the ledger.
        'platform_diagnostic' => ['sender' => 'platform', 'stream' => 'transactional', 'reply_to' => 'support'],

        'campaign'           => ['sender' => 'platform', 'stream' => 'broadcast', 'reply_to' => 'support'],
        'sequence'           => ['sender' => 'platform', 'stream' => 'broadcast', 'reply_to' => 'support'],
        'newsletter'         => ['sender' => 'platform', 'stream' => 'broadcast', 'reply_to' => 'support'],
    ],

    /*
     |--------------------------------------------------------------------------
     | Stale hand-off reaper
     |--------------------------------------------------------------------------
     | A row still 'queued' this long after hand-off never reached the provider.
     | The reaper marks it failed rather than leaving it ambiguous forever.
     */
    'stale_queued_minutes' => (int) env('EMAIL888_STALE_QUEUED_MINUTES', 15),

    /*
     |--------------------------------------------------------------------------
     | Webhook
     |--------------------------------------------------------------------------
     | Postmark cannot sign its webhooks, so authentication is a shared secret
     | carried in the URL path plus optional HTTP basic auth. Empty secret =
     | endpoint refuses everything, which is the correct default before the
     | secret is configured.
     */
    /*
     |--------------------------------------------------------------------------
     | Webhook ingress receipt retention (EM-6)
     |--------------------------------------------------------------------------
     | Receipts are written for REFUSED attempts too, which means anyone able to
     | reach the public endpoint can make this table grow. Two windows, because
     | the two kinds of row are worth different amounts:
     |
     |   accepted — evidence about real mail, and the only record of when the
     |              provider told us anything. Kept for a year.
     |   refused  — mostly someone else's port scan. Useful while diagnosing,
     |              worthless a month later. Kept for thirty days.
     |
     | The newest row of every kind is exempt from both windows: bounding volume
     | must never erase the fact that a category was ever seen at all.
     */
    'receipts' => [
        'accepted_retention_days' => (int) env('EMAIL888_RECEIPT_ACCEPTED_DAYS', 365),
        'refused_retention_days'  => (int) env('EMAIL888_RECEIPT_REFUSED_DAYS', 30),
    ],

    /*
     |--------------------------------------------------------------------------
     | Outbound provider (EM-8)
     |--------------------------------------------------------------------------
     | The single place the platform's outbound vendor is named. The dispatcher,
     | the delivery ledger and the webhook ingress recorder read it through
     | OutboundPolicy::provider() rather than writing a literal, so the core
     | records WHICH provider handled a message without knowing which one it is.
     |
     | Changing this alone does not change how mail is sent — that is the
     | mailer's transport and an adapter. It exists so that swapping providers is
     | a configuration change plus an adapter, not a search-and-replace through
     | code that should never have named a vendor in the first place.
     */
    'provider' => env('EMAIL888_PROVIDER', 'postmark'),

    'webhook' => [
        'secret'         => env('EMAIL888_WEBHOOK_SECRET', ''),
        'basic_user'     => env('EMAIL888_WEBHOOK_BASIC_USER', ''),
        'basic_password' => env('EMAIL888_WEBHOOK_BASIC_PASSWORD', ''),
    ],
];
