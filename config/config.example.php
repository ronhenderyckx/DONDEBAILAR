<?php
declare(strict_types=1);

/*
 * Copy this file to config.local.php and fill in your local values.
 * config.local.php is ignored by Git and must never be committed.
 */
return [
    'app' => [
        'name' => 'DONDEBAILE',
        'environment' => 'development',
        'debug' => true,
        // Keep the current production domain until the new domain is registered and configured.
        'base_domain' => 'dondebailar.eu',
        'default_timezone' => 'Europe/Madrid',
        'supported_languages' => ['nl', 'en', 'es', 'fr'],
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'dondebailar',
        'username' => 'dondebailar_user',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'DONDEBAILAR_SESSION',
        'https_only' => false, // Set true on production HTTPS.
    ],
];
