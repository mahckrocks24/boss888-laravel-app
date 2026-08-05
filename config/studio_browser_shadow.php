<?php

/*
|--------------------------------------------------------------------------
| STUDIO888 · Browser Projection Shadow (Phase 5B) — default OFF
|--------------------------------------------------------------------------
|
| Path-specific control for the Studio AI-edit BROWSER projection SHADOW.
| It governs ONLY the shadow comparison of the new transformation engine
| against the legacy action path, on an isolated (non-mounted) shadow
| document. It NEVER affects: the customer-visible legacy edit path, image
| generation, video, other engines, persistence, or autosave.
|
| Default OFF. Turning it on enables shadow observation + structured
| comparison logging only — never a customer-visible mutation. Immediate
| rollback = set the env flag back to false (no deploy, no migration).
|
| This is NOT reused from STUDIO_TRANSFORM_ENGINE / STUDIO_IMAGE_INTELLIGENCE
| / STUDIO_MANUAL_IMAGE_SHADOW — it is a dedicated browser-shadow control.
|
*/

return [

    // Master switch for browser projection shadow comparison (Studio AI-edit only).
    'enabled' => env('STUDIO_BROWSER_PROJECTION_SHADOW', false),

    // Projection protocol schema version the browser adapter speaks.
    'schema_version' => 1,

    // Never persist, never autosave, never mutate the live document under shadow.
    'persist_shadow'  => false,
    'autosave_shadow' => false,
];
