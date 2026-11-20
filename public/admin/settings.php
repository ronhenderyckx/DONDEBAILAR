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
    'action_button' => '#0A4D9F',
    'list_text' => '#202B38',
    'list_header_bg' => '#F1F3F6',
    'list_header_text' => '#687586',
    'list_row_bg' => '#FFFFFF',
    'list_hover_bg' => '#083B79',
    'list_hover_text' => '#FFFFFF',
    'action_button_text' => '#FFFFFF',
    'action_hover_bg' => '#083B79',
    'action_hover_text' => '#FFFFFF',
    'status_active_bg' => '#EAF7F0',
    'status_active_text' => '#16845B',
    'status_inactive_bg' => '#F0F1F3',
    'status_inactive_text' => '#68717C',
    'form_panel_bg' => '#F7F8FA',
    'form_label_text' => '#202B38',
    'form_input_bg' => '#FFFFFF',
    'form_input_text' => '#202B38',
    'form_input_border' => '#E7EAF0',
    'form_input_hover_border' => '#0A4D9F',
    'form_button_bg' => '#0A4D9F',
    'form_button_text' => '#FFFFFF',
    'form_button_hover_bg' => '#083B79',
    'form_button_hover_text' => '#FFFFFF',
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
                        <div id="preview-form-panel" style="border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:8px;padding:12px;margin:12px 0 18px;background:<?= escapeHtml($platformAdminColors['form_panel_bg']) ?>">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
                                <div style="font-size:14px;font-weight:700">Voorbeeld invulscherm</div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    <label class="preview-color-choice" style="margin:0"><span>Paneel</span><input type="color" data-preview-color="form_panel_bg" value="<?= escapeHtml($platformAdminColors['form_panel_bg']) ?>" aria-label="Achtergrond invulscherm"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Labeltekst</span><input type="color" data-preview-color="form_label_text" value="<?= escapeHtml($platformAdminColors['form_label_text']) ?>" aria-label="Labeltekst invulscherm"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Veldachtergrond</span><input type="color" data-preview-color="form_input_bg" value="<?= escapeHtml($platformAdminColors['form_input_bg']) ?>" aria-label="Veldachtergrond invulscherm"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Veldtekst</span><input type="color" data-preview-color="form_input_text" value="<?= escapeHtml($platformAdminColors['form_input_text']) ?>" aria-label="Veldtekst invulscherm"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Veldrand</span><input type="color" data-preview-color="form_input_border" value="<?= escapeHtml($platformAdminColors['form_input_border']) ?>" aria-label="Randkleur invoervelden"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Veld hoverrand</span><input type="color" data-preview-color="form_input_hover_border" value="<?= escapeHtml($platformAdminColors['form_input_hover_border']) ?>" aria-label="Hoverrand invoervelden"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Knop</span><input type="color" data-preview-color="form_button_bg" value="<?= escapeHtml($platformAdminColors['form_button_bg']) ?>" aria-label="Achtergrondkleur formulierknop"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Knoptekst</span><input type="color" data-preview-color="form_button_text" value="<?= escapeHtml($platformAdminColors['form_button_text']) ?>" aria-label="Tekstkleur formulierknop"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Knop hover</span><input type="color" data-preview-color="form_button_hover_bg" value="<?= escapeHtml($platformAdminColors['form_button_hover_bg']) ?>" aria-label="Hoverkleur formulierknop"></label>
                                    <label class="preview-color-choice" style="margin:0"><span>Hovertekst</span><input type="color" data-preview-color="form_button_hover_text" value="<?= escapeHtml($platformAdminColors['form_button_hover_text']) ?>" aria-label="Hovertekstkleur formulierknop"></label>
                                </div>
                            </div>
                            <div style="max-width:360px">
                                <label style="display:grid;gap:5px;font-size:12px;font-weight:600">Naam
                                    <input type="text" value="Voorbeeld invulveld" readonly style="width:100%;box-sizing:border-box;padding:8px;border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:6px;background:<?= escapeHtml($platformAdminColors['form_input_bg']) ?>;color:<?= escapeHtml($platformAdminColors['form_input_text']) ?>">
                                </label>
                            </div>
                            <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
                                <button type="button" class="preview-form-button preview-form-button--secondary" style="padding:7px 12px;border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:6px;background:<?= escapeHtml($platformAdminColors['form_input_bg']) ?>;color:<?= escapeHtml($platformAdminColors['form_input_text']) ?>">Annuleren</button>
                                <button type="button" class="preview-form-button" style="padding:7px 12px;border:0;border-radius:6px;background:<?= escapeHtml($platformAdminColors['form_button_bg']) ?>;color:<?= escapeHtml($platformAdminColors['form_button_text']) ?>">Opslaan</button>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:16px 0 8px"><div id="preview-list-title" style="font-size:14px;font-weight:700">Voorbeeld van een beheerlijst</div><div style="display:flex;gap:10px;flex-wrap:wrap"><label class="preview-color-choice" style="margin-left:0"><span>Lijsttekst</span><input type="color" data-preview-color="list_text" value="<?= escapeHtml($platformAdminColors['list_text']) ?>" aria-label="Tekstkleur van de voorbeeldlijst"></label><label class="preview-color-choice" style="margin-left:0"><span>Knop Wijzigen</span><input type="color" data-preview-color="action_button" value="<?= escapeHtml($platformAdminColors['action_button']) ?>" aria-label="Kleur knop Wijzigen"></label></div></div>
                        <div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 12px">
