<?php

/*
|--------------------------------------------------------------------------
| Namecheap registrar provider
|--------------------------------------------------------------------------
|
| INFRA888's first domain registrar. Credentials NEVER live in this file --
| only the names of the environment variables that carry them.
|
| The outbound IP that Namecheap must whitelist is the droplet's PRIMARY
| address, proven empirically on 2026-07-29 to be 134.209.93.41. It is NOT
| the reserved IP (64.225.81.247): a DigitalOcean reserved IP is an inbound
| address only, and egress still leaves via the primary interface.
|
*/

return [

    // sandbox | production
    'environment' => env('NAMECHEAP_ENVIRONMENT', 'sandbox'),

    'endpoints' => [
        'sandbox'    => 'https://api.sandbox.namecheap.com/xml.response',
        'production' => 'https://api.namecheap.com/xml.response',
    ],

    'credentials' => [
        'sandbox' => [
            'api_user' => env('NAMECHEAP_SANDBOX_API_USER', ''),
            'api_key'  => env('NAMECHEAP_SANDBOX_API_KEY', ''),
            'username' => env('NAMECHEAP_SANDBOX_USERNAME', ''),
        ],
        'production' => [
            'api_user' => env('NAMECHEAP_API_USER', ''),
            'api_key'  => env('NAMECHEAP_API_KEY', ''),
            'username' => env('NAMECHEAP_USERNAME', ''),
        ],
    ],

    // Namecheap requires the calling IP to be whitelisted per API user.
    'client_ip' => env('NAMECHEAP_CLIENT_IP', '134.209.93.41'),

    /*
    | HARD SAFETY GATE.
    | Every billable production operation (register, renew, transfer) checks this
    | flag and refuses when false, regardless of credentials. Sandbox is never
    | gated -- it spends no money.
    */
    'production_purchases_enabled' => env('NAMECHEAP_PRODUCTION_PURCHASES_ENABLED', false),

    // Registration in a single call must never silently retry: it is billable.
    'timeout_seconds'         => (int) env('NAMECHEAP_TIMEOUT', 30),
    'billable_timeout_seconds'=> (int) env('NAMECHEAP_BILLABLE_TIMEOUT', 60),

    /*
    | Retail pricing = registrar cost + markup. Markup is configuration, never
    | invented at runtime. Percentage is applied first, then the flat minimum is
    | enforced, so cheap TLDs still clear our cost of service.
    */
    'pricing' => [
        'markup_percent'      => (float) env('DOMAIN_MARKUP_PERCENT', 30.0),
        'markup_minimum_usd'  => (float) env('DOMAIN_MARKUP_MIN_USD', 4.00),
        'quote_ttl_seconds'   => (int) env('DOMAIN_QUOTE_TTL', 900),
        // We do NOT convert currency. If Namecheap quotes anything but USD the
        // quote is rejected and surfaced for a human decision.
        'accepted_currencies' => ['USD'],
    ],

    /*
    | SANDBOX-ONLY test registrant.
    |
    | Deliberately fake but syntactically valid, so Namecheap's sandbox accepts
    | it while it identifies no real person. It is NEVER reachable in production:
    | DomainContactResolver refuses to return it unless the environment is
    | sandbox AND the endpoint is the sandbox endpoint AND the caller explicitly
    | opts in. Production registration must supply real customer contact data.
    |
    | Values are overridable by env so the identity can be changed without a
    | code edit, but the defaults are safe to commit precisely because they are
    | fictional.
    */
    'sandbox_test_contact' => [
        'first_name'  => env('NAMECHEAP_SANDBOX_CONTACT_FIRST', 'LevelUp'),
        'last_name'   => env('NAMECHEAP_SANDBOX_CONTACT_LAST', 'Sandbox'),
        'address1'    => env('NAMECHEAP_SANDBOX_CONTACT_ADDRESS1', '123 Test Street'),
        'city'        => env('NAMECHEAP_SANDBOX_CONTACT_CITY', 'New York'),
        'state'       => env('NAMECHEAP_SANDBOX_CONTACT_STATE', 'NY'),
        'postal_code' => env('NAMECHEAP_SANDBOX_CONTACT_POSTAL', '10001'),
        'country'     => env('NAMECHEAP_SANDBOX_CONTACT_COUNTRY', 'US'),
        // Namecheap requires +CC.NNNNNNNNNN. 555-01xx is the reserved
        // fictional-number range, so this can never reach a real subscriber.
        'phone'       => env('NAMECHEAP_SANDBOX_CONTACT_PHONE', '+1.2125550100'),
        'email'       => env('NAMECHEAP_SANDBOX_CONTACT_EMAIL', 'sandbox-domain-test@levelupgrowth.io'),
    ],

    // Default contact used for registrations until per-customer contacts exist.
    'default_contact' => [
        'first_name' => env('NAMECHEAP_CONTACT_FIRST', ''),
        'last_name'  => env('NAMECHEAP_CONTACT_LAST', ''),
        'address1'   => env('NAMECHEAP_CONTACT_ADDRESS1', ''),
        'city'       => env('NAMECHEAP_CONTACT_CITY', ''),
        'state'      => env('NAMECHEAP_CONTACT_STATE', ''),
        'postal_code'=> env('NAMECHEAP_CONTACT_POSTAL', ''),
        'country'    => env('NAMECHEAP_CONTACT_COUNTRY', ''),
        'phone'      => env('NAMECHEAP_CONTACT_PHONE', ''),
        'email'      => env('NAMECHEAP_CONTACT_EMAIL', ''),
    ],
];
