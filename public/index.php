<?php
declare(strict_types=1);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';

$environmentRoots = [
    'test.dondebailar.eu' => 'DONDEBAILAR-TEST',
    'preview.dondebailar.eu' => 'DONDEBAILAR-PREVIEW',
    'dondebailar.eu' => 'DONDEBAILAR-PROD',
    'www.dondebailar.eu' => 'DONDEBAILAR-PROD',
];

if (!isset($environmentRoots[$host])) {
    http_response_code(404);
    exit('Website niet beschikbaar.');
}

$accountRoot = dirname(__DIR__);
$privateRoot = $accountRoot . '/private/' . $environmentRoots[$host];

if (!is_dir($privateRoot) || !is_file($privateRoot . '/app/bootstrap.php')) {
    http_response_code(503);
    exit('De private applicatiebestanden zijn niet beschikbaar.');
}

require $privateRoot . '/app/agenda/agenda_data.php';
require $privateRoot . '/app/views/agenda/content.php';
