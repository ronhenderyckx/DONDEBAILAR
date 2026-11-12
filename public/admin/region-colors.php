<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/ImageOptimization.php';

$db = Database::connection();
$regionId = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT);
if (!$regionId || $regionId < 1) {
    http_response_code(400);
    exit('Ongeldig regionummer.');
}

requireRegionRole($regionId, ['region_admin']);

$regionQuery = $db->prepare("SELECT id, name, logo_path FROM regions WHERE id = :id LIMIT 1");
$regionQuery->execute(['id' => $regionId]);
$region = $regionQuery->fetch();
if (!$region) {
    http_response_code(404);
    exit('Regio niet gevonden.');
}

$defaults = [
    'primary_color' => '#E23A2C',
    'primary_dark' => '#8F211B',
    'accent_color' => '#F6B22E',
    'secondary_color' => '#1FA898',
    'tertiary_color' => '#F4802A',
    'header_color' => '#222222',
    'menu_color' => '#FFFFFF',
    'organizer_color' => '#E23A2C',
    'filter_hover_color' => '#E23A2C',
    'filter_bar_color' => '#FFFFFF',
    'filter_text_color' => '#222222',
    'search_button_color' => '#E23A2C',
    'back_top_color' => '#E23A2C',
    'search_field_color' => '#FFFFFF',
    'search_text_color' => '#222222',
    'footer_color' => '#FFFFFF',
    'background_color' => '#FFFFFF',
    'text_color' => '#222222',
    'agenda_arrow_background' => '#F6B22E',
    'agenda_arrow_text' => '#222222',
];
$weekdayDefaults = ['#F6D84A', '#4A90D9', '#55A96B', '#A78BDA', '#E78AAE', '#F49A45', '#999999'];
$select = $db->prepare("SELECT * FROM region_appearance WHERE region_id = :id LIMIT 1");
$select->execute(['id' => $regionId]);
$saved = $select->fetch() ?: [];
$colors = [];
foreach ($defaults as $key => $value) {
    $candidate = (string) ($saved[$key] ?? $value);
    $colors[$key] = preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) ? strtoupper($candidate) : $value;
}
$weekdayColors = $weekdayDefaults;
if (!empty($saved['weekday_colors'])) {
    $decoded = json_decode((string) $saved['weekday_colors'], true);
    if (is_array($decoded)) {
        foreach (['agenda_arrow_background', 'agenda_arrow_text'] as $arrowKey) {
            $candidate = (string) ($decoded[$arrowKey] ?? $defaults[$arrowKey]);
            $colors[$arrowKey] = preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) ? strtoupper($candidate) : $defaults[$arrowKey];
        }
        foreach ($weekdayDefaults as $i => $value) {
            if (isset($decoded[$i]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $decoded[$i])) {
                $weekdayColors[$i] = strtoupper((string) $decoded[$i]);
            }
        }
    }
}
$logoPath = $saved !== []
    ? (string) ($saved['logo_path'] ?? '')
    : (string) ($region['logo_path'] ?? '');

