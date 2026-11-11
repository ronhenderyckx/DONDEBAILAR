<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/ImageOptimization.php';

requirePlatformAdmin();

$pageTitle = 'Instellingen - DONDEBAILAR';
$errorMessage = '';
$successMessage = '';

$projectRoot = dirname(__DIR__, 2);
$logoSettingFile = $projectRoot . '/storage/platform-logo-path.txt';
$logoPath = is_file($logoSettingFile) ? trim((string) file_get_contents($logoSettingFile)) : '';

$agendaPageSizeFile = $projectRoot . '/storage/agenda-page-size.txt';
$agendaPageSize = 20;
if (is_file($agendaPageSizeFile)) {
    $storedPageSize = (int) trim((string) file_get_contents($agendaPageSizeFile));
    if ($storedPageSize >= 1 && $storedPageSize <= 100) {
        $agendaPageSize = $storedPageSize;
    }
}

if ($logoPath !== '' && !preg_match('#^/uploads/platform/[a-zA-Z0-9._-]+$#', $logoPath)) {
    $logoPath = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } elseif (isset($_POST['save_agenda_settings'])) {
        $requestedPageSize = filter_var($_POST['agenda_page_size'] ?? null, FILTER_VALIDATE_INT);
        if ($requestedPageSize === false || $requestedPageSize < 1 || $requestedPageSize > 100) {
            $errorMessage = 'Kies een aantal tussen 1 en 100.';
        } elseif (file_put_contents($agendaPageSizeFile, (string) $requestedPageSize . PHP_EOL, LOCK_EX) === false) {
            $errorMessage = 'De agenda-instelling kon niet worden opgeslagen. Controleer de schrijfrechten van storage.';
        } else {
            $agendaPageSize = $requestedPageSize;
            $successMessage = 'Het aantal evenementen per laadbeurt is opgeslagen.';
        }
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
        try {
            $newRelativePath = storeOptimizedLogoUpload(
                $_FILES['platform_logo'],
                'public/uploads/platform',
                800,
                5 * 1024 * 1024,
                'logo-'
            );
            if ($newRelativePath !== null) {
                $newLogoPath = '/' . substr($newRelativePath, strlen('public/'));
                if (file_put_contents($logoSettingFile, $newLogoPath . PHP_EOL, LOCK_EX) === false) {
                    $newFile = $projectRoot . '/' . $newRelativePath;
                    if (is_file($newFile)) {
                        @unlink($newFile);
                    }
                    $errorMessage = 'Het logo is geoptimaliseerd, maar de instelling kon niet worden bijgewerkt. Controleer de schrijfrechten van storage.';
                } else {
                    if ($logoPath !== '') {
                        $oldFile = $projectRoot . '/public' . $logoPath;
                        if (is_file($oldFile)) {
                            @unlink($oldFile);
                        }
                    }
                    $logoPath = $newLogoPath;
                    $successMessage = 'Het DONDEBAILAR-logo is bijgewerkt en geoptimaliseerd.';
                }
            }
        } catch (RuntimeException $exception) {
            $errorMessage = $exception->getMessage();
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

    <?php if ($errorMessage !== ''): ?>
        <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
        <div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div>
    <?php endif; ?>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Agendaweergave</h2>
                <p>Bepaal hoeveel evenementen tegelijk worden getoond. Bezoekers kunnen daarna steeds verder laden.</p>
            </div>
        </div>
        <form method="post" action="/admin/settings.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="agenda_page_size">Evenementen per keer (1–100)</label>
            <div class="agenda-page-size-control" style="display:flex;align-items:center;gap:8px;max-width:260px">
                <button type="button" class="button" id="agenda_page_size_down" aria-label="Aantal evenementen verlagen">−</button>
                <input type="number" id="agenda_page_size" name="agenda_page_size" min="1" max="100" step="1" value="<?= (int) $agendaPageSize ?>" required style="text-align:center;margin:0">
                <button type="button" class="button" id="agenda_page_size_up" aria-label="Aantal evenementen verhogen">+</button>
            </div>
            <script>
            (function () {
                const field = document.getElementById('agenda_page_size');
                const down = document.getElementById('agenda_page_size_down');
                const up = document.getElementById('agenda_page_size_up');
                if (!field || !down || !up) return;
                down.addEventListener('click', function () {
                    field.value = Math.max(1, (parseInt(field.value, 10) || 1) - 1);
                    field.dispatchEvent(new Event('change', {bubbles:true}));
                });
                up.addEventListener('click', function () {
                    field.value = Math.min(100, (parseInt(field.value, 10) || 0) + 1);
                    field.dispatchEvent(new Event('change', {bubbles:true}));
                });
            })();
            </script>
            <div class="form-actions">
                <button type="submit" class="button button--primary" name="save_agenda_settings" value="1">Agenda-instelling opslaan</button>
            </div>
        </form>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Platformlogo</h2>
                <p>Dit logo wordt gebruikt als standaardlogo wanneer er geen regiologo is ingesteld.</p>
            </div>
        </div>

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
