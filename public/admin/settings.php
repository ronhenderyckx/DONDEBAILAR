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
        <div class="platform-color-preview-wrap" style="margin:18px 0 24px">
            <h3 style="margin:0 0 6px">Live voorbeeld</h3>
            <p style="margin:0 0 12px;color:var(--db-muted)">Verander een kleur hieronder en bekijk meteen wat er in dit voorbeeld verandert. Je wijzigingen worden pas actief nadat je op ‘Platformkleuren opslaan’ klikt.</p>
            <div id="platform-color-preview" style="border:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;border-radius:12px;overflow:hidden;background:<?= escapeHtml($platformAdminColors['background']) ?>;color:<?= escapeHtml($platformAdminColors['text']) ?>">
                <style>
                    #platform-color-preview .preview-color-choice{display:inline-flex;align-items:center;gap:5px;margin-left:8px;font-size:11px;font-weight:400;vertical-align:middle}
                    #platform-color-preview .preview-color-choice input{width:25px;height:23px;padding:1px;border:1px solid #999;border-radius:5px;cursor:pointer;vertical-align:middle}
                    #platform-color-preview .preview-color-choice span{white-space:nowrap}
                </style>
                <div id="preview-header" style="background:<?= escapeHtml($platformAdminColors['header']) ?>;padding:13px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>">
                    <strong style="font-size:17px;letter-spacing:.3px">DONDEBAILAR</strong><label class="preview-color-choice" title="Kies de kleur van de bovenbalk"><span>Bovenbalk</span><input type="color" data-preview-color="header" value="<?= escapeHtml($platformAdminColors['header']) ?>" aria-label="Kleur bovenbalk"></label>
                    <span style="font-size:13px">Platformbeheer　☰ <label class="preview-color-choice" title="Kies de achtergrondkleur van het menu"><span>Menu</span><input type="color" data-preview-color="menu" value="<?= escapeHtml($platformAdminColors['menu']) ?>" aria-label="Kleur menu"></label></span>
                </div>
                <div id="preview-menu" style="background:<?= escapeHtml($platformAdminColors['menu']) ?>;padding:9px 16px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;font-size:13px">
                    Dashboard　　Gebruikers　　Regio's　　Instellingen
                </div>
                <div id="preview-page" style="background:<?= escapeHtml($platformAdminColors['background']) ?>;padding:16px">
                    <div style="display:flex;justify-content:flex-end;margin-bottom:8px"><label class="preview-color-choice"><span>Pagina-achtergrond</span><input type="color" data-preview-color="background" value="<?= escapeHtml($platformAdminColors['background']) ?>" aria-label="Achtergrondkleur pagina"></label></div>
                    <div style="font-size:11px;font-weight:700;letter-spacing:1px;color:<?= escapeHtml($platformAdminColors['muted']) ?>">PLATFORMBEHEER <label class="preview-color-choice"><span>Gedempte tekst</span><input type="color" data-preview-color="muted" value="<?= escapeHtml($platformAdminColors['muted']) ?>" aria-label="Gedempte tekstkleur"></label></div>
                    <div style="font-size:21px;font-weight:700;margin:3px 0 5px">Instellingen <label class="preview-color-choice"><span>Tekst</span><input type="color" data-preview-color="text" value="<?= escapeHtml($platformAdminColors['text']) ?>" aria-label="Tekstkleur"></label></div>
                    <div id="preview-muted" style="font-size:13px;color:<?= escapeHtml($platformAdminColors['muted']) ?>;margin-bottom:13px">Beheer de algemene instellingen en huisstijl.</div>
                    <div id="preview-panel" style="background:<?= escapeHtml($platformAdminColors['surface']) ?>;border:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;border-radius:9px;padding:14px"><div style="display:flex;justify-content:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:8px"><label class="preview-color-choice"><span>Paneel</span><input type="color" data-preview-color="surface" value="<?= escapeHtml($platformAdminColors['surface']) ?>" aria-label="Kleur panelen"></label><label class="preview-color-choice"><span>Randen</span><input type="color" data-preview-color="border" value="<?= escapeHtml($platformAdminColors['border']) ?>" aria-label="Randkleur"></label></div>
                        <div style="font-size:15px;font-weight:700;margin-bottom:5px">Voorbeeld van een instellingenblok</div>
                        <div style="font-size:13px;margin-bottom:12px">Dit is gewone tekst in een paneel.</div>
                        <div id="preview-list-title" style="font-size:14px;font-weight:700;margin:16px 0 8px">Voorbeeld van een beheerlijst</div>
                        <div style="overflow-x:auto">
                            <table id="preview-admin-list" style="width:100%;border-collapse:collapse;font-size:12px;background:<?= escapeHtml($platformAdminColors['surface']) ?>;color:<?= escapeHtml($platformAdminColors['text']) ?>">
                                <thead><tr>
                                    <th style="text-align:left;padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;color:<?= escapeHtml($platformAdminColors['muted']) ?>">Naam</th>
                                    <th style="text-align:left;padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;color:<?= escapeHtml($platformAdminColors['muted']) ?>">Regio</th>
                                    <th style="text-align:left;padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;color:<?= escapeHtml($platformAdminColors['muted']) ?>">Status</th>
                                    <th style="text-align:left;padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;color:<?= escapeHtml($platformAdminColors['muted']) ?>">Acties</th>
                                </tr></thead>
                                <tbody>
                                    <tr>
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>">Marina Alta</td>
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>">Spanje</td>
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>"><span style="display:inline-block;padding:3px 7px;border-radius:12px;background:#EAF7F0;color:#16845B">Actief</span></td>
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>"><span style="display:inline-block;padding:4px 8px;border:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;border-radius:5px;color:<?= escapeHtml($platformAdminColors['primary']) ?>">Wijzigen</span></td>
                                    </tr>
                                    <tr>
                                        <td style="padding:9px 7px">Costa Blanca</td>
                                        <td style="padding:9px 7px">Spanje</td>
                                        <td style="padding:9px 7px"><span style="display:inline-block;padding:3px 7px;border-radius:12px;background:#F0F1F3;color:#68717C">Inactief</span></td>
                                        <td style="padding:9px 7px"><span style="display:inline-block;padding:4px 8px;border:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;border-radius:5px;color:<?= escapeHtml($platformAdminColors['primary']) ?>">Wijzigen</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
                            <span id="preview-primary-button" style="display:inline-block;background:<?= escapeHtml($platformAdminColors['primary']) ?>;color:#FFFFFF;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700">Opslaan</span><label class="preview-color-choice"><span>Knoppen</span><input type="color" data-preview-color="primary" value="<?= escapeHtml($platformAdminColors['primary']) ?>" aria-label="Primaire kleur"></label>
                            <span id="preview-hover-button" style="display:inline-block;background:<?= escapeHtml($platformAdminColors['primary_dark']) ?>;color:#FFFFFF;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700">Knop bij aanwijzen</span><label class="preview-color-choice"><span>Hover</span><input type="color" data-preview-color="primary_dark" value="<?= escapeHtml($platformAdminColors['primary_dark']) ?>" aria-label="Donkere primaire kleur"></label>
                            <span id="preview-accent" style="display:inline-block;background:<?= escapeHtml($platformAdminColors['accent']) ?>;color:#202B38;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700">Accent</span><label class="preview-color-choice"><span>Accent</span><input type="color" data-preview-color="accent" value="<?= escapeHtml($platformAdminColors['accent']) ?>" aria-label="Accentkleur"></label>
                        </div>
                    </div>
                </div>
            </div>
            <p style="font-size:12px;color:var(--db-muted);margin:8px 0 0">Dit is een vereenvoudigde voorbeeldweergave; de echte pagina kan per onderdeel iets verschillen.</p>
        </div>
