<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/SmtpMailer.php';

$admin = requirePlatformAdmin();
$db = Database::connection();

$pageTitle = 'E-mailinstellingen - DONDEBAILAR';
$errorMessage = '';
$successMessage = '';

$db->exec(
    "CREATE TABLE IF NOT EXISTS platform_mail_settings (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        smtp_host VARCHAR(190) NOT NULL,
        smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 465,
        smtp_encryption ENUM('ssl', 'starttls', 'none') NOT NULL DEFAULT 'ssl',
        smtp_username VARCHAR(190) NOT NULL DEFAULT '',
        smtp_password_encrypted TEXT NULL,
        from_email VARCHAR(190) NOT NULL,
        from_name VARCHAR(190) NOT NULL DEFAULT 'DONDEBAILAR',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$settings = [
    'smtp_host' => 'webreus.email',
    'smtp_port' => '465',
    'smtp_encryption' => 'ssl',
    'smtp_username' => '',
    'from_email' => '',
    'from_name' => 'DONDEBAILAR',
    'has_password' => false,
];

$existing = $db->query(
    "SELECT smtp_host, smtp_port, smtp_encryption, smtp_username,
            smtp_password_encrypted, from_email, from_name
     FROM platform_mail_settings WHERE id = 1"
)->fetch();

if ($existing) {
    foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'from_email', 'from_name'] as $field) {
        $settings[$field] = (string) $existing[$field];
    }
    $settings['has_password'] = !empty($existing['smtp_password_encrypted']);
}

