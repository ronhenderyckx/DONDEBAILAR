<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = 'Instellingen - DONDEBAILAR';
$errorMessage = '';
$successMessage = '';

$projectRoot = dirname(__DIR__, 2);
$logoSettingFile = $projectRoot . '/storage/platform-logo-path.txt';
$logoPath = is_file($logoSettingFile) ? trim((string) file_get_contents($logoSettingFile)) : '';

if ($logoPath !== '' && !preg_match('#^/uploads/platform/[a-zA-Z0-9._-]+$#', $logoPath)) {
    $logoPath = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } elseif (isset($_POST['remove_logo'])) {
        if ($logoPath !== '') {
            $oldFile = $projectRoot . '/public' . $logoPath;
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
        if (is_file($logoSettingFile)) {
            @unlink($logoSettingFile);
        }
        $logoPath = '';
        $successMessage = 'Het platformlogo is verwijderd.';
    } elseif (isset($_FILES['platform_logo']) && (int) ($_FILES['platform_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload = $_FILES['platform_logo'];
        if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = 'Het uploaden is mislukt. Probeer het opnieuw.';
        } elseif ((int) $upload['size'] > 5 * 1024 * 1024) {
            $errorMessage = 'Het logo mag maximaal 5 MB groot zijn.';
        } elseif (!is_uploaded_file($upload['tmp_name'])) {
            $errorMessage = 'Ongeldig uploadbestand.';
        } else {
            $imageInfo = @getimagesize($upload['tmp_name']);
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($upload['tmp_name']);
            $extensions = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];

            if (!$imageInfo || !isset($extensions[$mime])) {
                $errorMessage = 'Gebruik een PNG-, JPG- of WEBP-afbeelding.';
            } elseif ($imageInfo[0] > 6000 || $imageInfo[1] > 6000) {
                $errorMessage = 'Het logo mag maximaal 6000 × 6000 pixels zijn.';
            } else {
                $uploadDirectory = $projectRoot . '/public/uploads/platform';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    $errorMessage = 'De uploadmap kon niet worden aangemaakt.';
                } else {
                    $filename = 'logo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
                    $destination = $uploadDirectory . '/' . $filename;

                    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                        $errorMessage = 'Het logo kon niet worden opgeslagen.';
                    } else {
                        if ($logoPath !== '') {
                            $oldFile = $projectRoot . '/public' . $logoPath;
                            if (is_file($oldFile)) {
                                @unlink($oldFile);
                            }
                        }
                        $logoPath = '/uploads/platform/' . $filename;
                        if (file_put_contents($logoSettingFile, $logoPath . PHP_EOL, LOCK_EX) === false) {
                            @unlink($destination);
                            $logoPath = '';
                            $errorMessage = 'Het logo is opgeslagen, maar de instelling kon niet worden bijgewerkt. Controleer de schrijfrechten van storage.';
                        } else {
                            $successMessage = 'Het DONDEBAILAR-logo is bijgewerkt.';
                        }
                    }
                }
            }
        }
    } else {
        $errorMessage = 'Kies eerst een logo om te uploaden.';
    }
}

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Instellingen</h1>
            <p>Beheer de algemene instellingen en huisstijl van DONDEBAILAR.</p>
        </div>
        <a class="button" href="/admin/">Terug naar platformbeheer</a>
    </div>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Platformlogo</h2>
                <p>Dit logo wordt gebruikt als standaardlogo wanneer er geen regiologo is ingesteld.</p>
            </div>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div>
        <?php endif; ?>

        <?php if ($logoPath !== ''): ?>
            <p><img src="<?= escapeHtml($logoPath) ?>" alt="DONDEBAILAR-logo" style="max-width:280px;max-height:140px;object-fit:contain"></p>
        <?php else: ?>
            <p>Er is nog geen eigen platformlogo ingesteld. DONDEBAILAR gebruikt het standaardbeeldmerk.</p>
        <?php endif; ?>

        <form method="post" action="/admin/settings.php" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="platform_logo">Logo uploaden (PNG, JPG of WEBP; maximaal 5 MB)</label>
            <input type="file" id="platform_logo" name="platform_logo" accept="image/png,image/jpeg,image/webp" required>
            <div class="form-actions">
                <button type="submit" class="button button--primary">Logo opslaan</button>
            </div>
        </form>

        <?php if ($logoPath !== ''): ?>
            <form method="post" action="/admin/settings.php" onsubmit="return confirm('Het platformlogo verwijderen?');">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <button type="submit" class="button" name="remove_logo" value="1">Logo verwijderen</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>E-mailinstellingen</h2>
                <p>SMTP-server en afzender voor systeemmails, zoals wachtwoordherstel.</p>
            </div>
            <a class="button button--primary" href="/admin/mail-settings.php">E-mailinstellingen openen</a>
        </div>
    </section>
</section>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
