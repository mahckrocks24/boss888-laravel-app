<?php

/*
|--------------------------------------------------------------------------
| Marketing / Public Launch Gate (MW-1a — Entry-Point Isolation)
|--------------------------------------------------------------------------
| Single source of truth for the pre-launch gate. While `public_launched` is
| false, the PRODUCTION marketing hosts must behave as a marketing-and-
| information site only: no visitor may enter the app, every marketing→/app
| entry point is gated, staging is not indexable, and unreleased functionality
| stays on staging (staging.levelupgrowth.io) exclusively.
|
| Flip PLATFORM_PUBLIC_LAUNCHED=true (and re-enable the SPA-shell guard flag)
| at official platform launch, per the Launch Activation Checklist.
*/

return [
    'public_launched'  => filter_var(env('PLATFORM_PUBLIC_LAUNCHED', false), FILTER_VALIDATE_BOOLEAN),

    // Public production marketing hosts. Everything else — staging.levelupgrowth.io,
    // the server IP, customer subdomains — is internal/non-public for gating purposes.
    'production_hosts' => ['levelupgrowth.io', 'www.levelupgrowth.io'],

    // Hosts that must never be indexed (staging + direct IP).
    'noindex_hosts'    => ['staging.levelupgrowth.io', '134.209.93.41'],

    // Where a gated marketing CTA points instead of the app (honest, no app entry,
    // no new page required until a waitlist is built in a later MW-1 step).
    'gated_cta_href'   => 'mailto:hello@levelupgrowth.io?subject=Notify%20me%20when%20LevelUp%20launches',
];
