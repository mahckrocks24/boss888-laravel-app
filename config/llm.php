<?php

return [

    'default' => env('LLM_PROVIDER', 'deepseek'),

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY', ''),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'timeout' => env('DEEPSEEK_TIMEOUT', 30),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'model' => env('OPENAI_CHAT_MODEL', 'gpt-4o'),
        'timeout' => env('OPENAI_TIMEOUT', 30),
    ],

    // Agent reasoning defaults
    'agent' => [
        'temperature' => 0.7,
        'max_tokens' => 2000,
        'planning_temperature' => 0.4,
        'planning_max_tokens' => 3000,
    ],

];
