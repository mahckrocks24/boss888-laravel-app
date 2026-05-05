<?php

return [
    'stripe' => [
        // Accept both new (STRIPE_KEY/STRIPE_SECRET — Laravel convention) and
        // legacy (STRIPE_PUBLISHABLE_KEY/STRIPE_SECRET_KEY) env names.
        'secret_key'      => env('STRIPE_SECRET', env('STRIPE_SECRET_KEY', '')),
        'publishable_key' => env('STRIPE_KEY',    env('STRIPE_PUBLISHABLE_KEY', '')),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET', ''),
    ],
];
