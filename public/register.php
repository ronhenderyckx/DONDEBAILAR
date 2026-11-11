<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/SmtpMailer.php';

$pageTitle = 'Account registreren - DONDEBAILAR';
$errorMessage = '';
$successMessage = '';
$displayName = '';
$email = '';

if (currentUser() !== null) {
    header('Location: /');
    exit;
}

function registrationSmtpPassword(array $settings): string
{
    $encrypted = (string) ($settings['smtp_password_encrypted'] ?? '');
    if ($encrypted === '') return '';
    if (!extension_loaded('sodium')) throw new RuntimeException('De PHP-extensie sodium ontbreekt.');
    $packed = base64_decode($encrypted, true);
    $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if (!is_string($packed) || strlen($packed) <= $nonceLength) throw new RuntimeException('Het opgeslagen SMTP-wachtwoord is ongeldig.');
    $keyFile = dirname(__DIR__) . '/storage/mail-settings.key';
    $key = is_file($keyFile) ? file_get_contents($keyFile) : false;
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('De SMTP-versleutelingssleutel ontbreekt.');
    $password = sodium_crypto_secretbox_open(substr($packed, $nonceLength), substr($packed, 0, $nonceLength), $key);
    if (!is_string($password)) throw new RuntimeException('Het SMTP-wachtwoord kon niet worden ontsleuteld.');
    return $password;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } elseif ($displayName === '' || mb_strlen($displayName) > 120) {
        $errorMessage = 'Vul een naam in van maximaal 120 tekens.';
    } elseif (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Vul een geldig e-mailadres in.';
    } elseif (strlen($password) < 12 || strlen($password) > 4096) {
        $errorMessage = 'Kies een wachtwoord van minstens 12 tekens.';
    } elseif (!hash_equals($password, $passwordConfirm)) {
        $errorMessage = 'De twee wachtwoorden zijn niet hetzelfde.';
    } else {
        $db = Database::connection();
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS user_email_verification_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_email_verification_hash (token_hash),
                KEY idx_user_email_verification_user (user_id),
                KEY idx_user_email_verification_expiry (expires_at),
                CONSTRAINT fk_user_email_verification_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $exists = $db->prepare("SELECT id FROM users WHERE LOWER(email) = :email LIMIT 1");
            $exists->execute(['email' => $email]);
            if ($exists->fetchColumn()) throw new DomainException('Er bestaat al een account met dit e-mailadres. Gebruik inloggen of wachtwoord vergeten.');

            $settings = $db->query("SELECT smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_encrypted, from_email, from_name FROM platform_mail_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
            if (!$settings) throw new RuntimeException('De e-mailinstellingen zijn nog niet geconfigureerd. Neem contact op met de beheerder.');

            $baseDomain = strtolower((string) ($GLOBALS['config']['app']['base_domain'] ?? 'dondebailar.eu'));
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $host = preg_replace('/:\\d+$/', '', $host) ?? '';
            if (!preg_match('/^[a-z0-9.-]+$/', $host) || !($host === $baseDomain || str_ends_with($host, '.' . $baseDomain))) $host = $baseDomain;

            $db->beginTransaction();
            $insert = $db->prepare("INSERT INTO users (email, password_hash, display_name, preferred_language, platform_role, status) VALUES (:email, :password_hash, :display_name, 'nl', 'user', 'pending_email')");
            $insert->execute(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'display_name' => $displayName]);
            $userId = (int) $db->lastInsertId();
            $rawToken = bin2hex(random_bytes(32));
            $tokenInsert = $db->prepare("INSERT INTO user_email_verification_tokens (user_id, token_hash, expires_at) VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
            $tokenInsert->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $rawToken)]);

            $verifyUrl = 'https://' . $host . '/verify-email.php?token=' . rawurlencode($rawToken);
            $body = "Hallo " . $displayName . ",\n\nBedankt voor je registratie bij DONDEBAILAR.\nBevestig je e-mailadres via deze link (24 uur geldig):\n" . $verifyUrl . "\n\nNa de bevestiging word je automatisch ingelogd.\n\nHeb je dit account niet aangevraagd? Dan kun je deze e-mail negeren.\n";
            dondebailarSendSmtpMail($settings, registrationSmtpPassword($settings), $email, 'DONDEBAILAR - bevestig je e-mailadres', $body);
            $db->commit();
            $successMessage = 'Je registratie is bijna klaar. We hebben een bevestigingsmail gestuurd. Klik op de link in die e-mail om je e-mailadres te bevestigen en automatisch in te loggen.';
            $displayName = '';
            $email = '';
        } catch (DomainException $exception) {
            if ($db->inTransaction()) $db->rollBack();
            $errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('DONDEBAILAR registratie mislukt: ' . $exception->getMessage());
            $errorMessage = 'De registratie-e-mail kon niet worden verstuurd. Controleer je gegevens of probeer het later opnieuw. Als je al een account hebt aangemaakt, neem dan contact op met de beheerder.';
        }
    }
}

require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="auth-card">
    <h1>Nieuw account</h1>
    <p>Maak een DONDEBAILAR-account aan. Na bevestiging van je e-mailadres word je automatisch ingelogd.</p>
    <?php if ($errorMessage !== ''): ?><div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
    <?php if ($successMessage !== ''): ?><div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div><?php endif; ?>
    <?php if ($successMessage === ''): ?>
    <form method="post" action="/register.php" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
        <label for="display_name">Naam</label>
        <input type="text" id="display_name" name="display_name" value="<?= escapeHtml($displayName) ?>" autocomplete="name" maxlength="120" required>
        <label for="email">E-mailadres</label>
        <input type="email" id="email" name="email" value="<?= escapeHtml($email) ?>" autocomplete="email" maxlength="190" required>
        <label for="password">Wachtwoord (minstens 12 tekens)</label>
        <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
        <label for="password_confirm">Wachtwoord herhalen</label>
        <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" minlength="12" maxlength="4096" required>
        <button type="submit" class="button button--primary">Registreren</button>
    </form>
    <?php endif; ?>
    <p><a href="/login.php">Terug naar inloggen</a></p>
</section>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