function mailSettingsEncryptionKey(): string
{
    $keyFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/mail-settings.key';
    $storageDirectory = dirname($keyFile);

    if (!is_dir($storageDirectory) || !is_writable($storageDirectory)) {
        throw new RuntimeException('De map storage moet schrijfbaar zijn om de SMTP-sleutel veilig op te slaan.');
    }

    if (!is_file($keyFile)) {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $written = file_put_contents($keyFile, $key, LOCK_EX);
        if ($written !== strlen($key)) {
            throw new RuntimeException('De versleutelingssleutel kon niet worden aangemaakt.');
        }
        @chmod($keyFile, 0600);
    }

    $key = file_get_contents($keyFile);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('De SMTP-versleutelingssleutel ontbreekt of is ongeldig. Bewaar storage/mail-settings.key bij back-ups.');
    }

    return $key;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    $testRecipient = trim((string) ($_POST['test_recipient'] ?? $admin['email'] ?? ''));

    if (!verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } elseif (!filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Vul een geldig e-mailadres in voor de testmail.';
    } elseif (!$existing) {
        $errorMessage = 'Sla eerst de SMTP-instellingen op voordat je een testmail verstuurt.';
    } else {
        try {
            $smtpPassword = '';
            $encryptedPassword = (string) ($existing['smtp_password_encrypted'] ?? '');
            if ($encryptedPassword !== '') {
                if (!extension_loaded('sodium')) {
                    throw new RuntimeException('De PHP-extensie sodium is vereist om het opgeslagen SMTP-wachtwoord te ontsleutelen.');
                }
                $packed = base64_decode($encryptedPassword, true);
                $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                if (!is_string($packed) || strlen($packed) <= $nonceLength) {
                    throw new RuntimeException('Het opgeslagen SMTP-wachtwoord is ongeldig.');
                }
                $smtpPassword = sodium_crypto_secretbox_open(
                    substr($packed, $nonceLength),
                    substr($packed, 0, $nonceLength),
                    mailSettingsEncryptionKey()
                );
                if (!is_string($smtpPassword)) {
                    throw new RuntimeException('Het SMTP-wachtwoord kon niet worden ontsleuteld. Controleer of storage/mail-settings.key nog aanwezig is.');
                }
            }

            dondebailarSendSmtpMail(
                $existing,
                $smtpPassword,
                $testRecipient,
                'DONDEBAILAR - test e-mail',
                "Dit is een testbericht van DONDEBAILAR.\n\nAls je dit bericht ontvangt, werken de SMTP-instellingen voor uitgaande e-mail."
            );
            $successMessage = 'De testmail is door de SMTP-server aanvaard voor verzending naar ' . $testRecipient . '. Controleer ook de spammap.';
        } catch (Throwable $exception) {
            error_log('DONDEBAILAR SMTP-test mislukt: ' . $exception->getMessage());
            $errorMessage = 'Testmail mislukt: ' . $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['send_test_email'])) {
    $posted = [
        'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
        'smtp_port' => trim((string) ($_POST['smtp_port'] ?? '')),
        'smtp_encryption' => trim((string) ($_POST['smtp_encryption'] ?? '')),
        'smtp_username' => trim((string) ($_POST['smtp_username'] ?? '')),
        'from_email' => trim((string) ($_POST['from_email'] ?? '')),
        'from_name' => trim((string) ($_POST['from_name'] ?? '')),
    ];
    $newPassword = (string) ($_POST['smtp_password'] ?? '');
    $clearPassword = isset($_POST['clear_password']);

    $settings = array_merge($settings, $posted);

    if (!verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } elseif ($posted['smtp_host'] === '' || strlen($posted['smtp_host']) > 190) {
        $errorMessage = 'Vul een geldige SMTP-server in.';
    } elseif (!ctype_digit($posted['smtp_port']) || (int) $posted['smtp_port'] < 1 || (int) $posted['smtp_port'] > 65535) {
        $errorMessage = 'Vul een geldige SMTP-poort in.';
    } elseif (!in_array($posted['smtp_encryption'], ['ssl', 'starttls', 'none'], true)) {
        $errorMessage = 'Kies een geldige verbindingsbeveiliging.';
    } elseif (!filter_var($posted['from_email'], FILTER_VALIDATE_EMAIL)) {
        $errorMessage = 'Vul een geldig afzender-e-mailadres in.';
    } elseif ($posted['from_name'] === '' || strlen($posted['from_name']) > 190) {
        $errorMessage = 'Vul een geldige afzendernaam in.';
    } elseif (strlen($posted['smtp_username']) > 190 || strlen($newPassword) > 2000) {
        $errorMessage = 'Een van de ingevulde waarden is te lang.';
    } else {
        try {
            $currentCipher = $existing['smtp_password_encrypted'] ?? null;
            if ($clearPassword) {
                $currentCipher = null;
            } elseif ($newPassword !== '') {
                if (!extension_loaded('sodium')) {
                    throw new RuntimeException('De PHP-extensie sodium is vereist om het SMTP-wachtwoord versleuteld op te slaan.');
                }
                $key = mailSettingsEncryptionKey();
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher = sodium_crypto_secretbox($newPassword, $nonce, $key);
                $currentCipher = base64_encode($nonce . $cipher);
            }

            $save = $db->prepare(
                "INSERT INTO platform_mail_settings
                    (id, smtp_host, smtp_port, smtp_encryption, smtp_username,
                     smtp_password_encrypted, from_email, from_name, updated_by)
                 VALUES
                    (1, :host, :port, :encryption, :username, :password,
                     :from_email, :from_name, :updated_by)
                 ON DUPLICATE KEY UPDATE
                    smtp_host = VALUES(smtp_host),
                    smtp_port = VALUES(smtp_port),
                    smtp_encryption = VALUES(smtp_encryption),
                    smtp_username = VALUES(smtp_username),
                    smtp_password_encrypted = VALUES(smtp_password_encrypted),
                    from_email = VALUES(from_email),
                    from_name = VALUES(from_name),
                    updated_by = VALUES(updated_by)"
            );
            $save->execute([
                'host' => $posted['smtp_host'],
                'port' => (int) $posted['smtp_port'],
                'encryption' => $posted['smtp_encryption'],
                'username' => $posted['smtp_username'],
                'password' => $currentCipher,
                'from_email' => $posted['from_email'],
                'from_name' => $posted['from_name'],
                'updated_by' => (int) $admin['id'],
            ]);

            header('Location: /admin/mail-settings.php?saved=1');
            exit;
        } catch (Throwable $exception) {
            error_log('DONDEBAILAR mailinstellingen: ' . $exception->getMessage());
            $errorMessage = $exception->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $successMessage = 'De e-mailinstellingen zijn opgeslagen.';
}

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>E-mailinstellingen</h1>
            <p>Stel de centrale mailserver in die DONDEBAILAR gebruikt voor onder andere wachtwoordherstel.</p>
        </div>
        <a class="button" href="/admin/">Terug naar platformbeheer</a>
    </div>

    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/mail-settings.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <h2>SMTP-server</h2>
            <label for="smtp_host">SMTP-server</label>
            <input type="text" id="smtp_host" name="smtp_host" maxlength="190"
                   value="<?= escapeHtml($settings['smtp_host']) ?>" required>

            <label for="smtp_port">Poort</label>
            <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
                   value="<?= escapeHtml($settings['smtp_port']) ?>" required>

            <label for="smtp_encryption">Beveiliging</label>
            <select id="smtp_encryption" name="smtp_encryption">
                <option value="ssl" <?= $settings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS (meestal poort 465)</option>
                <option value="starttls" <?= $settings['smtp_encryption'] === 'starttls' ? 'selected' : '' ?>>STARTTLS (meestal poort 587)</option>
                <option value="none" <?= $settings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>Geen (niet aanbevolen)</option>
            </select>

            <label for="smtp_username">SMTP-gebruikersnaam</label>
            <input type="text" id="smtp_username" name="smtp_username" maxlength="190"
                   autocomplete="username" value="<?= escapeHtml($settings['smtp_username']) ?>">

            <label for="smtp_password">SMTP-wachtwoord</label>
            <input type="password" id="smtp_password" name="smtp_password"
                   autocomplete="new-password" placeholder="<?= $settings['has_password'] ? 'Laat leeg om het huidige wachtwoord te behouden' : 'Vul SMTP-wachtwoord in' ?>">
            <p><?= $settings['has_password'] ? 'Er is een SMTP-wachtwoord opgeslagen. Het wordt niet opnieuw getoond.' : 'Nog geen SMTP-wachtwoord opgeslagen.' ?></p>
            <?php if ($settings['has_password']): ?>
                <label><input type="checkbox" name="clear_password" value="1"> Opgeslagen SMTP-wachtwoord wissen</label>
            <?php endif; ?>

            <h2>Afzender</h2>
            <label for="from_email">Afzender e-mailadres</label>
            <input type="email" id="from_email" name="from_email" maxlength="190"
                   value="<?= escapeHtml($settings['from_email']) ?>" required>

            <label for="from_name">Afzendernaam</label>
            <input type="text" id="from_name" name="from_name" maxlength="190"
                   value="<?= escapeHtml($settings['from_name']) ?>" required>

            <div class="form-actions">
                <button type="submit" class="button button--primary">Instellingen opslaan</button>
                <a class="button" href="/admin/">Annuleren</a>
            </div>
        </form>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Test e-mail versturen</h2>
                <p>Verstuur een testbericht via de opgeslagen SMTP-instellingen.</p>
            </div>
        </div>
        <form method="post" action="/admin/mail-settings.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="test_recipient">Testmail versturen naar</label>
            <input
                type="email"
                id="test_recipient"
                name="test_recipient"
                value="<?= escapeHtml($admin['email'] ?? '') ?>"
                maxlength="190"
                required
            >
            <div class="form-actions">
                <button
                    type="submit"
                    class="button button--primary"
                    name="send_test_email"
                    value="1"
                    <?= !$existing ? 'disabled' : '' ?>
                >Testmail versturen</button>
            </div>
            <?php if (!$existing): ?>
                <p>Sla eerst de SMTP-instellingen op. Daarna kun je een testmail versturen.</p>
            <?php endif; ?>
        </form>
    </section>

    <p>Het SMTP-wachtwoord wordt versleuteld opgeslagen. Bewaar ook het bestand <code>storage/mail-settings.key</code> in een beveiligde back-up; zonder dat bestand kan het wachtwoord niet meer ontsleuteld worden.</p>
</section>

<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
