<?php

return [
    // 2026-07-07 — moved off raw env() so it survives config:cache
    // (env() outside config files returns null when config is cached,
    // which would silently disable Sarah's whole strategy/governance brain).
    "via_runtime" => env("INTELLIGENCE_VIA_RUNTIME", false),
];
