<?php

/*
|--------------------------------------------------------------------------
| Cloudflare for SaaS (Custom Hostnames) — INFRA888 Domains
|--------------------------------------------------------------------------
| Locked architecture (2026-07-24): external customer domains are connected
| via Cloudflare for SaaS Custom Hostnames, NOT by creating DNS records for
| the customer's domain inside the LevelUp Growth zone.
|
| `saas_enabled` is the PRODUCTION MUTATION GATE. While false, the domain
| service performs NO live Cloudflare mutations — connect() short-circuits
| with a clear "not yet available" result. It is flipped to true only after
| the scoped token + zone readiness are verified (Sprint 3, Phase 8/9).
|
| env() is read directly because this app must NOT be config:cache'd
| (RuntimeClient reads env() live — see ops notes).
*/

return [
    'zone_id'    => env('CLOUDFLARE_ZONE_ID'),
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'api_token'  => env('CLOUDFLARE_API_TOKEN'),

    // The SaaS fallback origin (set via the CF fallback_origin endpoint) that
    // requests to custom hostnames are sent to. Must be a proxied A/AAAA/CNAME
    // record inside the SaaS zone. Provisioned during Phase 8.
    'fallback_origin' => env('CLOUDFLARE_SAAS_FALLBACK_ORIGIN', 'levelupgrowth.io'),

    // The proxied hostname in the SaaS zone that customers CNAME toward (the
    // "CNAME target"). Cloudflare routes it to the fallback origin. Defaults to
    // the fallback origin until a dedicated target is published.
    'routing_target' => env('CLOUDFLARE_SAAS_ROUTING_TARGET', env('CLOUDFLARE_SAAS_FALLBACK_ORIGIN', 'levelupgrowth.io')),

    // Production mutation gate. MUST stay false until live validation passes.
    'saas_enabled' => filter_var(env('CLOUDFLARE_SAAS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // DV certificate validation method for new custom hostnames: 'txt' or 'http'.
    'ssl_validation_method' => env('CLOUDFLARE_SAAS_SSL_METHOD', 'txt'),

    // Provider key persisted on each domain row (portability).
    'provider' => 'cloudflare_saas',
];