<label class="preview-color-choice" style="margin-left:0"><span>Kopachtergrond</span><input type="color" data-preview-color="list_header_bg" value="<?= escapeHtml($platformAdminColors['list_header_bg']) ?>" aria-label="Kopachtergrond"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Koptekst</span><input type="color" data-preview-color="list_header_text" value="<?= escapeHtml($platformAdminColors['list_header_text']) ?>" aria-label="Koptekst"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Regelachtergrond</span><input type="color" data-preview-color="list_row_bg" value="<?= escapeHtml($platformAdminColors['list_row_bg']) ?>" aria-label="Regelachtergrond"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Hoverachtergrond</span><input type="color" data-preview-color="list_hover_bg" value="<?= escapeHtml($platformAdminColors['list_hover_bg']) ?>" aria-label="Hoverachtergrond"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Hovertekst</span><input type="color" data-preview-color="list_hover_text" value="<?= escapeHtml($platformAdminColors['list_hover_text']) ?>" aria-label="Hovertekst"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Tekst knop</span><input type="color" data-preview-color="action_button_text" value="<?= escapeHtml($platformAdminColors['action_button_text']) ?>" aria-label="Tekst knop"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Hover knop</span><input type="color" data-preview-color="action_hover_bg" value="<?= escapeHtml($platformAdminColors['action_hover_bg']) ?>" aria-label="Hover knop"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Hover knoptekst</span><input type="color" data-preview-color="action_hover_text" value="<?= escapeHtml($platformAdminColors['action_hover_text']) ?>" aria-label="Hover knoptekst"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Actief achtergrond</span><input type="color" data-preview-color="status_active_bg" value="<?= escapeHtml($platformAdminColors['status_active_bg']) ?>" aria-label="Actief achtergrond"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Actief tekst</span><input type="color" data-preview-color="status_active_text" value="<?= escapeHtml($platformAdminColors['status_active_text']) ?>" aria-label="Actief tekst"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Inactief achtergrond</span><input type="color" data-preview-color="status_inactive_bg" value="<?= escapeHtml($platformAdminColors['status_inactive_bg']) ?>" aria-label="Inactief achtergrond"></label>
<label class="preview-color-choice" style="margin-left:0"><span>Inactief tekst</span><input type="color" data-preview-color="status_inactive_text" value="<?= escapeHtml($platformAdminColors['status_inactive_text']) ?>" aria-label="Inactief tekst"></label>
                        </div>
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
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>">Regel 1</td>
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>">Regio 1</td>
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>"><span style="display:inline-block;padding:3px 7px;border-radius:12px;background:#EAF7F0;color:#16845B" class="preview-status-active">Actief</span></td>
                                        <td style="padding:9px 7px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>"><button type="button" class="preview-action-button" style="padding:4px 8px;border:1px solid <?= escapeHtml($platformAdminColors['action_button']) ?>;border-radius:5px;background:<?= escapeHtml($platformAdminColors['action_button']) ?>;color:#FFFFFF;cursor:pointer">Wijzigen</button></td>
                                    </tr>
                                    <tr>
                                        <td style="padding:9px 7px">Regel 2</td>
                                        <td style="padding:9px 7px">Regio 2</td>
                                        <td style="padding:9px 7px"><span style="display:inline-block;padding:3px 7px;border-radius:12px;background:#F0F1F3;color:#68717C" class="preview-status-inactive">Inactief</span></td>
                                        <td style="padding:9px 7px"><button type="button" class="preview-action-button" style="padding:4px 8px;border:1px solid <?= escapeHtml($platformAdminColors['action_button']) ?>;border-radius:5px;background:<?= escapeHtml($platformAdminColors['action_button']) ?>;color:#FFFFFF;cursor:pointer">Wijzigen</button></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap">
                            <button type="button" id="preview-primary-button" style="border:0;cursor:pointer;background:<?= escapeHtml($platformAdminColors['primary']) ?>;color:#FFFFFF;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700">Opslaan</button><label class="preview-color-choice"><span>Knoppen</span><input type="color" data-preview-color="primary" value="<?= escapeHtml($platformAdminColors['primary']) ?>" aria-label="Primaire kleur"></label>
                            <button type="button" id="preview-hover-button" style="border:0;background:<?= escapeHtml($platformAdminColors['primary']) ?>;color:#FFFFFF;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700;cursor:pointer;transition:background .15s ease">Beweeg muis hier</button><label class="preview-color-choice"><span>Hover</span><input type="color" data-preview-color="primary_dark" value="<?= escapeHtml($platformAdminColors['primary_dark']) ?>" aria-label="Donkere primaire kleur"></label>
                            <button type="button" id="preview-accent" style="border:0;cursor:pointer;background:<?= escapeHtml($platformAdminColors['accent']) ?>;color:#202B38;border-radius:6px;padding:8px 12px;font-size:13px;font-weight:700">Accent</button><label class="preview-color-choice"><span>Accent</span><input type="color" data-preview-color="accent" value="<?= escapeHtml($platformAdminColors['accent']) ?>" aria-label="Accentkleur"></label>
                        </div>
                    </div>
                </div>
            </div>
            <p style="font-size:12px;color:var(--db-muted);margin:8px 0 0">Dit is een vereenvoudigde voorbeeldweergave; de echte pagina kan per onderdeel iets verschillen.</p>
        </div>
