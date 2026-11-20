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

$platformMenuColorFile = $projectRoot . '/storage/platform-menu-color.txt';
$platformAdminColors = [
    'primary' => '#0A4D9F',
    'primary_dark' => '#083B79',
    'accent' => '#00AFC5',
    'header' => '#FFFFFF',
    'menu' => '#FFFFFF',
    'background' => '#F7F8FA',
    'surface' => '#FFFFFF',
    'text' => '#202B38',
    'muted' => '#687586',
    'border' => '#E7EAF0'
];
$platformColorsFile = $projectRoot . '/storage/platform-admin-colors.json';
if (is_file($platformColorsFile)) {
    $savedPlatformColors = json_decode((string) file_get_contents($platformColorsFile), true);
    if (is_array($savedPlatformColors)) {
        foreach ($platformAdminColors as $colorKey => $fallbackColor) {
            $candidateColor = strtoupper(trim((string) ($savedPlatformColors[$colorKey] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $candidateColor)) {
                $platformAdminColors[$colorKey] = $candidateColor;
            }
        }
    }
} elseif (is_file($platformMenuColorFile)) {
    $legacyMenuColor = strtoupper(trim((string) file_get_contents($platformMenuColorFile)));
    if (preg_match('/^#[0-9A-F]{6}$/', $legacyMenuColor)) {
        $platformAdminColors['menu'] = $legacyMenuColor;
    }
}

$platformMenuColor = '#FFFFFF';
if (is_file($platformMenuColorFile)) {
    $storedMenuColor = strtoupper(trim((string) file_get_contents($platformMenuColorFile)));
    if (preg_match('/^#[0-9A-F]{6}$/', $storedMenuColor)) $platformMenuColor = $storedMenuColor;
}

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
    } elseif (isset($_POST['save_platform_colors'])) {
        $requestedColors = [];
        foreach ($platformAdminColors as $colorKey => $fallbackColor) {
            $candidateColor = strtoupper(trim((string) ($_POST['platform_colors'][$colorKey] ?? '')));
            if (!preg_match('/^#[0-9A-F]{6}$/', $candidateColor)) {
                $errorMessage = 'Controleer alle gekozen kleuren.';
                break;
            }
            $requestedColors[$colorKey] = $candidateColor;
        }
        if ($errorMessage === '') {
            $encodedColors = json_encode($requestedColors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encodedColors === false || file_put_contents($platformColorsFile, $encodedColors . PHP_EOL, LOCK_EX) === false) {
                $errorMessage = 'De platformkleuren konden niet worden opgeslagen. Controleer de schrijfrechten van storage.';
            } else {
                $platformAdminColors = $requestedColors;
                $platformMenuColor = $requestedColors['menu'];
                $successMessage = 'De kleuren van het platformbeheer zijn opgeslagen.';
            }
        }
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

<section class="admin-page">
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
                <p>Deze kleuren gelden uitsluitend voor de pagina's van Platformbeheer. Regiobeheer en de openbare website behouden hun eigen kleuren.</p>
            </div>
        </div>
        <form method="post" action="/admin/settings.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="platform_color_primary">Primaire kleur (knoppen en links)</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Dit is de hoofdkleur van Platformbeheer. Je ziet deze kleur bijvoorbeeld op actieknoppen, links en onderdelen die geselecteerd zijn.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_primary" name="platform_colors[primary]" value="<?= escapeHtml($platformAdminColors['primary']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['primary']) ?></code>
            </div>
            <label for="platform_color_primary_dark">Donkere primaire kleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Dit is de donkerdere variant van de hoofdkleur. Ze verschijnt wanneer je met de muis boven een actieknop of link beweegt.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_primary_dark" name="platform_colors[primary_dark]" value="<?= escapeHtml($platformAdminColors['primary_dark']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['primary_dark']) ?></code>
            </div>
            <label for="platform_color_accent">Accentkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Deze kleur dient om belangrijke details extra te laten opvallen, bijvoorbeeld een opvallend accent of markering. Ze vervangt niet de hoofdkleur van knoppen.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_accent" name="platform_colors[accent]" value="<?= escapeHtml($platformAdminColors['accent']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['accent']) ?></code>
            </div>
            <label for="platform_color_header">Achtergrondkleur bovenbalk</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Bepaalt de kleur van de volledige bovenbalk bovenaan Platformbeheer, waar het DONDEBAILAR-logo en de navigatie staan.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_header" name="platform_colors[header]" value="<?= escapeHtml($platformAdminColors['header']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['header']) ?></code>
            </div>
            <label for="platform_color_menu">Achtergrondkleur menu</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Bepaalt de achtergrond van het menu dat zichtbaar wordt wanneer je op de menuknop klikt. Kies een kleur waarbij de menu-items goed leesbaar blijven.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_menu" name="platform_colors[menu]" value="<?= escapeHtml($platformAdminColors['menu']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['menu']) ?></code>
            </div>
            <label for="platform_color_background">Achtergrondkleur pagina</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Dit is de achtergrond van de pagina zelf, achter de witte panelen en instellingenblokken.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_background" name="platform_colors[background]" value="<?= escapeHtml($platformAdminColors['background']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['background']) ?></code>
            </div>
            <label for="platform_color_surface">Achtergrondkleur panelen en kaarten</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Bepaalt de achtergrond van de afzonderlijke vlakken op de pagina, zoals instellingenblokken, kaarten en inhoudspanelen.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_surface" name="platform_colors[surface]" value="<?= escapeHtml($platformAdminColors['surface']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['surface']) ?></code>
            </div>
            <label for="platform_color_text">Tekstkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Bepaalt de standaardkleur van de belangrijkste teksten, zoals paginatitels, koppen en gewone tekst.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_text" name="platform_colors[text]" value="<?= escapeHtml($platformAdminColors['text']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['text']) ?></code>
            </div>
            <label for="platform_color_muted">Gedempte tekstkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Voor teksten die minder nadruk moeten krijgen, zoals korte toelichtingen, ondertitels en extra informatie onder een instelling.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_muted" name="platform_colors[muted]" value="<?= escapeHtml($platformAdminColors['muted']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['muted']) ?></code>
            </div>
            <label for="platform_color_border">Randkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Bepaalt de kleur van scheidingslijnen en fijne randen rond kaarten, instellingenblokken, tabellen en menu’s.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_border" name="platform_colors[border]" value="<?= escapeHtml($platformAdminColors['border']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['border']) ?></code>
            </div>
            <div class="form-actions">
                <button type="submit" class="button button--primary" name="save_platform_colors" value="1">Platformkleuren opslaan</button>
            </div>
        </form>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Agendaweergave</h2>
                <p>Bepaal hoeveel evenementen in één reeks worden getoond. Bezoekers bladeren op de agenda met de pijltjes naar de vorige of volgende reeks.</p>
            </div>
        </div>
        <form method="post" action="/admin/settings.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="agenda_page_size">Evenementen per reeks (1–100)</label>
            <div style="max-width:180px">
                <input type="number" id="agenda_page_size" name="agenda_page_size" min="1" max="100" step="1" value="<?= (int) $agendaPageSize ?>" required style="text-align:center;margin:0">
            </div>
            <div class="form-actions">
                <button type="submit" class="button button--primary" name="save_agenda_settings" value="1">Agenda-instelling opslaan</button>
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
        <form method="post" action="/admin/settings.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="max_event_duration_hours">Maximale duur (1–168 uur)</label>
            <div style="max-width:180px">
                <input type="number" id="max_event_duration_hours" name="max_event_duration_hours" min="1" max="168" step="1" value="<?= (int) $maxEventDurationHours ?>" required style="text-align:center;margin:0">
            </div>
            <div class="form-actions">
                <button type="submit" class="button button--primary" name="save_event_duration" value="1">Maximale duur opslaan</button>
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

<?php require $privateRoot . '/app/views/footer.php'; ?>
