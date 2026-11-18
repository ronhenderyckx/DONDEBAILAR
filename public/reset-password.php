<?php
declare(strict_types=1);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';
if ($host !== 'test.dondebailar.eu') {
    http_response_code(404);
    exit('Deze pagina is niet beschikbaar op deze website.');
}
$privateRoot = dirname(__DIR__) . '/private/DONDEBAILAR-TEST';
if (!is_file($privateRoot . '/app/bootstrap.php')) {
    http_response_code(503);
    exit('De private applicatiebestanden zijn niet beschikbaar.');
}

require_once $privateRoot . '/app/bootstrap.php';
require_once $privateRoot . '/app/Security.php';

$pageTitle = 'Nieuw wachtwoord instellen - DONDEBAILAR';
$errorMessage = '';
$successMessage = '';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenValid = false;
$tokenEmail = '';

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $db = Database::connection();
        $tableCheck = $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_reset_tokens'"
        );
        if ((int) $tableCheck->fetchColumn() === 1) {
            $check = $db->prepare(
                "SELECT email FROM password_reset_tokens
                 WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
                 LIMIT 1"
            );
            $check->execute(['token_hash' => hash('sha256', $token)]);
            $tokenEmail = (string) ($check->fetchColumn() ?: '');
            $tokenValid = $tokenEmail !== '';
        }
    } catch (Throwable $exception) {
        error_log('DONDEBAILAR wachtwoordherstel controleren mislukt: ' . $exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } elseif (!$tokenValid) {
        $errorMessage = 'Deze herstellink is ongeldig, verlopen of al gebruikt. Vraag een nieuwe link aan.';
    } elseif (strlen($password) < 12) {
        $errorMessage = 'Kies een wachtwoord van minstens 12 tekens.';
    } elseif (strlen($password) > 4096) {
        $errorMessage = 'Het wachtwoord is te lang.';
    } elseif (!hash_equals($password, $passwordConfirm)) {
        $errorMessage = 'De twee wachtwoorden zijn niet hetzelfde.';
    } else {
        try {
            $db->beginTransaction();
            $lock = $db->prepare(
                "SELECT id, email FROM password_reset_tokens
                 WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
                 LIMIT 1 FOR UPDATE"
            );
            $lock->execute(['token_hash' => hash('sha256', $token)]);
            $reset = $lock->fetch();

            if (!$reset) {
                $db->rollBack();
                $tokenValid = false;
                $errorMessage = 'Deze herstellink is ongeldig, verlopen of al gebruikt. Vraag een nieuwe link aan.';
            } else {
                $updateUsers = $db->prepare(
                    "UPDATE users SET password_hash = :password_hash
                     WHERE LOWER(email) = :email AND status = 'active'"
                );
                $updateUsers->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'email' => strtolower((string) $reset['email']),
                ]);

                if ($updateUsers->rowCount() < 1) {
                    $db->rollBack();
                    $tokenValid = false;
                    $errorMessage = 'Het account kon niet worden bijgewerkt. Vraag een nieuwe herstellink aan.';
                } else {
                    $consume = $db->prepare(
                        "UPDATE password_reset_tokens SET used_at = NOW()
                         WHERE email = :email AND used_at IS NULL"
                    );
                    $consume->execute(['email' => $reset['email']]);
                    $db->commit();
                    $tokenValid = false;
                    $successMessage = 'Je wachtwoord is gewijzigd. Je kunt nu inloggen met je nieuwe wachtwoord.';
                }
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('DONDEBAILAR wachtwoordherstel opslaan mislukt: ' . $exception->getMessage());
            $errorMessage = 'Het wachtwoord kon niet worden gewijzigd. Probeer het later opnieuw.';
        }
    }
}

require $privateRoot . '/app/views/header.php';
?>

<section class="auth-card">
    <h1>Nieuw wachtwoord instellen</h1>

    <?php if ($errorMessage !== ''): ?>
        <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
        <div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <p><a class="button button--primary" href="/login.php">Naar inloggen</a></p>
    <?php elseif (!$tokenValid): ?>
        <?php if ($errorMessage === ''): ?>
            <p>Deze herstellink is ongeldig, verlopen of al gebruikt. Vraag een nieuwe link aan.</p>
        <?php endif; ?>
        <p><a href="/forgot-password.php">Nieuwe herstellink aanvragen</a></p>
    <?php else: ?>
        <p>Kies een nieuw wachtwoord van minstens 12 tekens.</p>
        <form method="post" action="/reset-password.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <input type="hidden" name="token" value="<?= escapeHtml($token) ?>">
            <label for="password">Nieuw wachtwoord</label>
            <input type="password" id="password" name="password" autocomplete="new-password"
                   minlength="12" maxlength="4096" required>
            <label for="password_confirm">Nieuw wachtwoord herhalen</label>
            <input type="password" id="password_confirm" name="password_confirm"
                   autocomplete="new-password" minlength="12" maxlength="4096" required>
            <button type="submit" class="button button--primary">Wachtwoord opslaan</button>
        </form>
    <?php endif; ?>
</section>

<?php require $privateRoot . '/app/views/footer.php'; ?>
