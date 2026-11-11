<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/SmtpMailer.php';

$pageTitle = 'Wachtwoord vergeten - DONDEBAILAR';
$errorMessage = '';
$successMessage = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errorMessage = 'Vul een geldig e-mailadres in.';
    } else {
        // Dezelfde bevestiging wordt getoond, ongeacht of het adres bestaat.
        $successMessage = 'Als er een actief account met dit e-mailadres bestaat, ontvang je een e-mail met verdere instructies. Controleer ook je spammap.';

        try {
            $db = Database::connection();
            $tableCheck = $db->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_reset_tokens'"
            );
            if ((int) $tableCheck->fetchColumn() !== 1) {
                throw new RuntimeException('De database-upgrade voor wachtwoordherstel is nog niet uitgevoerd.');
            }

            $userQuery = $db->prepare(
                "SELECT id FROM users WHERE LOWER(email) = :email AND status = 'active' LIMIT 1"
            );
            $userQuery->execute(['email' => $email]);
            $userExists = (bool) $userQuery->fetchColumn();

            if ($userExists) {
                // Beperk herhaalde aanvragen en maak oudere links ongeldig.
                $recent = $db->prepare(
                    "SELECT COUNT(*) FROM password_reset_tokens
                     WHERE email = :email AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
                );
                $recent->execute(['email' => $email]);

                if ((int) $recent->fetchColumn() === 0) {
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $insert = $db->prepare(
                        "INSERT INTO password_reset_tokens (email, token_hash, expires_at)
                         VALUES (:email, :token_hash, DATE_ADD(NOW(), INTERVAL 60 MINUTE))"
                    );
                    $insert->execute(['email' => $email, 'token_hash' => $tokenHash]);

                    $settings = $db->query(
                        "SELECT smtp_host, smtp_port, smtp_encryption, smtp_username,
                                smtp_password_encrypted, from_email, from_name
                         FROM platform_mail_settings WHERE id = 1"
                    )->fetch();

                    if (!$settings) {
                        throw new RuntimeException('De SMTP-instellingen zijn nog niet geconfigureerd.');
                    }

                    $smtpPassword = '';
                    $encryptedPassword = (string) ($settings['smtp_password_encrypted'] ?? '');
                    if ($encryptedPassword !== '') {
                        if (!extension_loaded('sodium')) {
                            throw new RuntimeException('De PHP-extensie sodium is vereist voor de SMTP-instellingen.');
                        }
                        $packed = base64_decode($encryptedPassword, true);
                        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                        if (!is_string($packed) || strlen($packed) <= $nonceLength) {
                            throw new RuntimeException('Het opgeslagen SMTP-wachtwoord is ongeldig.');
                        }
                        $keyFile = dirname(__DIR__) . '/storage/mail-settings.key';
                        $key = is_file($keyFile) ? file_get_contents($keyFile) : false;
                        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                            throw new RuntimeException('De SMTP-versleutelingssleutel ontbreekt.');
                        }
                        $smtpPassword = sodium_crypto_secretbox_open(
                            substr($packed, $nonceLength),
                            substr($packed, 0, $nonceLength),
                            $key
                        );
                        if (!is_string($smtpPassword)) {
                            throw new RuntimeException('Het SMTP-wachtwoord kon niet worden ontsleuteld.');
                        }
                    }

                    $baseDomain = strtolower((string) ($GLOBALS['config']['app']['base_domain'] ?? 'dondebailar.eu'));
                    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
                    $requestHost = preg_replace('/:\\d+$/', '', $requestHost) ?? '';
                    if (
                        !preg_match('/^[a-z0-9.-]+$/', $requestHost)
                        || !($requestHost === $baseDomain || str_ends_with($requestHost, '.' . $baseDomain))
                    ) {
                        $requestHost = $baseDomain;
                    }
                    $resetUrl = 'https://' . $requestHost . '/reset-password.php?token=' . rawurlencode($rawToken);

                    $body = "Je hebt gevraagd om het wachtwoord van je DONDEBAILAR-account opnieuw in te stellen.\n\n"
                        . "Open deze beveiligde link om een nieuw wachtwoord te kiezen (geldig gedurende 60 minuten en eenmalig te gebruiken):\n"
                        . $resetUrl . "\n\n"
                        . "Heb je deze aanvraag niet gedaan? Dan kun je deze e-mail negeren. Je huidige wachtwoord blijft ongewijzigd.\n";
                    dondebailarSendSmtpMail(
                        $settings,
                        $smtpPassword,
                        $email,
                        'DONDEBAILAR - wachtwoord opnieuw instellen',
                        $body
                    );
                }
            }
        } catch (Throwable $exception) {
            error_log('DONDEBAILAR wachtwoordherstel aanvraag mislukt: ' . $exception->getMessage());
        }
    }
}

require dirname(__DIR__) . '/app/views/header.php';
?>

<section class="auth-card">
    <h1>Wachtwoord vergeten?</h1>
    <p>Vul het e-mailadres van je DONDEBAILAR-account in. We sturen je een link om een nieuw wachtwoord in te stellen.</p>

    <?php if ($errorMessage !== ''): ?>
        <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
        <div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div>
    <?php endif; ?>

    <form method="post" action="/forgot-password.php" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
        <label for="email">E-mailadres</label>
        <input type="email" id="email" name="email" value="<?= escapeHtml($email) ?>"
               autocomplete="email" maxlength="190" required>
        <button type="submit" class="button button--primary">Herstellink versturen</button>
    </form>

    <p><a href="/login.php">Terug naar inloggen</a></p>
</section>

<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
