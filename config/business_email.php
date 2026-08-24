<?php

/**
 * INFRA888 · E3 — Business Email Admin configuration.
 *
 * WHY THE GATE DEFAULTS TO OFF ON EVERY ENVIRONMENT, INCLUDING STAGING
 *
 * The obvious gate is `app()->environment('production')`. On this install that
 * would be wrong and dangerously so: APP_ENV is `staging`, and this same
 * Laravel serves levelupgrowth.io and live customer domains. An environment
 * check would leave the Business Email admin area fully switched on for real
 * operators looking at real customer workspaces, backed by nothing but a fake
 * provider.
 *
 * So the gate is not an environment test. It is an explicit opt-in that is off
 * until someone sets it, everywhere, and a test proves the default. E3 ships a
 * complete admin surface that is inert until E5 gives it a real provider.
 *
 * NO SECRETS IN THIS FILE. The fake provider needs none by construction, and a
 * real one will read its credential from infra_provider_credentials.
 */
return [

    /*
     |--------------------------------------------------------------------------
     | Admin surface gate
     |--------------------------------------------------------------------------
     | Off by default. When off: the navigation entries are hidden by
     | AdminRegistry, every admin API route returns a typed 404-equivalent, and
     | no governed action can be initiated. Nothing half-operational is exposed.
     */
    'admin_enabled' => env('BUSINESS_EMAIL_ADMIN_ENABLED', false),

    /*
     |--------------------------------------------------------------------------
     | Customer portal gate (E4)
     |--------------------------------------------------------------------------
     | Its own flag, and additionally requires the admin gate above.
     |
     | An operator console backed by a fake provider is defensible — the
     | operator knows what they are looking at. A CUSTOMER portal backed by a
     | fake provider is not defensible under any framing: it would show a
     | paying customer mailboxes that do not exist. And a customer surface for
     | a feature no operator can see or repair produces support requests
     | nobody can answer, which is why it requires both.
     |
     | While this is off, customers see exactly what they see today: the
     | HOSTING -> Email Accounts item and its existing coming-soon page. E4
     | changes nothing observable until this flag is set.
     */
    'customer_enabled' => env('BUSINESS_EMAIL_CUSTOMER_ENABLED', false),

    /*
     |--------------------------------------------------------------------------
     | LevelUp allowances (E4)
     |--------------------------------------------------------------------------
     | What the CUSTOMER bought from LevelUp Growth — never what the underlying
     | service happens to permit. If the provider allowed 500 mailboxes and the
     | product sells 10, the customer's limit is 10; reading it from provider
     | capacity would make a customer's allowance change when we switched
     | vendor, which is the coupling the whole design exists to prevent.
     |
     | Conservative defaults, no plan name, no price: commercial Business Email
     | plans are not approved yet (masterplan section 14, Q6). `null` means no
     | ceiling. Per-tenant capacity belongs in tenant entitlement later, not
     | hard-coded into shared code.
     */
    'customer_entitlements' => [
        'access'     => env('BUSINESS_EMAIL_CUSTOMER_ACCESS', true),
        'domains'    => env('BUSINESS_EMAIL_LIMIT_DOMAINS', 1),
        'mailboxes'  => env('BUSINESS_EMAIL_LIMIT_MAILBOXES', 10),
        'aliases'    => env('BUSINESS_EMAIL_LIMIT_ALIASES', 25),
        'forwarders' => env('BUSINESS_EMAIL_LIMIT_FORWARDERS', 25),
        'storage_mb' => env('BUSINESS_EMAIL_LIMIT_STORAGE_MB', 51200),
        // Off by default: a catch-all is a well-known spam magnet, so it is
        // opt-in per account rather than a default customers discover.
        'catchall'   => env('BUSINESS_EMAIL_ALLOW_CATCHALL', false),
    ],

    /*
     |--------------------------------------------------------------------------
     | Elevated operators
     |--------------------------------------------------------------------------
     | The three narrowest capabilities — provider credentials, destructive
     | mailbox actions and destructive domain actions — are granted ONLY to user
     | ids listed here. The default is an empty list, which means nobody, which
     | means those actions are refused for every operator until an owner
     | deliberately names someone.
     |
     | This is not an RBAC system. The platform already rejected adding a fifth
     | authority vocabulary (GD-007/AZ-1), and PermissionRegistry is the
     | platform's own answer. Until Business Email is registered there in E4,
     | an explicit allowlist that fails closed is the honest interim: it cannot
     | accidentally grant, and it is one line to read.
     */
    'elevated_admin_user_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('BUSINESS_EMAIL_ELEVATED_ADMINS', ''))
    ))),

    /*
     |--------------------------------------------------------------------------
     | Controlled scenario selection (E3-K)
     |--------------------------------------------------------------------------
     | Lets an E3 validation run drive the fake provider through its failure
     | modes. Off by default and additionally refused unless the admin gate is
     | on, so it cannot be reached on an install where Business Email admin is
     | switched off — which is every install today.
     |
     | Scenario names are matched against a fixed allowlist in the controller.
     | A class name is NEVER accepted from request input.
     */
    /*
    |--------------------------------------------------------------------------
    | Provider execution gates (INFRA888 · E5)
    |--------------------------------------------------------------------------
    |
    | A real vendor adapter exists from E5 onward, so "the feature is off" is no
    | longer enough — the code is now capable of changing something in the world.
    | These are the two innermost of the layered controls:
    |
    |   network_enabled   : may the adapter open a socket to the vendor at all?
    |   mutations_enabled : may it create, change or destroy anything?
    |
    | Both default to FALSE and both must be true before a single mutating call
    | can leave this process. E5 is read-only validation: network_enabled may be
    | turned on for an operator-supervised read-only run, and mutations_enabled
    | stays off until E6 authorises supervised provisioning.
    |
    | Turning network_enabled on alone is safe by construction. Turning it on
    | with mutations_enabled is the moment this platform can create a mailbox
    | somebody pays for.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Customer onboarding email (INFRA888 · E8)
    |--------------------------------------------------------------------------
    |
    | The customer-visible sender. The provider is never in this conversation:
    | its own invitation email names the vendor, which is why LevelUp sends its
    | own. Nothing here may reference a provider.
    |
    */
    'onboarding' => [
        'from_address' => env('BUSINESS_EMAIL_FROM_ADDRESS', 'support@levelupgrowth.io'),
        'from_name'    => env('BUSINESS_EMAIL_FROM_NAME', 'LevelUp Growth'),
        'reply_to'     => env('BUSINESS_EMAIL_REPLY_TO', 'support@levelupgrowth.io'),
    ],
    'provider' => [
        'network_enabled'   => env('BUSINESS_EMAIL_PROVIDER_NETWORK_ENABLED', false),
        'mutations_enabled' => env('BUSINESS_EMAIL_PROVIDER_MUTATIONS_ENABLED', false),
    ],
    'allow_scenario_control' => env('BUSINESS_EMAIL_ALLOW_SCENARIO_CONTROL', false),

    /*
     |--------------------------------------------------------------------------
     | Pagination
     |--------------------------------------------------------------------------
     */
    'admin_page_size'     => (int) env('BUSINESS_EMAIL_ADMIN_PAGE_SIZE', 50),
    'admin_max_page_size' => (int) env('BUSINESS_EMAIL_ADMIN_MAX_PAGE_SIZE', 200),
];
