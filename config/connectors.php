<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Creative Engine (Native AI — OpenAI + MiniMax)
    |--------------------------------------------------------------------------
    | Phase 2 will replace this with direct OpenAI/MiniMax API calls.
    | For now, base_url points to self (Laravel handles internally).
    */
    'creative' => [
        'base_url' => env('CREATIVE_API_URL', 'http://localhost:8000'),
        'api_key' => env('OPENAI_API_KEY', ''),
        'timeout' => env('CREATIVE_TIMEOUT', 30),
        'poll_max_attempts' => env('CREATIVE_POLL_MAX', 30),
        'poll_interval_ms' => env('CREATIVE_POLL_INTERVAL', 2000),
        'min_asset_size' => env('CREATIVE_MIN_ASSET_SIZE', 1024),
        'image_model' => env('CREATIVE_IMAGE_MODEL', 'gpt-image-1'),
        'video_provider' => env('CREATIVE_VIDEO_PROVIDER', 'minimax'),
        'minimax_api_key' => env('MINIMAX_API_KEY', ''),
        'runway_api_key' => env('RUNWAY_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email (Postmark / SMTP)
    |--------------------------------------------------------------------------
    */
    'email' => [
        'driver' => env('EMAIL_CONNECTOR_DRIVER', 'smtp'),
        'postmark_token' => env('POSTMARK_TOKEN', ''),
        'from_email' => env('EMAIL_FROM_ADDRESS', 'noreply@levelupgrowth.io'),
        'from_name' => env('EMAIL_FROM_NAME', 'LevelUp Growth'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Media
    |--------------------------------------------------------------------------
    */
    'social' => [
        'base_url' => env('SOCIAL_CONNECTOR_URL', ''),
        'api_key' => env('SOCIAL_CONNECTOR_API_KEY', ''),
        'mock_mode' => env('SOCIAL_MOCK_MODE', true),
    ],

];
