<?php

return [

    /*
    |--------------------------------------------------------------------------
    | STUDIO888 — creative_jobs observational domain model (Phase I)
    |--------------------------------------------------------------------------
    | Default ON. CreativeJob is shadow/observational persistence only — it
    | records Studio executions as first-class objects but never controls
    | execution. OFF ⇒ the system behaves exactly as before (no jobs written).
    | Negligible risk: pure best-effort side-write.
    */
    'creative_jobs' => env('STUDIO_CREATIVE_JOBS_ENABLED', true),

];
