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
require_once $privateRoot . '/app/SmtpMailer.php';

$pageTitle = 'Wachtwoord vergeten - DONDEBAILAR';
$errorMessage = '';
$successMessage = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } elseif ($identifier === '' || strlen($identifier) > 190) {
        $errorMessage = 'Vul je gebruikersnaam of e-mailadres in.';
    } else {
        // Dezelfde bevestiging wordt getoond, ongeacht of het adres bestaat.
        $successMessage = 'Als er een actief account met deze gebruikersnaam of dit e-mailadres bestaat, ontvang je een e-mail met verdere instructies. Controleer ook je spammap.';

        try {
            $db = Database::connection();
            // Zelf aanmaken zodat wachtwoordherstel ook werkt wanneer de gebruiker niet kan inloggen om UPDATE uit te voeren.
            $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(190) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_password_reset_token_hash (token_hash),
                KEY idx_password_reset_email_created (email, created_at),
                KEY idx_password_reset_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $userQuery = $db->prepare(
                "SELECT email, GROUP_CONCAT(DISTINCT display_name ORDER BY display_name SEPARATOR ', ') AS usernames
                 FROM users
                 WHERE status = 'active'
                   AND (LOWER(email) = :email_identifier OR LOWER(display_name) = :username_identifier)
                 GROUP BY email
                 LIMIT 2"
            );
            $normalizedIdentifier = strtolower($identifier);
            $userQuery->execute([
                'email_identifier' => $normalizedIdentifier,
                'username_identifier' => $normalizedIdentifier,
            ]);
            $matchedUsers = $userQuery->fetchAll(PDO::FETCH_ASSOC);
            // De invoer moet naar precies één e-mailadres verwijzen.
            // Bij meerdere accounts op hetzelfde adres vermelden we de bijbehorende gebruikersnamen.
            $matchedUser = count($matchedUsers) === 1 ? $matchedUsers[0] : null;
            $email = $matchedUser ? strtolower((string) $matchedUser['email']) : '';
            $username = $matchedUser ? trim((string) ($matchedUser['usernames'] ?? '')) : '';
            $userExists = $email !== '';

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
                        $keyFile = $privateRoot . '/storage/mail-settings.key';
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
                        . "Gebruikersnaam: " . ($username !== '' ? $username : '(niet beschikbaar)') . "\n\n"
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

require $privateRoot . '/app/views/header.php';
?>

<section class="auth-card">
    <h1>Wachtwoord vergeten?</h1>
    <p>Vul je gebruikersnaam of e-mailadres van je DONDEBAILAR-account in. We sturen een link naar het e-mailadres dat bij je account hoort.</p>

    <?php if ($errorMessage !== ''): ?>
        <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
        <div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div>
    <?php endif; ?>

    <form method="post" action="/forgot-password.php" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
        <label for="identifier">Gebruikersnaam of e-mailadres</label>
        <input type="text" id="identifier" name="identifier" value="<?= escapeHtml($identifier) ?>"
               autocomplete="username" maxlength="190" required>
        <button type="submit" class="button button--primary">Herstellink versturen</button>
    </form>

    <p><a href="/login.php">Terug naar inloggen</a></p>
</section>

<?php require $privateRoot . '/app/views/footer.php'; ?>