<form method="post" action="/admin/settings.php">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead><tr><th>Kleur</th><th>Waarvoor dient deze?</th><th>Kleur kiezen</th><th>Code</th></tr></thead>
                    <tbody>
                        <?php
                        $colorDescriptions = [
                            'primary' => ['Primaire kleur', 'Hoofdkleur voor actieknoppen, links en geselecteerde onderdelen.'],
                            'primary_dark' => ['Donkere primaire kleur', 'Donkere variant bij aanwijzen of hoveren over een knop.'],
                            'accent' => ['Accentkleur', 'Laat belangrijke details en markeringen extra opvallen.'],
                            'header' => ['Bovenbalk', 'Achtergrond van de bovenste balk met het DONDEBAILAR-logo.'],
                            'menu' => ['Menu', 'Achtergrondkleur van het uitklapmenu met navigatie-items.'],
                            'background' => ['Pagina-achtergrond', 'Achtergrond achter de panelen en inhoud van de beheerpagina.'],
                            'surface' => ['Panelen en kaarten', 'Achtergrond van inhoudspanelen, kaarten en instellingenblokken.'],
                            'text' => ['Tekstkleur', 'Standaardkleur voor titels, koppen en gewone teksten.'],
                            'muted' => ['Gedempte tekst', 'Voor toelichtingen, ondertitels en aanvullende informatie.'],
                            'border' => ['Randkleur', 'Voor randen en scheidingslijnen rond panelen, kaarten, tabellen en menu’s.']
                        ];
                        ?>
                        <?php foreach ($colorDescriptions as $colorKey => [$colorLabel, $colorDescription]): ?>
                            <tr>
                                <td><strong><?= escapeHtml($colorLabel) ?></strong></td>
                                <td><?= escapeHtml($colorDescription) ?></td>
                                <td><input type="color" id="platform_color_<?= escapeHtml($colorKey) ?>" name="platform_colors[<?= escapeHtml($colorKey) ?>]" value="<?= escapeHtml($platformAdminColors[$colorKey]) ?>" aria-label="<?= escapeHtml($colorLabel) ?>" style="width:64px;height:42px;padding:3px"></td>
                                <td><code><?= escapeHtml($platformAdminColors[$colorKey]) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="form-actions">
                <button type="submit" class="button button--primary" name="save_platform_colors" value="1">Platformkleuren opslaan</button>
            </div>
        </form> </form>
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

