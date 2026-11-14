<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/ImageOptimization.php';

requirePlatformAdmin();

$pageTitle = 'Instellingen - DONDEBAILAR';
$platformAdminPage = true;
$errorMessage = '';
$successMessage = '';

$projectRoot = dirname(__DIR__, 2);
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
                <h2>Kleuren platformbeheer</h2>
                <p>Deze kleuren gelden uitsluitend voor de pagina's van Platformbeheer. Regiobeheer en de openbare website behouden hun eigen kleuren.</p>
            </div>
        </div>
        <form method="post" action="/admin/settings.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="platform_color_primary">Primaire kleur (knoppen en links)</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Gebruikt voor belangrijke actieknoppen, links, geselecteerde accenten en focusranden.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_primary" name="platform_colors[primary]" value="<?= escapeHtml($platformAdminColors['primary']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['primary']) ?></code>
            </div>
            <label for="platform_color_primary_dark">Donkere primaire kleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Gebruikt wanneer je met de muis over een primaire knop of link beweegt (hover).</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_primary_dark" name="platform_colors[primary_dark]" value="<?= escapeHtml($platformAdminColors['primary_dark']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['primary_dark']) ?></code>
            </div>
            <label for="platform_color_accent">Accentkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Extra opvallende accentkleur voor onderdelen die visueel de aandacht mogen trekken.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_accent" name="platform_colors[accent]" value="<?= escapeHtml($platformAdminColors['accent']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['accent']) ?></code>
            </div>
            <label for="platform_color_header">Achtergrondkleur bovenbalk</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">De achtergrond van de bovenste balk met het DONDEBAILAR-logo en het menu.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_header" name="platform_colors[header]" value="<?= escapeHtml($platformAdminColors['header']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['header']) ?></code>
            </div>
            <label for="platform_color_menu">Achtergrondkleur menu</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">De achtergrond van het uitklapmenu dat opent via de menuknop.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_menu" name="platform_colors[menu]" value="<?= escapeHtml($platformAdminColors['menu']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['menu']) ?></code>
            </div>
            <label for="platform_color_background">Achtergrondkleur pagina</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">De algemene achtergrond achter alle inhoud op de platformbeheerpagina’s.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_background" name="platform_colors[background]" value="<?= escapeHtml($platformAdminColors['background']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['background']) ?></code>
            </div>
            <label for="platform_color_surface">Achtergrondkleur panelen en kaarten</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">De achtergrond van de witte inhoudsvakken, instellingenblokken en dashboardkaarten.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_surface" name="platform_colors[surface]" value="<?= escapeHtml($platformAdminColors['surface']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['surface']) ?></code>
            </div>
            <label for="platform_color_text">Tekstkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">De hoofdkleur van titels, gewone teksten en menu-items.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_text" name="platform_colors[text]" value="<?= escapeHtml($platformAdminColors['text']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['text']) ?></code>
            </div>
            <label for="platform_color_muted">Gedempte tekstkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Voor minder belangrijke teksten, zoals uitleg, subtitels en aanvullende informatie.</p>
            <div style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
                <input type="color" id="platform_color_muted" name="platform_colors[muted]" value="<?= escapeHtml($platformAdminColors['muted']) ?>" style="width:64px;height:42px;padding:3px">
                <code><?= escapeHtml($platformAdminColors['muted']) ?></code>
            </div>
            <label for="platform_color_border">Randkleur</label>
            <p class="platform-color-help" style="margin:-6px 0 2px;color:var(--db-muted);font-size:12px;line-height:1.45">Voor dunne lijnen en randen rond kaarten, panelen, tabellen en menu’s.</p>
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
