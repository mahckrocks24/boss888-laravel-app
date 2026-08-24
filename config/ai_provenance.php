<?php

/*
|--------------------------------------------------------------------------
| AI execution provenance and pricing
|--------------------------------------------------------------------------
|
| Added 2026-07-29 for remediation D-01 / D-02 (see DEEPSEEK-V4-FORENSIC-AUDIT.md).
|
| THE DEFECT THIS EXISTS TO FIX
| The runtime returns complete, truthful attribution — requested_provider,
| actual_provider, requested_model, actual_model, fallback_used, fallback_reason
| and an exact usage object. Laravel discarded all of it, hardcoded
| provider='deepseek', and estimated tokens as strlen(text)/4. The result was 67
| production rows recording OpenAI gpt-4o-mini traffic as DeepSeek, and output
| token counts understated by up to 801x on reasoning-heavy V4 calls.
|
| THE FLAG
| `enabled` defaults to FALSE so this change can land without altering a single
| byte of production behaviour. With the flag off, RuntimeClient takes exactly
| the path it took before. With it on, the provenance columns are written and
| the legacy provider/model columns carry ACTUAL execution values.
|
| Flipping the flag is also the rollback: no deploy, no migration reversal.
|
*/

return [

    /*
    | Master switch. OFF until the tests and rollback have been reviewed.
    | Turning this on changes what the admin API Usage page reports — see
    | "SEMANTIC CHANGE" below. That is the intended fix, not a side effect.
    */
    'enabled' => env('AI_PROVENANCE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | SEMANTIC CHANGE — declared, not silent
    |--------------------------------------------------------------------------
    |
    | With the flag ON, the legacy columns are DEFINED as the actual execution:
    |
    |   api_usage_logs.provider  = the provider that actually executed the call
    |   api_usage_logs.model     = the model that actually executed the call
    |
    | `model` already held the actual value — it read the runtime's response
    | `model` field, which the runtime sets to gpt-4o-mini on fallback. Only
    | `provider` was wrong. Aligning `provider` to actual therefore removes an
    | internal contradiction rather than introducing a new meaning.
    |
    | Nothing is lost: requested_provider and requested_model are recorded in
    | their own columns.
    |
    | The one downstream consumer is the admin /api-usage endpoint
    | (routes/api.php:2417). Its `by_provider` grouping will begin showing
    | `openai` as a separate row for fallback traffic, and its token/cost sums
    | will rise because they are finally measured rather than guessed.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Pricing — per 1,000,000 tokens, USD, keyed by ACTUAL executed model
    |--------------------------------------------------------------------------
    |
    | Source:   https://api-docs.deepseek.com/quick_start/pricing
    | Accessed: 2026-07-29
    |
    | The previous implementation applied ONE flat rate to everything recorded
    | as "deepseek": $0.14 in / $0.28 out, commented "DeepSeek V3". That rate is
    | correct for V4 Flash by coincidence — V3 and V4-Flash are priced
    | identically — but understated V4 Pro by 3.1x and mispriced OpenAI
    | fallbacks entirely.
    |
    | `cached_input` applies to the portion of input the provider reports as
    | cache hits. Null means the provider does not expose a cache tier here.
    |
    */
    'pricing' => [

        'deepseek-v4-flash' => [
            'input'        => 0.14,
            'cached_input' => 0.0028,
            'output'       => 0.28,
        ],

        'deepseek-v4-pro' => [
            'input'        => 0.435,
            'cached_input' => 0.003625,
            'output'       => 0.87,
        ],

        // Fallback target. OpenAI list pricing; not from the DeepSeek page.
        'gpt-4o-mini' => [
            'input'        => 0.15,
            'cached_input' => null,
            'output'       => 0.60,
        ],
    ],

    /*
    | Historical models. Retired by DeepSeek 2026-07-24 15:59 UTC and rejected
    | by the runtime, but 3,651 rows predate that. Kept so old rows can still be
    | priced consistently if ever recomputed. NOT valid for new execution.
    */
    'pricing_historical' => [
        'deepseek-chat'     => ['input' => 0.14, 'cached_input' => 0.014, 'output' => 0.28],
        'deepseek-reasoner' => ['input' => 0.55, 'cached_input' => 0.14,  'output' => 2.19],
    ],

    /*
    | Applied when the executed model is not in either table above. Recorded as
    | pricing_source='unknown' so it is visible rather than silently zero.
    */
    'pricing_unknown_is_zero' => true,

    'pricing_source_url'      => 'https://api-docs.deepseek.com/quick_start/pricing',
    'pricing_accessed_at'     => '2026-07-29',
];
