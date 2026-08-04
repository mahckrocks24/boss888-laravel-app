<?php

/*
|--------------------------------------------------------------------------
| STUDIO888 · AI Transformation Engine — Phase 1 configuration
|--------------------------------------------------------------------------
|
| Path-specific configuration for the Studio888 AI Transformation Engine.
| This is NOT the cross-engine image flag and NOT config/studio.php.
|
| The engine is DEFAULT OFF. Phase 1 ships only the operation vocabulary,
| capability advertisement, validation, and colour normalization — none of
| which is wired into any live request path. This flag exists so later phases
| can gate execution without touching this file again.
|
*/

return [

    // Master switch for the Transformation Engine execution path (later phases).
    // OFF by default: turning it on changes nothing until an executor consumes it.
    'engine_enabled' => env('STUDIO_TRANSFORM_ENGINE', false),

    // The operation-contract schema version this build validates.
    'schema_version' => 1,

    // Reserved for later phases (Selection Engine, Executor, Verification).
    // Declared here so their activation is a config change, not a code change.
    'selection_enabled'    => env('STUDIO_TRANSFORM_SELECTION', false),
    'executor_enabled'     => env('STUDIO_TRANSFORM_EXECUTOR', false),
    'verification_enabled' => env('STUDIO_TRANSFORM_VERIFICATION', false),

];