<script>
(function () {
    const preview = document.getElementById('platform-color-preview');
    if (!preview) return;

    const targets = {
        primary: ['preview-primary-button'],
        primary_dark: ['preview-hover-button'],
        accent: ['preview-accent'],
        header: ['preview-header'],
        menu: ['preview-menu'],
        background: ['preview-page'],
        surface: ['preview-panel'],
        text: ['platform-color-preview'],
        muted: ['preview-muted'],
        border: ['platform-color-preview', 'preview-header', 'preview-menu', 'preview-panel', 'preview-admin-list'],
        surface: ['preview-panel', 'preview-admin-list']
    };

    preview.querySelectorAll('[data-preview-color]').forEach(function (picker) {
        picker.addEventListener('input', function () {
            const key = picker.dataset.previewColor;
            const original = document.getElementById('platform_color_' + key);
            if (original) {
                original.value = picker.value;
                original.dispatchEvent(new Event('input', { bubbles: true }));
            }
            (targets[key] || []).forEach(function (id) {
                const element = document.getElementById(id);
                if (!element) return;
                if (key === 'text') {
                    element.style.color = picker.value;
                    if (id === 'platform-color-preview') {
                        preview.querySelectorAll('#preview-admin-list tbody td').forEach(function (cell) { cell.style.color = picker.value; });
                    }
                } else if (key === 'border') {
                    element.style.borderColor = picker.value;
                    if (id === 'preview-admin-list') preview.querySelectorAll('#preview-admin-list th, #preview-admin-list td').forEach(function (cell) { cell.style.borderBottomColor = picker.value; });
                } else if (key === 'muted') {
                    element.style.color = picker.value;
                    preview.querySelectorAll('#preview-admin-list th').forEach(function (cell) { cell.style.color = picker.value; });
                    const eyebrow = preview.querySelector('.preview-eyebrow');
                    if (eyebrow) eyebrow.style.color = picker.value;
                } else {
                    element.style.backgroundColor = picker.value;
                }
                if (key === 'primary') preview.querySelectorAll('#preview-admin-list td:last-child span').forEach(function (item) { item.style.color = picker.value; });
            });
            preview.querySelectorAll('[data-preview-color="' + key + '"]').forEach(function (other) {
                if (other !== picker) other.value = picker.value;
            });
        });
    });

    const backgroundPicker = preview.querySelector('[data-preview-color="background"]');
    if (backgroundPicker) backgroundPicker.addEventListener('input', function () {
        document.getElementById('platform-color-preview').style.backgroundColor = backgroundPicker.value;
    });
})();;
</script>

<?php require $privateRoot . '/app/views/footer.php'; ?>