$cityIconSettingFile = dirname(__DIR__, 2) . '/storage/region-city-icons/' . $regionId . '.txt';
$organizerIconSettingFile = dirname(__DIR__, 2) . '/storage/region-organizer-icons/' . $regionId . '.txt';
$citySwitchIconSettingFile = dirname(__DIR__, 2) . '/storage/region-city-switch-icons/' . $regionId . '.txt';
$cityIconPath = is_file($cityIconSettingFile) ? trim((string) file_get_contents($cityIconSettingFile)) : '';
$organizerIconPath = is_file($organizerIconSettingFile) ? trim((string) file_get_contents($organizerIconSettingFile)) : '';
$citySwitchIconPath = is_file($citySwitchIconSettingFile) ? trim((string) file_get_contents($citySwitchIconSettingFile)) : '';
foreach (['cityIconPath', 'organizerIconPath', 'citySwitchIconPath'] as $iconPathVariable) {
    if (!preg_match('#^uploads/regions/[a-zA-Z0-9._-]+$#', $iconPathVariable)) {
        $iconPathVariable = '';
    }
}
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } else {
        $oldCityIconPath = $cityIconPath;
        $newCityIconPath = $cityIconPath;
        $oldOrganizerIconPath = $organizerIconPath;
        $newOrganizerIconPath = $organizerIconPath;
        $oldCitySwitchIconPath = $citySwitchIconPath;
        $newCitySwitchIconPath = $citySwitchIconPath;
        if (!empty($_POST['delete_city_icon'])) $newCityIconPath = '';
        if (!empty($_POST['delete_organizer_icon'])) $newOrganizerIconPath = '';
        if (!empty($_POST['delete_city_switch_icon'])) $newCitySwitchIconPath = '';
        if (isset($_FILES['city_icon']) && ($_FILES['city_icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedCityIcon = storeOptimizedLogoUpload(
                    $_FILES['city_icon'],
                    'public/uploads/regions',
                    256,
                    5 * 1024 * 1024,
                    'city-icon-' . $regionId . '-'
                );
                if ($uploadedCityIcon !== null) {
                    $newCityIconPath = substr($uploadedCityIcon, strlen('public/'));
                }
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        if (isset($_FILES['organizer_icon']) && ($_FILES['organizer_icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedOrganizerIcon = storeOptimizedLogoUpload(
                    $_FILES['organizer_icon'],
                    'public/uploads/regions',
                    256,
                    5 * 1024 * 1024,
                    'organizer-icon-' . $regionId . '-'
                );
                if ($uploadedOrganizerIcon !== null) {
                    $newOrganizerIconPath = substr($uploadedOrganizerIcon, strlen('public/'));
                }
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        if (isset($_FILES['city_switch_icon']) && ($_FILES['city_switch_icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedCitySwitchIcon = storeOptimizedLogoUpload(
                    $_FILES['city_switch_icon'],
                    'public/uploads/regions',
                    256,
                    5 * 1024 * 1024,
                    'city-switch-icon-' . $regionId . '-'
                );
                if ($uploadedCitySwitchIcon !== null) {
                    $newCitySwitchIconPath = substr($uploadedCitySwitchIcon, strlen('public/'));
                }
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        foreach ($defaults as $key => $default) {
            $value = strtoupper(trim((string) ($_POST[$key] ?? $default)));
            if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
                $errorMessage = 'Een of meer kleurcodes zijn ongeldig.';
                break;
            }
            $colors[$key] = $value;
        }
        foreach (['agenda_arrow_background', 'agenda_arrow_text'] as $arrowKey) {
            $value = strtoupper(trim((string) ($_POST[$arrowKey] ?? $defaults[$arrowKey])));
            if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
                $errorMessage = 'Een of meer kleuren van de agendapijlen zijn ongeldig.';
                break;
            }
            $colors[$arrowKey] = $value;
        }
        $weekdayInput = $_POST['weekday_colors'] ?? [];
        if (!is_array($weekdayInput)) $weekdayInput = [];
        foreach ($weekdayDefaults as $i => $default) {
            $value = strtoupper(trim((string) ($weekdayInput[$i] ?? $default)));
            if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
                $errorMessage = 'Een of meer dagkleuren zijn ongeldig.';
                break;
            }
            $weekdayColors[$i] = $value;
        }

        if ($errorMessage === '') {
            $oldLogoPath = $logoPath;
            $newLogoPath = $logoPath;

            if (!empty($_POST['delete_logo'])) {
                $newLogoPath = '';
            }

            if (isset($_FILES['region_logo']) && ($_FILES['region_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $uploadedRegionLogo = storeOptimizedLogoUpload(
                        $_FILES['region_logo'],
                        'public/uploads/regions',
                        800,
                        5 * 1024 * 1024,
                        'region-' . $regionId . '-'
                    );
                    if ($uploadedRegionLogo !== null) {
                        $newLogoPath = substr($uploadedRegionLogo, strlen('public/'));
                    }
                } catch (RuntimeException $exception) {
                    $errorMessage = $exception->getMessage();
                }
            }

            if ($errorMessage === '') {
                $upsert = $db->prepare("INSERT INTO region_appearance
                    (region_id, primary_color, primary_dark, accent_color, secondary_color,
                     tertiary_color, header_color, menu_color, organizer_color, filter_hover_color, filter_bar_color, filter_text_color,
                     search_button_color, back_top_color, search_field_color, search_text_color, footer_color, background_color, text_color, weekday_colors, logo_path)
                    VALUES (:region_id, :primary_color, :primary_dark, :accent_color, :secondary_color,
                     :tertiary_color, :header_color, :menu_color, :organizer_color, :filter_hover_color, :filter_bar_color, :filter_text_color,
                     :search_button_color, :back_top_color, :search_field_color, :search_text_color, :footer_color, :background_color, :text_color, :weekday_colors, :logo_path)
                    ON DUPLICATE KEY UPDATE primary_color=VALUES(primary_color),
                     primary_dark=VALUES(primary_dark), accent_color=VALUES(accent_color),
                     secondary_color=VALUES(secondary_color), tertiary_color=VALUES(tertiary_color),
                     header_color=VALUES(header_color), menu_color=VALUES(menu_color),
                     organizer_color=VALUES(organizer_color), filter_hover_color=VALUES(filter_hover_color),
                     filter_bar_color=VALUES(filter_bar_color), filter_text_color=VALUES(filter_text_color),
                     search_button_color=VALUES(search_button_color), back_top_color=VALUES(back_top_color),
                     search_field_color=VALUES(search_field_color), search_text_color=VALUES(search_text_color),
                     footer_color=VALUES(footer_color), background_color=VALUES(background_color),
                     text_color=VALUES(text_color), weekday_colors=VALUES(weekday_colors),
                     logo_path=VALUES(logo_path)");
                $upsert->execute([
                    'region_id' => $regionId,
                    'primary_color' => $colors['primary_color'],
                    'primary_dark' => $colors['primary_dark'],
                    'accent_color' => $colors['accent_color'],
                    'secondary_color' => $colors['secondary_color'],
                    'tertiary_color' => $colors['tertiary_color'],
                    'header_color' => $colors['header_color'],
                    'menu_color' => $colors['menu_color'],
                    'organizer_color' => $colors['organizer_color'],
                    'filter_hover_color' => $colors['filter_hover_color'],
                    'filter_bar_color' => $colors['filter_bar_color'],
                    'filter_text_color' => $colors['filter_text_color'],
                    'search_button_color' => $colors['search_button_color'],
                    'back_top_color' => $colors['back_top_color'],
                    'search_field_color' => $colors['search_field_color'],
                    'search_text_color' => $colors['search_text_color'],
                    'footer_color' => $colors['footer_color'],
                    'background_color' => $colors['background_color'],
                    'text_color' => $colors['text_color'],
                    'weekday_colors' => json_encode($weekdayColors + [
                        'agenda_arrow_background' => $colors['agenda_arrow_background'],
                        'agenda_arrow_text' => $colors['agenda_arrow_text'],
                    ], JSON_THROW_ON_ERROR),
                    'logo_path' => $newLogoPath,
                ]);
                $logoPath = $newLogoPath;
                $iconDirectory = dirname($cityIconSettingFile);
                if (!is_dir($iconDirectory) && !mkdir($iconDirectory, 0755, true) && !is_dir($iconDirectory)) {
                    throw new RuntimeException('De map voor de stadsicoon kon niet worden aangemaakt.');
                }
                if ($newCityIconPath === '') {
                    if (is_file($cityIconSettingFile)) @unlink($cityIconSettingFile);
                } elseif (file_put_contents($cityIconSettingFile, $newCityIconPath . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('De stadsicoon kon niet worden opgeslagen.');
                }
                $cityIconPath = $newCityIconPath;
                if ($oldCityIconPath !== '' && $oldCityIconPath !== $newCityIconPath
                    && str_starts_with($oldCityIconPath, 'uploads/regions/')) {
                    $oldIconFile = dirname(__DIR__) . '/' . $oldCityIconPath;
                    if (is_file($oldIconFile)) @unlink($oldIconFile);
                }
                $organizerIconDirectory = dirname($organizerIconSettingFile);
                if (!is_dir($organizerIconDirectory) && !mkdir($organizerIconDirectory, 0755, true) && !is_dir($organizerIconDirectory)) {
                    throw new RuntimeException('De map voor het organisatorenicoon kon niet worden aangemaakt.');
                }
                if ($newOrganizerIconPath === '') {
                    if (is_file($organizerIconSettingFile)) @unlink($organizerIconSettingFile);
                } elseif (file_put_contents($organizerIconSettingFile, $newOrganizerIconPath . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('Het organisatorenicoon kon niet worden opgeslagen.');
                }
                $organizerIconPath = $newOrganizerIconPath;
                if ($oldOrganizerIconPath !== '' && $oldOrganizerIconPath !== $newOrganizerIconPath
                    && str_starts_with($oldOrganizerIconPath, 'uploads/regions/')) {
                    $oldOrganizerIconFile = dirname(__DIR__) . '/' . $oldOrganizerIconPath;
                    if (is_file($oldOrganizerIconFile)) @unlink($oldOrganizerIconFile);
                }
                $citySwitchIconDirectory = dirname($citySwitchIconSettingFile);
                if (!is_dir($citySwitchIconDirectory) && !mkdir($citySwitchIconDirectory, 0755, true) && !is_dir($citySwitchIconDirectory)) {
                    throw new RuntimeException('De map voor het stedenkeuze-icoon kon niet worden aangemaakt.');
                }
                if ($newCitySwitchIconPath === '') {
                    if (is_file($citySwitchIconSettingFile)) @unlink($citySwitchIconSettingFile);
                } elseif (file_put_contents($citySwitchIconSettingFile, $newCitySwitchIconPath . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('Het stedenkeuze-icoon kon niet worden opgeslagen.');
                }
                $citySwitchIconPath = $newCitySwitchIconPath;
                if ($oldCitySwitchIconPath !== '' && $oldCitySwitchIconPath !== $newCitySwitchIconPath
                    && str_starts_with($oldCitySwitchIconPath, 'uploads/regions/')) {
                    $oldCitySwitchIconFile = dirname(__DIR__) . '/' . $oldCitySwitchIconPath;
                    if (is_file($oldCitySwitchIconFile)) @unlink($oldCitySwitchIconFile);
                }
                if ($oldLogoPath !== '' && $oldLogoPath !== $newLogoPath
                    && str_starts_with($oldLogoPath, 'uploads/regions/')) {
                    $oldFile = dirname(__DIR__) . '/' . $oldLogoPath;
                    if (is_file($oldFile)) @unlink($oldFile);
                }
                $successMessage = 'De kleuren, het logo en de filtericonen zijn opgeslagen.';
            }
        }
    }
}

$pageTitle = 'Kleuren en logo - ' . $region['name'];
require dirname(__DIR__, 2) . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">REGIOBEHEER</p>
            <h1>Kleuren en logo</h1>
            <p>Pas de vormgeving van <?= escapeHtml($region['name']) ?> aan.</p>
        </div>
        <a class="button" href="/admin/regions.php">Terug naar regio's</a>
    </div>
    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?><div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
        <?php if ($successMessage !== ''): ?><div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <h2>Basiskleuren</h2>
            <div class="region-color-grid">
            <?php foreach ([
                'primary_color' => 'Primaire kleur',
                'primary_dark' => 'Donkere primaire kleur',
                'accent_color' => 'Accentkleur',
                'secondary_color' => 'Secundaire kleur',
                'tertiary_color' => 'Tertiaire kleur',
                'header_color' => 'Headerkleur',
                'menu_color' => 'Menukleur',
                'organizer_color' => 'Achtergrondkleur van de organisatorknoppen',
                'filter_hover_color' => 'Hoverkleur van de filterknoppen (stad en organisator)',
                'filter_bar_color' => 'Achtergrondkleur van de filterbalk',
                'filter_text_color' => 'Tekstkleur van de filterknoppen',
                'search_button_color' => 'Achtergrondkleur zoekknop',
                'back_top_color' => 'Achtergrondkleur naar-bovenknop',
                'search_field_color' => 'Achtergrondkleur zoekveld',
                'search_text_color' => 'Tekstkleur zoekfunctie (invoer en placeholder)',
                'footer_color' => 'Achtergrondkleur footer',
                'background_color' => 'Achtergrondkleur',
                'text_color' => 'Tekstkleur',
            ] as $key => $label): ?>
                <label class="region-color-field" for="<?= escapeHtml($key) ?>">
                    <span><?= escapeHtml($label) ?></span>
                    <input type="color" id="<?= escapeHtml($key) ?>" name="<?= escapeHtml($key) ?>" value="<?= escapeHtml($colors[$key]) ?>">
                    <code><?= escapeHtml($colors[$key]) ?></code>
                </label>
            <?php endforeach; ?>
            </div>
            <h2>Kleuren per weekdag</h2>
            <div class="region-color-grid">
            <?php foreach (['Maandag','Dinsdag','Woensdag','Donderdag','Vrijdag','Zaterdag','Zondag'] as $i => $day): ?>
                <label class="region-color-field" for="weekday_<?= $i ?>">
                    <span><?= $day ?></span>
                    <input type="color" id="weekday_<?= $i ?>" name="weekday_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayColors[$i]) ?>">
                    <code><?= escapeHtml($weekdayColors[$i]) ?></code>
                </label>
            <?php endforeach; ?>
            </div>
            <h2>Kleuren van de agendapijlen</h2>
            <div class="region-color-grid">
                <label class="region-color-field" for="agenda_arrow_background"><span>Achtergrondkleur pijlen</span><input type="color" id="agenda_arrow_background" name="agenda_arrow_background" value="<?= escapeHtml($colors['agenda_arrow_background']) ?>"><code><?= escapeHtml($colors['agenda_arrow_background']) ?></code></label>
                <label class="region-color-field" for="agenda_arrow_text"><span>Kleur pijlsymbool</span><input type="color" id="agenda_arrow_text" name="agenda_arrow_text" value="<?= escapeHtml($colors['agenda_arrow_text']) ?>"><code><?= escapeHtml($colors['agenda_arrow_text']) ?></code></label>
            </div>
            <h2>Stadsicoon voor de filterbalk</h2>
            <p>Upload hier de afbeelding die bij het filter Steden / Plaatsen wordt gebruikt. PNG, JPG of WebP, maximaal 5 MB. De afbeelding wordt automatisch verkleind.</p>
            <?php if ($cityIconPath !== ''): ?>
                <p><img src="/<?= escapeHtml($cityIconPath) ?>" alt="Huidige stadsicoon" style="width:88px;height:88px;object-fit:contain"></p>
                <label class="checkbox-label"><input type="checkbox" name="delete_city_icon" value="1"> Huidige stadsicoon verwijderen</label>
            <?php endif; ?>
            <label for="city_icon">Nieuwe stadsicoon (PNG, JPG of WEBP)</label>
            <input type="file" id="city_icon" name="city_icon" accept="image/png,image/jpeg,image/webp">
            <h2>Stedenkeuze-icoon voor de filterknop</h2>
            <p>Dit is de aparte afbeelding op de knop waarmee je naar het stedenfilter schakelt. Dit staat los van de stadsicoontjes in de stedenlijst. PNG, JPG of WebP, maximaal 5 MB.</p>
            <?php if ($citySwitchIconPath !== ''): ?>
                <p><img src="/<?= escapeHtml($citySwitchIconPath) ?>" alt="Huidig stedenkeuze-icoon" style="width:88px;height:88px;object-fit:contain"></p>
                <label class="checkbox-label"><input type="checkbox" name="delete_city_switch_icon" value="1"> Huidig stedenkeuze-icoon verwijderen</label>
            <?php endif; ?>
            <label for="city_switch_icon">Nieuwe afbeelding voor de stedenkeuzeknop</label>
            <input type="file" id="city_switch_icon" name="city_switch_icon" accept="image/png,image/jpeg,image/webp">
            <h2>Organisatorenfilter-icoon</h2>
            <p>Afbeelding voor de knop waarmee je het organisatorenfilter kiest. PNG, JPG of WebP, maximaal 5 MB.</p>
            <?php if ($organizerIconPath !== ''): ?>
                <p><img src="/<?= escapeHtml($organizerIconPath) ?>" alt="Huidig organisatorenfilter-icoon" style="width:88px;height:88px;object-fit:contain"></p>
                <label class="checkbox-label"><input type="checkbox" name="delete_organizer_icon" value="1"> Huidig organisatorenfilter-icoon verwijderen</label>
            <?php endif; ?>
            <label for="organizer_icon">Nieuwe afbeelding organisatorenfilter</label>
            <input type="file" id="organizer_icon" name="organizer_icon" accept="image/png,image/jpeg,image/webp">
            <h2>Regiologo</h2>
            <?php if ($logoPath !== ''): ?>
                <p><img src="/<?= escapeHtml(ltrim($logoPath, '/')) ?>" alt="Huidig regiologo" style="max-width:220px;max-height:100px;object-fit:contain"></p>
                <label class="checkbox-label"><input type="checkbox" name="delete_logo" value="1"> Huidig logo verwijderen</label>
            <?php endif; ?>
            <label for="region_logo">Nieuw logo (PNG, JPG of WEBP, maximaal 5 MB)</label>
            <input type="file" id="region_logo" name="region_logo" accept="image/png,image/jpeg,image/webp">
            <div class="form-actions">
                <button type="submit" class="button button--primary">Kleuren en logo opslaan</button>
                <a class="button" href="/admin/regions.php">Annuleren</a>
            </div>
        </form>
    </section>
</section>
<style>
.region-color-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:14px 0 26px}
.region-color-field{display:grid;grid-template-columns:1fr 48px;align-items:center;gap:8px;padding:10px;border:1px solid #e1e5eb;border-radius:10px}
.region-color-field input[type=color]{width:48px;height:38px;padding:2px;border:1px solid #ddd;border-radius:7px;background:#fff}
.region-color-field code{grid-column:1/-1;color:#596273}
</style>
<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
