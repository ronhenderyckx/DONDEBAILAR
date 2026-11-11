<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';

$pageTitle = 'E-mailadres bevestigen - DONDEBAILAR';
$errorMessage = '';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$tokenValid = false;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $check = Database::connection()->prepare("SELECT t.user_id FROM user_email_verification_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = :token_hash AND t.used_at IS NULL AND t.expires_at > NOW() AND u.status = 'pending_email' LIMIT 1");
        $check->execute(['token_hash' => hash('sha256', $token)]);
        $tokenValid = (bool) $check->fetchColumn();
    } catch (Throwable $exception) {
        error_log('DONDEBAILAR e-mailbevestiging controleren mislukt: ' . $exception->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } elseif (!$tokenValid) {
        $errorMessage = 'Deze bevestigingslink is ongeldig, verlopen of al gebruikt. Registreer opnieuw of neem contact op met de beheerder.';
    } else {
        $db = Database::connection();
        try {
            $db->beginTransaction();
            $lock = $db->prepare("SELECT t.id, t.user_id FROM user_email_verification_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = :token_hash AND t.used_at IS NULL AND t.expires_at > NOW() AND u.status = 'pending_email' LIMIT 1 FOR UPDATE");
            $lock->execute(['token_hash' => hash('sha256', $token)]);
            $verification = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$verification) {
                $db->rollBack();
                $tokenValid = false;
                $errorMessage = 'Deze bevestigingslink is ongeldig, verlopen of al gebruikt.';
            } else {
                $activate = $db->prepare("UPDATE users SET status = 'active', email_verified_at = NOW() WHERE id = :user_id AND status = 'pending_email'");
                $activate->execute(['user_id' => (int) $verification['user_id']]);
                if ($activate->rowCount() !== 1) throw new RuntimeException('Het account kon niet worden geactiveerd.');
                $consume = $db->prepare("UPDATE user_email_verification_tokens SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL");
                $consume->execute(['user_id' => (int) $verification['user_id']]);
                $db->commit();
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $verification['user_id'];
                unset($_SESSION['csrf_token']);
                header('Location: /?registration=verified');
                exit;
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('DONDEBAILAR e-mailbevestiging activeren mislukt: ' . $exception->getMessage());
            $errorMessage = 'Je account kon niet worden geactiveerd. Probeer het later opnieuw.';
        }
    }
}
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="auth-card">
    <h1>E-mailadres bevestigen</h1>
    <?php if ($errorMessage !== ''): ?><div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
    <?php if ($tokenValid): ?>
        <p>Bevestig je e-mailadres. Daarna word je meteen ingelogd bij DONDEBAILAR.</p>
        <form method="post" action="/verify-email.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <input type="hidden" name="token" value="<?= escapeHtml($token) ?>">
            <button type="submit" class="button button--primary">E-mailadres bevestigen en inloggen</button>
        </form>
    <?php elseif ($errorMessage === ''): ?>
        <p>Deze bevestigingslink is ongeldig, verlopen of al gebruikt.</p>
        <p><a href="/register.php">Opnieuw registreren</a></p>
    <?php endif; ?>
</section>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
