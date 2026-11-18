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

$pageTitle = 'E-mailadres bevestigen - DONDEBAILAR';
$errorMessage = '';
$token = trim((string) ($_GET['token'] ?? ''));

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $errorMessage = 'Deze bevestigingslink is ongeldig of onvolledig.';
} else {
    $db = Database::connection();
    try {
        $db->beginTransaction();
        $lock = $db->prepare(
            "SELECT t.id, t.user_id
             FROM user_email_verification_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
               AND t.used_at IS NULL
               AND t.expires_at > NOW()
               AND u.status = 'pending_email'
             LIMIT 1 FOR UPDATE"
        );
        $lock->execute(['token_hash' => hash('sha256', $token)]);
        $verification = $lock->fetch(PDO::FETCH_ASSOC);

        if (!$verification) {
            $db->rollBack();
            $errorMessage = 'Deze bevestigingslink is ongeldig, verlopen of al gebruikt. Registreer opnieuw of neem contact op met de beheerder.';
        } else {
            $activate = $db->prepare(
                "UPDATE users SET status = 'active', email_verified_at = NOW()
                 WHERE id = :user_id AND status = 'pending_email'"
            );
            $activate->execute(['user_id' => (int) $verification['user_id']]);
            if ($activate->rowCount() !== 1) {
                throw new RuntimeException('Het account kon niet worden geactiveerd.');
            }

            $consume = $db->prepare(
                "UPDATE user_email_verification_tokens SET used_at = NOW()
                 WHERE user_id = :user_id AND used_at IS NULL"
            );
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

require $privateRoot . '/app/views/header.php';
?>
<section class="auth-card">
    <h1>E-mailadres bevestigen</h1>
    <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <p><a href="/register.php">Opnieuw registreren</a></p>
    <p><a href="/login.php">Naar inloggen</a></p>
</section>
<?php require $privateRoot . '/app/views/footer.php'; ?>
