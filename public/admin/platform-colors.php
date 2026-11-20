<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = 'Kleuren platformbeheer - DONDEBAILAR';
$platformAdminPage = true;
$errorMessage = '';
$successMessage = '';
$projectRoot = DONDEBAILAR_PRIVATE_ROOT;
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
    'footer' => '#FFFFFF',
    'menu' => '#FFFFFF',
    'menu_text' => '#202B38',
    'menu_hover_bg' => '#F3F6F9',
    'menu_hover_text' => '#0A4D9F',
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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    if (!verifyCsrfToken(is_string($csrf) ? $csrf : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } else {
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
                header('Location: /admin/platform-colors.php?saved=1');
                exit;
            }
        }
    }
}
if (isset($_GET['saved'])) {
    $successMessage = 'De kleuren van het platformbeheer zijn opgeslagen.';
}

require $projectRoot . '/app/views/header.php';
?>
<style>
.platform-colors-page .admin-heading {
    margin-bottom: 14px;
    gap: 12px;
}
.platform-colors-page .admin-heading > div > p:last-child {
    margin: 5px 0 0;
}
.platform-colors-page > .admin-section {
    padding: 16px;
}
.platform-colors-page .section-heading {
    margin-bottom: 8px;
}
.platform-colors-page .platform-color-preview-wrap {
    margin: 12px 0 16px !important;
}
.platform-colors-page .platform-color-preview-wrap > h3 {
    margin-bottom: 4px !important;
}
.platform-colors-page .platform-color-preview-wrap > p {
    margin-bottom: 9px !important;
}
.platform-colors-page > .admin-section > form .form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}
.platform-colors-page .admin-heading > a.button,
.platform-colors-page > .admin-section > form .form-actions .button {
    background: var(--db-primary, #0A4D9F) !important;
    border-color: var(--db-primary, #0A4D9F) !important;
    color: #FFFFFF !important;
    box-sizing: border-box;
    width: 230px;
    min-width: 230px;
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    white-space: normal;
    flex: 0 0 230px;
}
.platform-colors-page .admin-heading > a.button:hover,
.platform-colors-page > .admin-section > form .form-actions .button:hover {
    background: var(--db-primary-dark, #083B79) !important;
    border-color: var(--db-primary-dark, #083B79) !important;
    color: #FFFFFF !important;
}
@media (max-width: 700px) {
    .platform-colors-page > .admin-section {
        padding: 12px;
    }
    .platform-colors-page .admin-heading {
        align-items: stretch;
    }
    .platform-colors-page .admin-heading > a.button,
    .platform-colors-page > .admin-section > form .form-actions .button {
        width: 100%;
        min-width: 0;
        flex: 1 1 100%;
    }
    .platform-colors-page #platform-color-preview {
        font-size: 13px;
    }
}
</style>

<section class="admin-page platform-colors-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Kleuren platformbeheer</h1>
            <p>Deze kleuren gelden uitsluitend voor de pagina's van Platformbeheer. Regiobeheer en de openbare website behouden hun eigen kleuren.</p>
        </div>
        <a class="button" href="/admin/settings.php">Terug naar instellingen</a>
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
                    <span style="font-size:13px">Platformbeheer　☰</span>
                </div>
                <div id="preview-menu" style="background:<?= escapeHtml($platformAdminColors['menu']) ?>;padding:9px 16px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>;font-size:13px;display:flex;gap:5px;flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;width:100%;padding-bottom:8px;border-bottom:1px solid <?= escapeHtml($platformAdminColors['border']) ?>">
                        <strong style="font-size:12px">Menu kleuren</strong>
                        <label class="preview-color-choice" style="margin-left:0"><span>Menuachtergrond</span><input type="color" data-preview-color="menu" value="<?= escapeHtml($platformAdminColors['menu']) ?>" aria-label="Menu achtergrondkleur"></label>
                        <label class="preview-color-choice" style="margin-left:0"><span>Menutekst</span><input type="color" data-preview-color="menu_text" value="<?= escapeHtml($platformAdminColors['menu_text']) ?>" aria-label="Menutekstkleur"></label>
                        <label class="preview-color-choice" style="margin-left:0"><span>Hoverachtergrond</span><input type="color" data-preview-color="menu_hover_bg" value="<?= escapeHtml($platformAdminColors['menu_hover_bg']) ?>" aria-label="Menu hoverachtergrond"></label>
                        <label class="preview-color-choice" style="margin-left:0"><span>Hovertekst</span><input type="color" data-preview-color="menu_hover_text" value="<?= escapeHtml($platformAdminColors['menu_hover_text']) ?>" aria-label="Menu hovertekstkleur"></label>
                    </div>
                    <span class="preview-menu-item">Dashboard</span><span class="preview-menu-item preview-menu-item--hover">Gebruikers (hover)</span><span class="preview-menu-item">Regio's</span><span class="preview-menu-item">Instellingen</span>
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
                            <div style="font-size:14px;font-weight:700;margin-bottom:12px">Voorbeeld invulscherm</div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-bottom:14px">
                                <div style="padding:10px;border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:7px">
                                    <div style="font-size:12px;font-weight:700;margin-bottom:8px">1. Invulscherm</div>
                                    <label class="preview-color-choice" style="margin:0"><span>Achtergrond invulscherm</span><input type="color" data-preview-color="form_panel_bg" value="<?= escapeHtml($platformAdminColors['form_panel_bg']) ?>" aria-label="Achtergrond invulscherm"></label>
                                </div>
                                <div style="padding:10px;border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:7px">
                                    <div style="font-size:12px;font-weight:700;margin-bottom:8px">2. Labels</div>
                                    <label class="preview-color-choice" style="margin:0"><span>Labeltekst</span><input type="color" data-preview-color="form_label_text" value="<?= escapeHtml($platformAdminColors['form_label_text']) ?>" aria-label="Labeltekst invulscherm"></label>
                                </div>
                                <div style="padding:10px;border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:7px">
                                    <div style="font-size:12px;font-weight:700;margin-bottom:8px">3. Invoervelden</div>
                                    <div style="display:grid;gap:7px">
                                        <label class="preview-color-choice" style="margin:0"><span>Veldachtergrond</span><input type="color" data-preview-color="form_input_bg" value="<?= escapeHtml($platformAdminColors['form_input_bg']) ?>" aria-label="Veldachtergrond invulscherm"></label>
                                        <label class="preview-color-choice" style="margin:0"><span>Veldtekst</span><input type="color" data-preview-color="form_input_text" value="<?= escapeHtml($platformAdminColors['form_input_text']) ?>" aria-label="Veldtekst invulscherm"></label>
                                        <label class="preview-color-choice" style="margin:0"><span>Veldrand</span><input type="color" data-preview-color="form_input_border" value="<?= escapeHtml($platformAdminColors['form_input_border']) ?>" aria-label="Randkleur invoervelden"></label>
                                        <label class="preview-color-choice" style="margin:0"><span>Hoverrand</span><input type="color" data-preview-color="form_input_hover_border" value="<?= escapeHtml($platformAdminColors['form_input_hover_border']) ?>" aria-label="Hoverrand invoervelden"></label>
                                    </div>
                                </div>
                                <div style="padding:10px;border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:7px">
                                    <div style="font-size:12px;font-weight:700;margin-bottom:8px">4. Knoppen</div>
                                    <div style="display:grid;gap:7px">
                                        <label class="preview-color-choice" style="margin:0"><span>Knopachtergrond</span><input type="color" data-preview-color="form_button_bg" value="<?= escapeHtml($platformAdminColors['form_button_bg']) ?>" aria-label="Achtergrondkleur formulierknop"></label>
                                        <label class="preview-color-choice" style="margin:0"><span>Knoptekst</span><input type="color" data-preview-color="form_button_text" value="<?= escapeHtml($platformAdminColors['form_button_text']) ?>" aria-label="Tekstkleur formulierknop"></label>
                                        <label class="preview-color-choice" style="margin:0"><span>Knop hoverachtergrond</span><input type="color" data-preview-color="form_button_hover_bg" value="<?= escapeHtml($platformAdminColors['form_button_hover_bg']) ?>" aria-label="Hoverkleur formulierknop"></label>
                                        <label class="preview-color-choice" style="margin:0"><span>Knop hovertekst</span><input type="color" data-preview-color="form_button_hover_text" value="<?= escapeHtml($platformAdminColors['form_button_hover_text']) ?>" aria-label="Hovertekstkleur formulierknop"></label>
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
                                <label style="display:grid;gap:5px;font-size:12px;font-weight:600;flex:1 1 240px;min-width:0">Regio naam
                                    <input type="text" value="Marina Alta" readonly style="width:100%;box-sizing:border-box;padding:8px;border:1px solid <?= escapeHtml($platformAdminColors['form_input_border']) ?>;border-radius:6px;background:<?= escapeHtml($platformAdminColors['form_input_bg']) ?>;color:<?= escapeHtml($platformAdminColors['form_input_text']) ?>">
                                </label>
                                <button type="button" class="preview-form-button" style="padding:8px 14px;border:0;border-radius:6px;background:<?= escapeHtml($platformAdminColors['form_button_bg']) ?>;color:<?= escapeHtml($platformAdminColors['form_button_text']) ?>;white-space:nowrap;min-height:37px">Opslaan</button>
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
                <div id="preview-footer" style="padding:12px 16px;background:<?= escapeHtml($platformAdminColors['footer']) ?>;color:<?= escapeHtml($platformAdminColors['muted']) ?>;font-size:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
                    <span>Voorbeeld voettekst — DONDEBAILAR</span>
                    <label class="preview-color-choice" style="margin-left:0"><span>Footerachtergrond</span><input type="color" data-preview-color="footer" value="<?= escapeHtml($platformAdminColors['footer']) ?>" aria-label="Footer achtergrondkleur"></label>
                </div>
            </div>
            <p style="font-size:12px;color:var(--db-muted);margin:8px 0 0">Dit is een vereenvoudigde voorbeeldweergave; de echte pagina kan per onderdeel iets verschillen.</p>
        </div>
<form method="post" action="/admin/platform-colors.php">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <?php foreach ($platformAdminColors as $colorKey => $colorValue): ?>
                <input type="hidden" id="platform_color_<?= escapeHtml($colorKey) ?>" name="platform_colors[<?= escapeHtml($colorKey) ?>]" value="<?= escapeHtml($colorValue) ?>">
            <?php endforeach; ?>
            <div class="form-actions">
                <button type="submit" class="button button--primary" name="save_platform_colors" value="1">Platformkleuren opslaan</button>
            </div>
        </form>
    </section>

<script src="/assets/js/platform-colors-preview.js?v=7" defer></script>
<?php require $projectRoot . '/app/views/footer.php'; ?>
