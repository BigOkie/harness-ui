<?php
declare(strict_types=1);

/**
 * Harness UI config.
 * Keep this file OUTSIDE the web root.
 */
return [
    // Harness dirs
    'ws_harness_dir'   => '/usr/local/bin/work/ws-harness-php',
    'rest_harness_dir' => '/usr/local/bin/work/rest-harness-php',

    // PHP binary
    'php_bin' => '/usr/bin/php',

    // Where to store per-run output
    'runs_dir'  => '/srv/harness-ui/var/runs',
    'creds_dir' => '/srv/harness-ui/creds',

    // WS URLs (Pool + routed locations)
    'envs' => [
        'staging' => [
            'Pool'    => 'wss://secure-staging-ws.shieldconex.com',
            'Atlanta' => 'wss://secure-staging-ws-atl.shieldconex.com',
            'Tulsa'   => 'wss://secure-staging-ws-tul.shieldconex.com',
        ],
        'cert' => 'wss://secure-cert-ws.shieldconex.com',
        'prod' => [
            'Pool'    => 'wss://secure-ws.shieldconex.com',
            'Atlanta' => 'wss://secure-ws-atl.shieldconex.com',
            'Tulsa'   => 'wss://secure-ws-tul.shieldconex.com',
        ],
    ],

    // REST base URLs (include /api if that’s what the REST harness expects)
    'rest_envs' => [
        'staging' => [
            'Pool'    => 'https://secure-staging.shieldconex.com/api',
            'Atlanta' => 'https://secure-staging-atl.shieldconex.com/api',
            'Tulsa'   => 'https://secure-staging-tul.shieldconex.com/api',
        ],
        'cert' => [
            'Global Pool' => 'https://secure-cert.shieldconex.com/api',
            'Regional US' => 'https://secure-cert.shieldconex.us/api',
            'Regional EU' => 'https://secure-cert.shieldconex.eu/api',
            'Site FRA'    => 'https://secure-cert-de.shieldconex.eu/api',
            'Site DUB'    => 'https://secure-cert-ie.shieldconex.eu/api',
            'Cloud FRA'   => 'https://secure-cert-de.cloud.shieldconex.com/api',
            'Cloud DUB'   => 'https://secure-cert-ie.cloud.shieldconex.com/api',
        ],
        'prod' => [
            'Pool'    => 'https://secure.shieldconex.com/api',
            'Atlanta' => 'https://secure-atl.shieldconex.com/api',
            'Tulsa'   => 'https://secure-tul.shieldconex.com/api',
        ],
    ],

    // Max runtime (seconds) for one run (PHP-side guard)
    'max_run_seconds' => 300,
];
