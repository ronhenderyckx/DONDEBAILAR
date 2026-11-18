<?php
declare(strict_types=1);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';

if ($host !== 'test.dondebailar.eu') {
    http_response_code(404);
    exit('Niet beschikbaar.');
}

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

echo "DONDEBAILAR TEST-DIAGNOSE\n";
echo "PHP wordt uitgevoerd: JA\n";
echo "PHP-versie: " . PHP_VERSION . "\n";

$accountRoot = dirname(__DIR__);
$privateRoot = $accountRoot . '/private/DONDEBAILAR-TEST';

$checks = [
    'Private map' => is_dir($privateRoot),
    'Bootstrap' => is_file($privateRoot . '/app/bootstrap.php'),
    'Agenda data' => is_file($privateRoot . '/app/agenda/agenda_data.php'),
    'Agenda view' => is_file($privateRoot . '/app/views/agenda/content.php'),
    'Storage map' => is_dir($privateRoot . '/storage'),
    'Storage schrijfbaar' => is_dir($privateRoot . '/storage') && is_writable($privateRoot . '/storage'),
];

foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'NIET OK') . "\n";
}

try {
    require_once $privateRoot . '/app/bootstrap.php';
    echo "Bootstrap laden: OK\n";
} catch (Throwable $error) {
    echo "Bootstrap laden: FOUT\n";
    echo get_class($error) . ': ' . $error->getMessage() . "\n";
    echo "Bestand: " . basename($error->getFile()) . " regel " . $error->getLine() . "\n";
}
