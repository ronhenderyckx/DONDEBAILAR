<?php
declare(strict_types=1);

/**
 * Bepaalt de private applicatiemap voor de actieve DONDEBAILAR-omgeving.
 * De publieke map bevat uitsluitend de webbestanden; app/config/storage staan privé.
 */
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';

$environmentMap = [
    'test.dondebailar.eu' => ['private' => 'DONDEBAILAR-TEST', 'public' => 'test'],
    'preview.dondebailar.eu' => ['private' => 'DONDEBAILAR-PREVIEW', 'public' => 'preview'],
    'dondebailar.eu' => ['private' => 'DONDEBAILAR-PROD', 'public' => 'web'],
    'www.dondebailar.eu' => ['private' => 'DONDEBAILAR-PROD', 'public' => 'web'],
];

if (!isset($environmentMap[$host])) {
    http_response_code(404);
    exit('Deze DONDEBAILAR-omgeving is niet beschikbaar.');
}

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($documentRoot === false || basename($documentRoot) !== $environmentMap[$host]['public']) {
    http_response_code(503);
    exit('De publieke DONDEBAILAR-map komt niet overeen met de ingestelde omgeving.');
}

$accountRoot = realpath(dirname($documentRoot));
if ($accountRoot === false) {
    http_response_code(503);
    exit('De WebReus-accountmap kon niet worden vastgesteld.');
}

$privateRoot = $accountRoot . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . $environmentMap[$host]['private'];
if (!is_dir($privateRoot) || !is_file($privateRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
    http_response_code(503);
    exit('De private applicatiebestanden ontbreken voor deze omgeving.');
}

if (!defined('DONDEBAILAR_PRIVATE_ROOT')) {
    define('DONDEBAILAR_PRIVATE_ROOT', $privateRoot);
}
