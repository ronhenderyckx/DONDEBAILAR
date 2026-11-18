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

try {
    require $privateRoot . '/app/agenda/agenda_data.php';
    require $privateRoot . '/app/views/agenda/content.php';
} catch (Throwable $error) {
    http_response_code(500);

    $logDirectory = $privateRoot . '/storage';
    if (is_dir($logDirectory) && is_writable($logDirectory)) {
        $logEntry = sprintf(
            "[%s] %s: %s in %s:%d\n",
            date('c'),
            get_class($error),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        );
        error_log($logEntry, 3, $logDirectory . '/dondebailar-test-error.log');
    }

    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><title>DONDEBAILAR - fout</title>';
    echo '<body style="font-family:Arial,sans-serif;max-width:850px;margin:50px auto;padding:0 20px">';
    echo '<h1>DONDEBAILAR kan niet worden geladen</h1>';
    if ($host === 'test.dondebailar.eu') {
        echo '<p><strong>Diagnose (alleen TEST):</strong></p><pre style="white-space:pre-wrap;background:#f3f3f3;padding:16px;border-radius:6px">';
        echo htmlspecialchars(
            get_class($error) . ': ' . $error->getMessage() . "\nBestand: " . $error->getFile() . "\nRegel: " . $error->getLine(),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        echo '</pre>';
        echo '<p>De fout is ook opgeslagen in de private opslagmap.</p>';
    } else {
        echo '<p>Er is een technische fout opgetreden. Probeer het later opnieuw.</p>';
    }
    echo '</body></html>';
}
