<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WAVE 4A — Manual Studio "Generate Image" shadow-migration control
    |--------------------------------------------------------------------------
    | PATH-SPECIFIC. Read ONLY by ImageIntelligenceService::generate() and ONLY
    | for the manual Studio route (context source === 'studio'). It cannot affect
    | any other caller (CreativeService::generateImage, generateThroughBlueprint,
    | Write/Blog/Social/Marketing/CRM/Builder/Bella, video, or image editing).
    |
    | This is NOT the cross-engine STUDIO_IMAGE_INTELLIGENCE_ENABLED flag (which
    | was proven mis-scoped). Independent, reversible, default OFF.
    |
    | Default OFF preserves current production behaviour byte-for-byte. When ON,
    | the manual route ADDITIONALLY derives a Creative888-brief plan
    | (getImageBlueprint → compileFromBrief) purely for comparison — no provider
    | call, no credit reservation, no asset/creative_job, no persistence, and it
    | never changes the user response. Legacy reasoning remains authoritative.
    |
    | Not activated in production by this wave.
    */
    'manual_shadow_enabled' => env('STUDIO_MANUAL_IMAGE_SHADOW', false),
];
