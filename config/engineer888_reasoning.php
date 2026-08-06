<?php

use App\Core\Engineer888\Reasoning\Providers\DeepSeekReasoningProvider;
use App\Core\Engineer888\Reasoning\Providers\NullReasoningProvider;
use App\Core\Engineer888\Reasoning\Providers\OpenAiReasoningProvider;
use App\Core\Engineer888\Reasoning\Providers\ScriptedReasoningProvider;

/*
|--------------------------------------------------------------------------
| Engineering Reasoning Engine
|--------------------------------------------------------------------------
|
| Swapping the reasoning provider is a change to THIS FILE and nothing else.
| No stage, no test, no governance control and no part of the workflow names a
| provider. If a swap ever requires editing anything outside this map, the
| provider abstraction has failed and should be fixed rather than worked around.
|
| NOTE: this file is read through config(), never through a cached config.
| RuntimeClient reads env() directly on this platform and `config:cache` breaks
| it, so the platform runs uncached by standing rule.
|
*/

return [

    // Reasoning off means PLAN keeps its Sprint 6 behaviour exactly: a task with
    // no change set blocks and asks for one.
    'enabled' => env('E888_REASONING_ENABLED', true),

    // The default is 'null' on purpose. A fresh environment must not start
    // making paid API calls because nobody chose a provider.
    'provider' => env('E888_REASONING_PROVIDER', 'null'),

    'providers' => [
        'deepseek' => DeepSeekReasoningProvider::class,
        'openai'   => OpenAiReasoningProvider::class,
        'scripted' => ScriptedReasoningProvider::class,
        'null'     => NullReasoningProvider::class,
    ],

    // How much institutional knowledge one request may carry. The limit is not
    // about cost — it is that relevant knowledge buried under irrelevant
    // knowledge is knowledge the model will not use.
    'context' => [
        'max_bytes'            => (int) env('E888_REASONING_CONTEXT_BYTES', 60000),
        'max_items_per_source' => (int) env('E888_REASONING_ITEMS_PER_SOURCE', 6),
        // Minimum vocabulary overlap with the task before an item is included.
        'min_score'            => (int) env('E888_REASONING_MIN_SCORE', 1),
    ],

    // Structural limits on what a proposal may contain. Exceeding one rejects
    // the candidate; it never truncates it, because a truncated file that still
    // installs is worse than no proposal at all.
    'candidate' => [
        'max_files'      => (int) env('E888_REASONING_MAX_FILES', 12),
        'max_file_bytes' => (int) env('E888_REASONING_MAX_FILE_BYTES', 60000),
    ],

    'deepseek' => [
        'model'       => env('E888_REASONING_DEEPSEEK_MODEL', env('DEEPSEEK_MODEL', 'deepseek-chat')),
        'temperature' => 0.2,
        'max_tokens'  => 8000,
        // Not the platform's 30s chat default: V4 reasoning is always on and a
        // full proposal takes minutes. Applied per call, not to DEEPSEEK_TIMEOUT.
        'timeout'     => (int) env('E888_REASONING_DEEPSEEK_TIMEOUT', 240),
    ],

    'openai' => [
        'model'       => env('E888_REASONING_OPENAI_MODEL', env('OPENAI_CHAT_MODEL', 'gpt-4o')),
        'temperature' => 0.2,
        'max_tokens'  => 8000,
        'timeout'     => 180,
    ],

    'scripted' => [
        'label'   => env('E888_REASONING_SCRIPTED_LABEL', 'scripted-fixture'),
        'fixture' => env('E888_REASONING_FIXTURE'),
    ],

    'null' => [],

];
