<?php

return [
    'enabled' => env('DB_OPTIMIZER_ENABLED', env('APP_ENV') === 'local'),
    'capture_console' => env('DB_OPTIMIZER_CAPTURE_CONSOLE', false),
    'capture_testing' => env('DB_OPTIMIZER_CAPTURE_TESTING', false),
    'sample_rate' => (float) env('DB_OPTIMIZER_SAMPLE_RATE', 1.0),

    'slow_query_threshold_ms' => (float) env('DB_OPTIMIZER_SLOW_MS', 50),
    'n_plus_one_repeat_threshold' => (int) env('DB_OPTIMIZER_N1_THRESHOLD', 5),
    'cache_candidate_repeat_threshold' => (int) env('DB_OPTIMIZER_CACHE_REPEAT_THRESHOLD', 8),

    'storage_disk' => env('DB_OPTIMIZER_STORAGE_DISK', 'local'),
    'storage_path' => env('DB_OPTIMIZER_STORAGE_PATH', 'db-optimizer'),

    'agent_token' => env('DB_OPTIMIZER_AGENT_TOKEN', ''),
    'scanner' => [
        'timeout_seconds' => (int) env('DB_OPTIMIZER_SCANNER_TIMEOUT', 20),
    ],

    'route_prefix' => env('DB_OPTIMIZER_ROUTE_PREFIX', '_db-optimizer'),
    'register_dashboard_routes' => env('DB_OPTIMIZER_REGISTER_DASHBOARD_ROUTES', true),
    'register_agent_routes' => env('DB_OPTIMIZER_REGISTER_AGENT_ROUTES', true),
];
