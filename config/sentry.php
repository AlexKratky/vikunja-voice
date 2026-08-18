<?php

return [
    // Empty/missing DSN disables the SDK entirely (local dev, tests).
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('SENTRY_RELEASE'),

    // Error events only — no performance tracing or profiling.
    'traces_sample_rate' => null,
    'profiles_sample_rate' => null,

    'send_default_pii' => false,
];
