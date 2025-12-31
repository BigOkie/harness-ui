<?php
declare(strict_types=1);

/**
 * Harness UI config (WS suite MVP).
 * Keep this file OUTSIDE the web root.
 */

return [
    // Where the WS harness lives (must be readable/executable by www-data)
    'ws_harness_dir' => '/usr/local/bin/work/ws-harness-php',

    // PHP binary to use
    'php_bin' => '/usr/bin/php',

    // Where to store per-run output
    'runs_dir' => '/srv/harness-ui/var/runs',

    'creds_dir' => '/srv/harness-ui/creds',

    // Allowed environments -> WS URL
    'envs' => [
        'staging' => 'wss://secure-staging-ws.shieldconex.com',
        'cert'    => 'wss://secure-cert-ws.shieldconex.com',
        'prod'    => 'wss://secure-ws.shieldconex.com',
    ],

    // Max runtime (seconds) for one run (PHP-side guard)
    'max_run_seconds' => 300,
'rest_harness_dir' => '/usr/local/bin/work/rest-harness-php',

// base URLs for REST (edit to match your real endpoints)
'rest_envs' => [
    'staging' => 'https://secure-staging.shieldconex.com/api',
    'cert'    => 'https://secure-cert.shieldconex.com/api',
    'prod'    => 'https://secure.shieldconex.com/api', // if that’s correct for prod
],


];
