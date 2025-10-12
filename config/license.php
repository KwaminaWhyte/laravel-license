<?php

return [
    /*
    |--------------------------------------------------------------------------
    | License Mode
    |--------------------------------------------------------------------------
    |
    | This determines whether the package runs in 'server' or 'client' mode.
    |
    | Server Mode: Manages licenses, handles validation requests, stores data
    | Client Mode: Validates against remote server, caches licenses locally
    |
    | Supported: 'server', 'client'
    |
    */

    'mode' => env('LICENSE_MODE', 'client'),

    /*
    |--------------------------------------------------------------------------
    | Server Configuration (Server Mode Only)
    |--------------------------------------------------------------------------
    */

    'server' => [
        // JWT Secret for signing offline tokens
        'jwt_secret' => env('LICENSE_JWT_SECRET', env('APP_KEY')),

        // JWT algorithm (HS256, HS512, RS256, etc.)
        'jwt_algorithm' => env('LICENSE_JWT_ALGORITHM', 'HS256'),

        // Default grace period in days
        'grace_period_days' => (int) env('LICENSE_GRACE_PERIOD_DAYS', 7),

        // Default offline validation period in days
        'offline_validation_days' => (int) env('LICENSE_OFFLINE_VALIDATION_DAYS', 30),

        // Default activation limit per license
        'default_activation_limit' => (int) env('LICENSE_DEFAULT_ACTIVATION_LIMIT', 5),

        // Enable API routes
        'enable_api_routes' => env('LICENSE_ENABLE_API_ROUTES', true),

        // API route prefix
        'api_prefix' => env('LICENSE_API_PREFIX', 'api/license'),

        // Enable CORS for API
        'enable_cors' => env('LICENSE_ENABLE_CORS', true),

        // Allowed origins for CORS (null = allow all)
        'cors_origins' => env('LICENSE_CORS_ORIGINS', '*'),

        // Enable rate limiting
        'rate_limiting' => [
            'enabled' => env('LICENSE_RATE_LIMITING', true),
            'max_attempts' => 60,
            'decay_minutes' => 1,
        ],

        // Database table names
        'tables' => [
            'products' => 'products',
            'licenses' => 'licenses',
            'license_activations' => 'license_activations',
            'license_validations' => 'license_validations',
            'license_features' => 'license_features',
            'feature_definitions' => 'feature_definitions',
            'product_feature_assignments' => 'product_feature_assignments',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Configuration (Client Mode Only)
    |--------------------------------------------------------------------------
    */

    'client' => [
        // License server URL
        'server_url' => env('LICENSE_SERVER_URL', 'https://license.yourdomain.com'),

        // Your license key
        'license_key' => env('LICENSE_KEY'),

        // Product ID you're licensing for
        'product_id' => env('LICENSE_PRODUCT_ID'),

        // Cache TTL in seconds (0 = no cache, null = cache indefinitely)
        'cache_ttl' => (int) env('LICENSE_CACHE_TTL', 86400), // 24 hours

        // Cache store to use
        'cache_store' => env('LICENSE_CACHE_STORE', 'file'),

        // Enable offline mode (uses JWT tokens)
        'offline_mode' => env('LICENSE_OFFLINE_MODE', true),

        // Auto-generate hardware fingerprint
        'auto_fingerprint' => env('LICENSE_AUTO_FINGERPRINT', true),

        // Custom hardware fingerprint (overrides auto-generation)
        'hardware_fingerprint' => env('LICENSE_HARDWARE_FINGERPRINT'),

        // HTTP timeout in seconds
        'http_timeout' => (int) env('LICENSE_HTTP_TIMEOUT', 10),

        // Retry failed requests
        'retry' => [
            'enabled' => env('LICENSE_RETRY_ENABLED', true),
            'times' => (int) env('LICENSE_RETRY_TIMES', 3),
            'sleep' => (int) env('LICENSE_RETRY_SLEEP', 1000), // milliseconds
        ],

        // Heartbeat interval in seconds (how often to ping server)
        'heartbeat_interval' => (int) env('LICENSE_HEARTBEAT_INTERVAL', 86400), // 24 hours

        // Fail behavior when license is invalid
        'on_failure' => env('LICENSE_ON_FAILURE', 'throw'), // 'throw', 'log', 'silent'

        // Grace period handling
        'grace_period_warning' => env('LICENSE_GRACE_PERIOD_WARNING', true),

        // System info to send with validation requests
        'system_info' => [
            'enabled' => env('LICENSE_SEND_SYSTEM_INFO', true),
            'include_php_version' => true,
            'include_laravel_version' => true,
            'include_server_software' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hardware Fingerprinting
    |--------------------------------------------------------------------------
    */

    'fingerprint' => [
        // Components to use for fingerprinting
        'components' => [
            'hostname' => true,
            'ip_address' => true,
            'mac_address' => true,
            'cpu_info' => false, // May not work on all systems
            'disk_serial' => false, // May not work on all systems
            'user_agent' => true,
            'platform' => true,
        ],

        // Fingerprint tolerance (0-100, higher = more tolerant to changes)
        'tolerance' => (int) env('LICENSE_FINGERPRINT_TOLERANCE', 10),

        // Algorithm for hashing fingerprint
        'hash_algorithm' => env('LICENSE_FINGERPRINT_HASH', 'sha256'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    */

    'features' => [
        // Enable feature gating
        'enabled' => env('LICENSE_FEATURES_ENABLED', true),

        // Default behavior when feature not found
        'default_access' => env('LICENSE_DEFAULT_FEATURE_ACCESS', false),

        // Cache feature checks
        'cache_checks' => env('LICENSE_CACHE_FEATURE_CHECKS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        // Enable logging
        'enabled' => env('LICENSE_LOGGING_ENABLED', true),

        // Log channel
        'channel' => env('LICENSE_LOG_CHANNEL', 'stack'),

        // Log validation attempts
        'log_validations' => env('LICENSE_LOG_VALIDATIONS', true),

        // Log feature checks
        'log_feature_checks' => env('LICENSE_LOG_FEATURE_CHECKS', false),

        // Log level
        'level' => env('LICENSE_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    'events' => [
        // Enable events
        'enabled' => env('LICENSE_EVENTS_ENABLED', true),

        // Events to fire
        'fire' => [
            'license_validated' => true,
            'license_activated' => true,
            'license_deactivated' => true,
            'license_expired' => true,
            'license_invalid' => true,
            'feature_checked' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        // Auto-register middleware
        'auto_register' => env('LICENSE_AUTO_REGISTER_MIDDLEWARE', true),

        // Middleware aliases
        'aliases' => [
            'license.valid' => \Westel\License\Http\Middleware\EnsureLicenseValid::class,
            'license.feature' => \Westel\License\Http\Middleware\CheckFeatureAccess::class,
            'license.server' => \Westel\License\Http\Middleware\EnsureServerMode::class,
        ],

        // Redirect route when license invalid
        'redirect_route' => env('LICENSE_REDIRECT_ROUTE', 'license.invalid'),

        // Exception when license invalid (null = redirect)
        'throw_exception' => env('LICENSE_THROW_EXCEPTION', false),
    ],
];
