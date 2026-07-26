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

    /*
    |--------------------------------------------------------------------------
    | Prompt Compiler shadow mode (Phase J)
    |--------------------------------------------------------------------------
    | Default ON. The compiler is deterministic + observational only: it writes
    | creative_jobs.compiled_prompt / generation_spec / compiler metadata but
    | NEVER influences execution. OFF ⇒ compiler does nothing; execution identical.
    */
    'prompt_compiler_shadow' => env('STUDIO_PROMPT_COMPILER_SHADOW', true),

    /*
    |--------------------------------------------------------------------------
    | Prompt Guardrails shadow mode (Phase K)
    |--------------------------------------------------------------------------
    | Default ON. Deterministic structural analysis of the compiler output,
    | persisted to creative_jobs.metadata.guardrails. Observational only — never
    | blocks, modifies, or rejects a prompt. OFF ⇒ no evaluation; execution identical.
    */
    'prompt_guardrails_shadow' => env('STUDIO_PROMPT_GUARDRAILS_SHADOW', true),

    /*
    |--------------------------------------------------------------------------
    | Execution shadow comparison & compiler readiness (Phase L)
    |--------------------------------------------------------------------------
    | Observational only. `execution_prompt_observation` captures the actual
    | production provider prompt (sanitized) from the persisted asset; it never
    | alters the provider request. `compiler_readiness_shadow` runs the
    | deterministic comparison and persists readiness evidence. OFF ⇒ execution
    | and provider prompts remain identical.
    */
    'execution_prompt_observation' => env('STUDIO_EXECUTION_PROMPT_OBSERVATION', true),
    'compiler_readiness_shadow'    => env('STUDIO_COMPILER_READINESS_SHADOW', true),

    /*
    |--------------------------------------------------------------------------
    | Prompt Compiler V2 — production parity shadow (Phase N)
    |--------------------------------------------------------------------------
    | Default ON. V2 deterministically reproduces the production image-enhancement
    | pipeline (blueprint additions + no-text rule) for byte-parity measurement.
    | Observational only — never transmitted, never alters execution. OFF ⇒ only V1
    | runs; behaviour identical to Phase L/M.
    */
    'prompt_compiler_v2_shadow' => env('STUDIO_PROMPT_COMPILER_V2_SHADOW', true),

];
