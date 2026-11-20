<?php
declare(strict_types=1);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';
if ($host !== 'test.dondebailar.eu') {
    http_response_code(404);
    exit('Deze instellingenpagina is alleen beschikbaar op de TEST-omgeving.');
}

require_once dirname(__DIR__) . '/_private-root.php';
$privateRoot = DONDEBAILAR_PRIVATE_ROOT;

require_once $privateRoot . '/app/bootstrap.php';
require_once $privateRoot . '/app/Authorization.php';
require_once $privateRoot . '/app/Security.php';
require_once $privateRoot . '/app/ImageOptimization.php';

requirePlatformAdmin();

$pageTitle = 'Instellingen - DONDEBAILAR';
$platformAdminPage = true;

// Geef het menu ook op deze platformpagina een actieve regio-context.
// Zo blijven Platformbeheer en Regiobeheer afzonderlijk zichtbaar.
$regionQuery = Database::connection()->prepare(
    "SELECT r.*, ra.*
     FROM regions r
     LEFT JOIN region_appearance ra ON ra.region_id = r.id
     INNER JOIN user_region_roles urr ON urr.region_id = r.id
     WHERE urr.user_id = :user_id
       AND urr.role IN ('region_admin', 'editor')
       AND r.is_active = 1
     ORDER BY CASE WHEN urr.role = 'region_admin' THEN 0 ELSE 1 END, r.name
     LIMIT 1"
);
$regionQuery->execute(['user_id' => (int) currentUser()['id']]);
$region = $regionQuery->fetch() ?: null;

$errorMessage = '';
$successMessage = '';

$projectRoot = $privateRoot;
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

$maxEventDurationFile = $projectRoot . '/storage/platform-max-event-duration-hours.txt';
$maxEventDurationHours = 10;
if (is_file($maxEventDurationFile)) {
    $storedMaxEventDuration = (int) trim((string) file_get_contents($maxEventDurationFile));
    if ($storedMaxEventDuration >= 1 && $storedMaxEventDuration <= 168) {
        $maxEventDurationHours = $storedMaxEventDuration;
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
        } elseif (file_put_contents($agendaPageSizeFile, (string) $requestedPageSize . PHP_EOL, LOCK_EX) === false
) {
            $errorMessage = 'De agenda-instellingen konden niet worden opgeslagen. Controleer de schrijfrechten van storage.';
        } else {
            $agendaPageSize = $requestedPageSize;
            $successMessage = 'De agenda-instellingen zijn opgeslagen.';
        }
    } elseif (isset($_POST['save_event_duration'])) {
        $requestedMaxEventDuration = filter_var($_POST['max_event_duration_hours'] ?? null, FILTER_VALIDATE_INT);
        if ($requestedMaxEventDuration === false || $requestedMaxEventDuration < 1 || $requestedMaxEventDuration > 168) {
            $errorMessage = 'Kies een maximale evenementduur tussen 1 en 168 uur.';
        } elseif (file_put_contents($maxEventDurationFile, (string) $requestedMaxEventDuration . PHP_EOL, LOCK_EX) === false) {
            $errorMessage = 'De maximale evenementduur kon niet worden opgeslagen. Controleer de schrijfrechten van storage.';
        } else {
            $maxEventDurationHours = $requestedMaxEventDuration;
            $successMessage = 'De maximale evenementduur is opgeslagen.';
        }
    } elseif (isset($_POST['remove_logo'])) {
        if ($logoPath !== '') {
            $oldFile = dirname(__DIR__) . $logoPath;
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
                'uploads/platform',
                800,
                5 * 1024 * 1024,
                'logo-',
                dirname(__DIR__)
            );
            if ($newRelativePath !== null) {
                $newLogoPath = '/' . $newRelativePath;
                if (file_put_contents($logoSettingFile, $newLogoPath . PHP_EOL, LOCK_EX) === false) {
                    $newFile = dirname(__DIR__) . '/' . $newRelativePath;
                    if (is_file($newFile)) {
                        @unlink($newFile);
                    }
                    $errorMessage = 'Het logo is geoptimaliseerd, maar de instelling kon niet worden bijgewerkt. Controleer de schrijfrechten van storage.';
                } else {
                    if ($logoPath !== '') {
                        $oldFile = dirname(__DIR__) . $logoPath;
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

require $privateRoot . '/app/views/header.php';
?>

<section class="admin-page settings-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Instellingen</h1>
            <p>Beheer de algemene instellingen en huisstijl van DONDEBAILAR.</p>
        </div>
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
                <h2>Kleuren platformbeheer</h2>
                <p>Pas de kleuren van het platformbeheer aan in een apart scherm.</p>
            </div>
            <a class="button button--primary" href="/admin/platform-colors.php">Kleuren instellen</a>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Agendaweergave</h2>
                <p>Bepaal hoeveel evenementen in één reeks worden getoond. Bezoekers bladeren op de agenda met de pijltjes naar de vorige of volgende reeks.</p>
            </div>
        </div>
        <form method="post" action="/admin/settings.php" class="auth-form agenda-settings-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="agenda_page_size">Evenementen per reeks (1–100)</label>
            <div class="agenda-setting-controls">
                <div class="agenda-setting-input" style="max-width:180px">
                    <input type="number" id="agenda_page_size" name="agenda_page_size" min="1" max="100" step="1" value="<?= (int) $agendaPageSize ?>" required style="text-align:center;margin:0">
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button--primary" name="save_agenda_settings" value="1">Agenda-instelling opslaan</button>
                </div>
            </div>
        </form>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Maximale evenementduur</h2>
                <p>Deze limiet geldt voor nieuwe en gewijzigde evenementen in alle regio's. Een einde om 24:00 (middernacht) op de startdag valt buiten deze limiet. Als geen eindtijd wordt ingevuld, eindigt het evenement automatisch om 24:00.</p>
            </div>
        </div>
        <form method="post" action="/admin/settings.php" class="auth-form event-duration-settings-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="max_event_duration_hours">Maximale duur (1–168 uur)</label>
            <div class="duration-setting-controls">
                <div class="duration-setting-input">
                    <input type="number" id="max_event_duration_hours" name="max_event_duration_hours" min="1" max="168" step="1" value="<?= (int) $maxEventDurationHours ?>" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="button button--primary" name="save_event_duration" value="1">Maximale duur opslaan</button>
                </div>
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

        <form method="post" action="/admin/settings.php" enctype="multipart/form-data" class="auth-form platform-logo-settings-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="platform_logo">Logo uploaden (PNG, JPG of WEBP; maximaal 5 MB)</label>
            <div class="platform-logo-controls">
                <input type="file" id="platform_logo" name="platform_logo" accept="image/png,image/jpeg,image/webp" required>
                <div class="form-actions">
                    <button type="submit" class="button button--primary">Logo opslaan</button>
                    <?php if ($logoPath !== ''): ?>
                        <button type="submit" class="button" name="remove_logo" value="1" formnovalidate onclick="return confirm('Het platformlogo verwijderen?');">Logo verwijderen</button>
                    <?php endif; ?>
                </div>
                <div class="platform-logo-preview">
                    <?php if ($logoPath !== ''): ?>
                        <img src="<?= escapeHtml($logoPath) ?>" alt="DONDEBAILAR-logo">
                    <?php else: ?>
                        <span>Standaardbeeldmerk</span>
                    <?php endif; ?>
                </div>
            </div>
        </form>
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



<?php require $privateRoot . '/app/views/footer.php'; ?>
