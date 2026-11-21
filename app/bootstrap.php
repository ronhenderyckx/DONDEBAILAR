<?php
declare(strict_types=1);

$configFile = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($configFile)) {
    http_response_code(503);
    throw new RuntimeException(
        'Local configuration is missing. Copy config/config.example.php to config/config.local.php and configure it.'
    );
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('Application configuration must return an array.');
}

$GLOBALS['config'] = $config;

$timezone = $config['app']['default_timezone'] ?? 'Europe/Madrid';
date_default_timezone_set((string) $timezone);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';
$debug = (bool) ($config['app']['debug'] ?? false) && $host === 'test.dondebailar.eu';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Weiger niet-bestaande of door een aanvaller vooraf gekozen sessie-ID's.
    ini_set('session.use_strict_mode', '1');

    $httpsOnly = (bool) ($config['security']['https_only'] ?? false);
    session_name((string) ($config['security']['session_name'] ?? 'DONDEBAILAR_SESSION'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $httpsOnly,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/Database.php';
