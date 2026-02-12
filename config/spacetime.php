<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SpacetimeDB sidecar mirror
    |--------------------------------------------------------------------------
    |
    | When enabled, Laravel mirrors each accepted location update into the
    | SpacetimeDB location sidecar using the Spacetime CLI.
    |
    */

    'location_mirror_enabled' => (bool) env('SPACETIME_LOCATION_MIRROR_ENABLED', false),

    'cli_path' => env('SPACETIME_CLI_PATH', 'spacetime'),

    // Usually "local" for local smoke tests or "maincloud" for hosted runs.
    'server' => env('SPACETIME_SERVER', 'local'),

    // Example: calvary-location-tracker
    'database' => env('SPACETIME_DATABASE', ''),

    // Enable for local smoke/bootstrapping before permanent identity auth is set.
    'anonymous' => (bool) env('SPACETIME_ANONYMOUS', false),

    // Keep this short so API response path is never held up for long.
    'timeout_seconds' => (int) env('SPACETIME_TIMEOUT_SECONDS', 4),
];
