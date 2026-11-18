<?php
declare(strict_types=1);

/*
 * Tijdelijke diagnosepagina voor uitsluitend de TEST-omgeving.
 * Geeft nooit configuratiewaarden, gebruikersnamen of wachtwoorden weer.
 */

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';

if ($host !== 'test.dondebailar.eu') {
    http_response_code(404);
    exit('Niet beschikbaar.');
}

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_implicit_flush(true);

$accountRoot = dirname(__DIR__);
$privateRoot = $accountRoot . '/private/DONDEBAILAR-TEST';

function diagnose(string $label, callable $callback): void
{
    echo $label . ': BEZIG...\n';
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();

    try {
        $callback();
        echo $label . ': OK\n';
    } catch (Throwable $error) {
        echo $label . ': FOUT\n';
        echo 'Fouttype: ' . get_class($error) . "\n";
        echo 'Melding: ' . $error->getMessage() . "\n";
        echo 'Bestand: ' . basename($error->getFile()) . ' regel ' . $error->getLine() . "\n";
    }

    echo "\n";
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "AFGEBROKEN DOOR PHP-FOUT\n";
        echo 'Melding: ' . $error['message'] . "\n";
        echo 'Bestand: ' . basename($error['file']) . ' regel ' . $error['line'] . "\n";
    }
});

echo "DONDEBAILAR TEST-DIAGNOSE\n";
echo 'PHP-versie: ' . PHP_VERSION . "\n\n";

diagnose('Bestandscontrole', static function () use ($privateRoot): void {
    $required = [
        $privateRoot . '/config/config.local.php',
        $privateRoot . '/app/bootstrap.php',
        $privateRoot . '/app/Database.php',
        $privateRoot . '/app/agenda/agenda_data.php',
        $privateRoot . '/app/views/agenda/content.php',
    ];

    foreach ($required as $file) {
        if (!is_file($file)) {
            throw new RuntimeException('Bestand ontbreekt: ' . str_replace($privateRoot . '/', '', $file));
        }
    }

    if (!is_dir($privateRoot . '/storage') || !is_writable($privateRoot . '/storage')) {
        throw new RuntimeException('Storage-map ontbreekt of is niet schrijfbaar.');
    }
});

diagnose('Configuratie laden', static function () use ($privateRoot): void {
    $config = require $privateRoot . '/config/config.local.php';
    if (!is_array($config)) {
        throw new RuntimeException('config.local.php geeft geen configuratie-array terug.');
    }
});

diagnose('Bootstrap laden', static function () use ($privateRoot): void {
    require_once $privateRoot . '/app/bootstrap.php';
    if (!class_exists('Database')) {
        throw new RuntimeException('De Database-klasse is niet geladen.');
    }
});

diagnose('Databaseverbinding', static function (): void {
    if (!class_exists('Database')) {
        throw new RuntimeException('Database-klasse ontbreekt; bootstrap is niet geslaagd.');
    }
    $db = Database::connection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database::connection() gaf geen PDO-verbinding terug.');
    }
    $db->query('SELECT 1')->fetchColumn();
});

diagnose('Agenda-data laden', static function () use ($privateRoot): void {
    require $privateRoot . '/app/agenda/agenda_data.php';
});

echo "EINDE DIAGNOSE\n";
