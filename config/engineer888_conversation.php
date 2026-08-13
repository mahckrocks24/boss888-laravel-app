<?php

use App\Core\Engineer888\Conversation\Providers\DeepSeekConversationProvider;
use App\Core\Engineer888\Conversation\Providers\NullConversationProvider;
use App\Core\Engineer888\Conversation\Providers\OpenAiConversationProvider;
use App\Core\Engineer888\Conversation\Providers\ScriptedConversationProvider;

/*
|--------------------------------------------------------------------------
| Engineer888 conversation
|--------------------------------------------------------------------------
|
| THE CAPABILITY IS `engineering_conversation`. Nothing outside this file names
| a vendor. MessageService asks the registry for the capability; the registry
| decides who answers it. Swapping OpenAI for DeepSeek is a change to this file
| and nothing else — the same contract the reasoning engine already keeps.
|
| WHY THIS IS A SEPARATE MAP FROM engineer888_reasoning.php.
| They are different jobs with different failure costs. Reasoning produces a
| candidate that will be validated, fingerprinted, approved and installed; its
| budget is minutes and 8000 tokens because a truncated proposal is worthless.
| Conversation produces a sentence a human reads; it must be fast, and a
| conversational turn must never be able to spend a reasoning budget. Sharing
| one map would have tied the two together the first time either needed tuning.
|
| Read through config(), never a cached config — this platform runs uncached by
| standing rule because RuntimeClient reads env() directly.
*/

return [

    // Conversation off returns Engineer888 to deterministic answers only. It
    // does not fabricate: with this false, chat says what it can read from the
    // database and says plainly that it cannot reason.
    'enabled' => env('E888_CONVERSATION_ENABLED', true),

    // Defaults to the reasoning provider so a working environment converses
    // without a second piece of configuration, and still resolves to 'null'
    // (honest unavailability) on a fresh environment that has chosen nothing.
    'provider' => env('E888_CONVERSATION_PROVIDER', env('E888_REASONING_PROVIDER', 'null')),

    'providers' => [
        'openai'   => OpenAiConversationProvider::class,
        'deepseek' => DeepSeekConversationProvider::class,
        'scripted' => ScriptedConversationProvider::class,
        'null'     => NullConversationProvider::class,
    ],

    /*
    | Context budget.
    |
    | Sarah's CognitiveFrame is character-budgeted for a reason her own comments
    | record: relevant knowledge buried under irrelevant knowledge is knowledge
    | the model will not use. Engineer888 has more machine-readable state than
    | Sarah does — 45 tasks, 100+ candidates, an audit trail — so an unbounded
    | frame would be mostly noise.
    */
    'context' => [
        'max_chars'       => (int) env('E888_CONVERSATION_CONTEXT_CHARS', 14000),
        'history_turns'   => (int) env('E888_CONVERSATION_HISTORY_TURNS', 16),
        'max_tasks'       => (int) env('E888_CONVERSATION_MAX_TASKS', 8),
        'max_candidates'  => (int) env('E888_CONVERSATION_MAX_CANDIDATES', 5),
    ],

    'openai' => [
        'model'       => env('E888_CONVERSATION_OPENAI_MODEL', env('OPENAI_CHAT_MODEL', 'gpt-4o')),
        'endpoint'    => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'temperature' => 0.4,
        'max_tokens'  => 1200,
        'timeout'     => (int) env('E888_CONVERSATION_TIMEOUT', 60),
    ],

    'deepseek' => [
        'model'       => env('E888_CONVERSATION_DEEPSEEK_MODEL', env('DEEPSEEK_MODEL', 'deepseek-chat')),
        'endpoint'    => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'temperature' => 0.4,
        'max_tokens'  => 1200,
        'timeout'     => (int) env('E888_CONVERSATION_TIMEOUT', 60),
    ],

    'scripted' => [
        'label' => env('E888_CONVERSATION_SCRIPTED_LABEL', 'scripted-conversation'),
    ],

    'null' => [],

];