<form method="post" action="/admin/settings.php">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <?php foreach ($platformAdminColors as $colorKey => $colorValue): ?>
                <input type="hidden" id="platform_color_<?= escapeHtml($colorKey) ?>" name="platform_colors[<?= escapeHtml($colorKey) ?>]" value="<?= escapeHtml($colorValue) ?>">
            <?php endforeach; ?>
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

<script>
(function () {
    const preview = document.getElementById('platform-color-preview');
    if (!preview) return;

    const color = (key, fallback) =>
        preview.querySelector('[data-preview-color="' + key + '"]')?.value || fallback;

    function applyPreviewColors() {
        const primary = color('primary', '#0A4D9F');
        const dark = color('primary_dark', '#083B79');
        const actionButton = color('action_button', primary);
        const listText = color('list_text', '#202B38');
        const accent = color('accent', '#00AFC5');
        const header = color('header', '#FFFFFF');
        const menu = color('menu', '#FFFFFF');
        const background = color('background', '#F7F8FA');
        const surface = color('surface', '#FFFFFF');
        const text = color('text', '#202B38');
        const muted = color('muted', '#687586');
        const border = color('border', '#E7EAF0');
        const listHeaderBg = color('list_header_bg', '#F1F3F6');
        const listHeaderText = color('list_header_text', muted);
        const listRowBg = color('list_row_bg', surface);
        const listHoverBg = color('list_hover_bg', dark);
        const listHoverText = color('list_hover_text', '#FFFFFF');
        const actionButtonText = color('action_button_text', '#FFFFFF');
        const actionHoverBg = color('action_hover_bg', dark);
        const actionHoverText = color('action_hover_text', '#FFFFFF');
        const activeBg = color('status_active_bg', '#EAF7F0');
        const activeText = color('status_active_text', '#16845B');
        const inactiveBg = color('status_inactive_bg', '#F0F1F3');
        const inactiveText = color('status_inactive_text', '#68717C');

        preview.style.backgroundColor = background;
        preview.style.color = text;
        preview.style.borderColor = border;

        const setBackground = (selector, value) =>
            preview.querySelectorAll(selector).forEach(el => el.style.backgroundColor = value);
        const setText = (selector, value) =>
            preview.querySelectorAll(selector).forEach(el => el.style.color = value);
        const setBorder = (selector, value) =>
            preview.querySelectorAll(selector).forEach(el => el.style.borderColor = value);

        setBackground('#preview-header', header);
        setBackground('#preview-menu', menu);
        setBackground('#preview-page', background);
        setBackground('#preview-panel, #preview-admin-list', surface);
        const formPanelBg = color('form_panel_bg', background);
        const formLabelText = color('form_label_text', text);
        const formInputBg = color('form_input_bg', surface);
        const formInputText = color('form_input_text', text);
        const formInputBorder = color('form_input_border', border);
        const formInputHoverBorder = color('form_input_hover_border', primary);
        const formButtonBg = color('form_button_bg', primary);
        const formButtonText = color('form_button_text', '#FFFFFF');
        const formButtonHoverBg = color('form_button_hover_bg', dark);
        const formButtonHoverText = color('form_button_hover_text', '#FFFFFF');
        setBackground('#preview-form-panel', formPanelBg);
        setBackground('#preview-form-panel input:not([type="color"]), #preview-form-panel select, #preview-form-panel .preview-form-button--secondary', formInputBg);
        setBackground('#preview-form-panel .preview-form-button:not(.preview-form-button--secondary)', formButtonBg);
        setText('#preview-form-panel', formLabelText);
        setText('#preview-form-panel input:not([type="color"]), #preview-form-panel select, #preview-form-panel .preview-form-button--secondary', formInputText);
        setText('#preview-form-panel .preview-form-button:not(.preview-form-button--secondary)', formButtonText);
        setBorder('#preview-form-panel, #preview-form-panel input:not([type="color"]), #preview-form-panel select, #preview-form-panel .preview-form-button', formInputBorder);
        preview.querySelectorAll('#preview-form-panel input:not([type="color"]), #preview-form-panel select').forEach(field => {
            field.style.transition = 'border-color .15s ease, box-shadow .15s ease';
            field.onmouseenter = () => { field.style.borderColor = formInputHoverBorder; };
            field.onmouseleave = () => { if (document.activeElement !== field) field.style.borderColor = formInputBorder; };
            field.onfocus = () => { field.style.borderColor = formInputHoverBorder; field.style.boxShadow = '0 0 0 2px ' + formInputHoverBorder + '33'; };
            field.onblur = () => { field.style.borderColor = formInputBorder; field.style.boxShadow = 'none'; };
        });
        preview.querySelectorAll('#preview-form-panel .preview-form-button').forEach(button => {
            button.style.cursor = 'pointer';
            button.style.transition = 'background-color .15s ease, color .15s ease, border-color .15s ease';
            button.onmouseenter = () => {
                button.style.backgroundColor = formButtonHoverBg;
                button.style.color = formButtonHoverText;
                button.style.borderColor = formButtonHoverBg;
            };
            button.onmouseleave = () => {
                const secondary = button.classList.contains('preview-form-button--secondary');
                button.style.backgroundColor = secondary ? formInputBg : formButtonBg;
                button.style.color = secondary ? formInputText : formButtonText;
                button.style.borderColor = secondary ? formInputBorder : formButtonBg;
            };
        });
        setBackground('#preview-admin-list thead', listHeaderBg);
        setBackground('#preview-admin-list tbody tr', listRowBg);
        setBackground('.preview-status-active', activeBg);
        setBackground('.preview-status-inactive', inactiveBg);
        setBackground('#preview-primary-button', primary);
        setBackground('#preview-hover-button', primary);
        setBackground('#preview-accent', accent);

        setText('#preview-header, #preview-menu, #preview-page, #preview-panel', text);
        setText('#preview-muted', muted);
        setText('#preview-admin-list th', listHeaderText);
        setText('.preview-status-active', activeText);
        setText('.preview-status-inactive', inactiveText);
        setText('#preview-primary-button, #preview-hover-button', '#FFFFFF');
        setText('#preview-accent', '#202B38');
        setText('#preview-admin-list tbody td', listText);
        setText('#preview-admin-list .preview-action-button', actionButtonText);
        setBackground('#preview-admin-list .preview-action-button', actionButton);
        setBorder('#preview-admin-list .preview-action-button', actionButton);

        setBorder('#platform-color-preview, #preview-header, #preview-menu, #preview-panel, #preview-admin-list', border);
        setBorder('#preview-admin-list th, #preview-admin-list td', border);
        setBorder('#preview-admin-list .preview-action-button', actionButton);

        preview.querySelectorAll('#preview-admin-list tbody tr').forEach(row => {
            row.style.transition = 'background-color .15s ease, color .15s ease';
            row.onmouseenter = () => {
                row.style.backgroundColor = listHoverBg;
                row.querySelectorAll('td').forEach(cell => cell.style.color = listHoverText);
                row.querySelectorAll('.preview-action-button').forEach(button => {
                    button.style.backgroundColor = actionHoverBg;
                    button.style.borderColor = actionHoverBg;
                    button.style.color = actionHoverText;
                });
            };
            row.onmouseleave = () => {
                row.style.backgroundColor = listRowBg;
                row.querySelectorAll('td').forEach(cell => cell.style.color = listText);
                row.querySelectorAll('.preview-action-button').forEach(button => {
                    button.style.backgroundColor = actionButton;
                    button.style.borderColor = actionButton;
                    button.style.color = actionButtonText;
                });
            };
        });

        const hoverButton = preview.querySelector('#preview-hover-button');
        if (hoverButton) {
            hoverButton.onmouseenter = () => {
                hoverButton.style.backgroundColor = dark;
                hoverButton.style.color = '#FFFFFF';
            };
            hoverButton.onmouseleave = () => {
                hoverButton.style.backgroundColor = primary;
                hoverButton.style.color = '#FFFFFF';
            };
            hoverButton.onfocus = hoverButton.onmouseenter;
            hoverButton.onblur = hoverButton.onmouseleave;
        }

        preview.querySelectorAll('#preview-admin-list .preview-action-button').forEach(button => {
            button.style.cursor = 'pointer';
            button.style.transition = 'background-color .15s ease, color .15s ease';
            button.onmouseenter = () => {
                button.style.backgroundColor = actionHoverBg;
                button.style.borderColor = actionHoverBg;
                button.style.color = actionHoverText;
            };
            button.onmouseleave = () => {
                button.style.backgroundColor = actionButton;
                button.style.borderColor = actionButton;
                button.style.color = actionButtonText;
            };
        });
    }

    preview.querySelectorAll('[data-preview-color]').forEach(picker => {
        picker.addEventListener('input', function () {
            const key = picker.dataset.previewColor;
            const original = document.getElementById('platform_color_' + key);
            if (original) original.value = picker.value;
            preview.querySelectorAll('[data-preview-color="' + key + '"]').forEach(other => {
                if (other !== picker) other.value = picker.value;
            });
            applyPreviewColors();
        });
    });

    applyPreviewColors();
})();
</script>

<?php require $privateRoot . '/app/views/footer.php'; ?>
