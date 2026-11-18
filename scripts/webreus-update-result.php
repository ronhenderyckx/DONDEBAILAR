<?php
declare(strict_types=1);

/**
 * DONDEBAILAR WebReus TEST result page.
 * Install once as test/update-result.php.
 * The runner is pinned; it reads the current update-commands.json on each run.
 */
if (strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== 'test.dondebailar.eu') {
    http_response_code(404);
    exit('Niet beschikbaar.');
}
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(403);
    exit('Gebruik HTTPS.');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Start de updater via de updatepagina.');
}
session_start();

function updatePage(string $title, string $message, bool $success = false): never
{
    $color = $success ? '#176b38' : '#9b2525';
    echo '<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<body style="font:16px system-ui,sans-serif;max-width:850px;margin:40px auto;padding:0 18px;color:#222">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<pre style="white-space:pre-wrap;background:#f4f4f4;padding:18px;border-left:5px solid ' . $color . '">';
    echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '</pre><p>DONDEBAILAR TEST-updater</p></body></html>';
    exit;
}

$expectedToken = (string) ($_SESSION['db_update_csrf'] ?? '');
$postedToken = '';
foreach (['csrf_token', 'db_update_csrf', 'token'] as $field) {
    if (isset($_POST[$field]) && is_string($_POST[$field])) {
        $postedToken = $_POST[$field];
        break;
    }
}
if ($expectedToken === '' || $postedToken === '' || !hash_equals($expectedToken, $postedToken)) {
    updatePage('Beveiligingscontrole mislukt', 'De beveiligingstoken ontbreekt of is ongeldig. Open de updater opnieuw en probeer nogmaals.');
}

$runnerUrl = 'https://raw.githubusercontent.com/ronhenderyckx/DONDEBAILAR/develop/scripts/webreus-update-runner.php';
$ch = curl_init($runnerUrl);
if ($ch === false) {
    updatePage('Updaterfout', 'De verbinding met GitHub kon niet worden gestart.');
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => ['Accept: text/plain'],
]);
$runnerCode = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);
if (!is_string($runnerCode) || $httpCode !== 200 || !str_contains($runnerCode, 'function dondebailar_webreus_run')) {
    updatePage('Updaterfout', 'Het uitvoerprogramma kon niet veilig van GitHub worden opgehaald. HTTP ' . $httpCode . ' ' . $curlError);
}

$tempFile = tempnam(sys_get_temp_dir(), 'db-update-');
if ($tempFile === false || file_put_contents($tempFile, $runnerCode) === false) {
    if (is_string($tempFile)) {
        @unlink($tempFile);
    }
    updatePage('Updaterfout', 'Het tijdelijke uitvoerbestand kon niet worden aangemaakt.');
}

try {
    require $tempFile;
    if (!function_exists('dondebailar_webreus_run')) {
        throw new RuntimeException('Het uitvoerprogramma bevat niet de verwachte startfunctie.');
    }
    $result = dondebailar_webreus_run(['host' => 'test.dondebailar.eu']);
    $lines = [];
    foreach (($result['results'] ?? []) as $line) {
        $lines[] = (string) $line;
    }
    updatePage(
        !empty($result['success']) ? 'Update uitgevoerd' : 'Update niet geslaagd',
        implode("\n", $lines),
        !empty($result['success'])
    );
} catch (Throwable $e) {
    updatePage('Update niet geslaagd', $e->getMessage());
} finally {
    @unlink($tempFile);
}
