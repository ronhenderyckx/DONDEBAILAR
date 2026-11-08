<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    $databaseStatus = 'Nog niet gecontroleerd';
    try {
        Database::connection()->query('SELECT 1');
        $databaseStatus = 'Verbonden';
    } catch (Throwable $e) {
        $databaseStatus = 'Niet verbonden — controleer de database-instellingen en voer de migratie uit.';
        error_log($e->getMessage());
    }
} catch (Throwable $e) {
    http_response_code(503);
    $databaseStatus = 'Configuratie ontbreekt';
    $setupMessage = $e->getMessage();
}

$appName = htmlspecialchars((string) ($config['app']['name'] ?? 'DONDEBAILAR'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= $appName ?> — Technische start</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, Segoe UI, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f3f5f7; color: #222; padding: 24px; }
        main { width: min(100%, 680px); background: #fff; border-radius: 18px; padding: clamp(24px, 5vw, 42px); box-shadow: 0 12px 40px #17202a12; }
        .mark { display: inline-grid; place-items: center; width: 48px; height: 48px; border-radius: 14px; background: #e23a2c; color: #fff; font-weight: 800; }
        h1 { margin: 20px 0 8px; font-size: clamp(28px, 5vw, 40px); }
        p { line-height: 1.6; color: #555; }
        dl { margin: 28px 0 0; border-top: 1px solid #e7e9ec; }
        dl div { display: flex; justify-content: space-between; gap: 16px; padding: 14px 0; border-bottom: 1px solid #e7e9ec; }
        dt { color: #666; } dd { margin: 0; font-weight: 650; text-align: right; }
        code { background: #f1f2f4; padding: 2px 6px; border-radius: 5px; }
        .note { margin-top: 24px; padding: 14px 16px; border-left: 4px solid #e23a2c; background: #fff6f4; border-radius: 8px; }
    </style>
</head>
<body>
<main>
    <span class="mark">DB</span>
    <h1>DONDEBAILAR</h1>
    <p>De technische basis is gestart. Dit is de eerste interne controlepagina; de publieke regionale agenda volgt in een latere ontwikkelfase.</p>
    <dl>
        <div><dt>Applicatie</dt><dd>Basis gestart</dd></div>
        <div><dt>PHP</dt><dd><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>Database</dt><dd><?= htmlspecialchars($databaseStatus, ENT_QUOTES, 'UTF-8') ?></dd></div>
        <div><dt>Standaardtijdzone</dt><dd>Europe/Madrid</dd></div>
        <div><dt>Talen voorbereid</dt><dd>NL · EN · ES · FR</dd></div>
    </dl>
    <?php if (isset($setupMessage)): ?>
        <div class="note"><strong>Eerste configuratiestap</strong><p><?= htmlspecialchars($setupMessage, ENT_QUOTES, 'UTF-8') ?></p></div>
    <?php endif; ?>
</main>
</body>
</html>
