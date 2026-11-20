<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/ImageOptimization.php';

/**
 * Sla filtericonen op zonder ze naar WebP om te zetten.
 * Zo blijft het alfakanaal van transparante PNG/WebP-bestanden exact behouden.
 */
function storeFilterIconUpload(array $file, int $regionId, string $prefix): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('De afbeelding kon niet worden geüpload. Controleer de maximale uploadgrootte.');
    }

    $temporaryFile = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($temporaryFile === '' || !is_uploaded_file($temporaryFile) || $size < 1 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Kies een geldige afbeelding van maximaal 5 MB.');
    }

    $info = @getimagesize($temporaryFile);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    $extensions = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Gebruik een PNG-, WebP- of JPG-afbeelding.');
    }
    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000) {
        throw new RuntimeException('De afbeelding mag maximaal 6000 × 6000 pixels zijn.');
    }

    $directory = dirname(__DIR__) . '/uploads/regions';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('De map voor filtericonen kon niet worden aangemaakt.');
    }
    $filename = $prefix . $regionId . '-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporaryFile, $directory . '/' . $filename)) {
        throw new RuntimeException('De afbeelding kon niet ongewijzigd worden opgeslagen.');
    }
    return 'public/uploads/regions/' . $filename;
}

$db = Database::connection();
$embeddedRegionAppearanceEditor = !empty($embeddedRegionAppearanceEditor);
if ($embeddedRegionAppearanceEditor) {
    $regionId = (int) ($embeddedRegionId ?? 0);
} else {
    $regionId = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT);
}
if (!$regionId || $regionId < 1) {
    http_response_code(400);
    exit('Ongeldig regionummer.');
}

if (!$embeddedRegionAppearanceEditor) {
    requireRegionRole($regionId, ['region_admin']);
}

$regionQuery = $db->prepare("SELECT id, name, logo_path FROM regions WHERE id = :id LIMIT 1");
$regionQuery->execute(['id' => $regionId]);
$region = $regionQuery->fetch();

$fallbackTextSettingFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/region-event-fallback-text/' . $regionId . '.txt';
$eventFallbackText = 'SALSA · BACHATA · KIZOMBA';
if (is_file($fallbackTextSettingFile)) {
    $savedFallbackText = trim((string) file_get_contents($fallbackTextSettingFile));
    if ($savedFallbackText !== '') $eventFallbackText = mb_substr($savedFallbackText, 0, 100);
}
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
];
$weekdayDefaults = ['#F6D84A', '#4A90D9', '#55A96B', '#A78BDA', '#E78AAE', '#F49A45', '#999999'];
$select = $db->prepare("SELECT * FROM region_appearance WHERE region_id = :id LIMIT 1");
$select->execute(['id' => $regionId]);
$saved = $select->fetch() ?: [];
$pastEventColor = strtoupper((string) ($saved['past_event_color'] ?? '#777777'));
if (!preg_match('/^#[0-9A-F]{6}$/', $pastEventColor)) $pastEventColor = '#777777';
$pastEventTextColor = '#FFFFFF';
$selectorStripBackgroundColor = '#FFFFFF';
$colors = [];
foreach ($defaults as $key => $value) {
    $candidate = (string) ($saved[$key] ?? $value);
    $colors[$key] = preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) ? strtoupper($candidate) : $value;
}
$weekdayColors = $weekdayDefaults;
$weekdayTextDefaults = array_fill(0, 7, '#222222');
$weekdayTextColors = $weekdayTextDefaults;
$weekdayTitleDefaults = array_fill(0, 7, '#222222');
$weekdayTitleColors = $weekdayTitleDefaults;
$eventTitleColor = '#222222';
$separatorColor = '#D0D0D0';
$iconColorDefaults = [
    'back_top_icon_color' => '#FFFFFF',
    'search_icon_color' => '#FFFFFF',
    'speaker_icon_color' => '#FFFFFF',
];
$iconColors = $iconColorDefaults;
if (!empty($saved['weekday_colors'])) {
    $decoded = json_decode((string) $saved['weekday_colors'], true);
    if (is_array($decoded)) {
        $stripCandidate = strtoupper((string) ($decoded['selector_strip_background_color'] ?? '#FFFFFF'));
        $selectorStripBackgroundColor = preg_match('/^#[0-9A-F]{6}$/', $stripCandidate) ? $stripCandidate : '#FFFFFF';
        $separatorCandidate = strtoupper((string) ($decoded['event_separator_color'] ?? '#D0D0D0'));
        $separatorColor = preg_match('/^#[0-9A-F]{6}$/', $separatorCandidate) ? $separatorCandidate : '#D0D0D0';
        $pastTextCandidate = strtoupper((string) ($decoded['past_event_text_color'] ?? '#FFFFFF'));
        $pastEventTextColor = preg_match('/^#[0-9A-F]{6}$/', $pastTextCandidate) ? $pastTextCandidate : '#FFFFFF';
        $titleCandidate = (string) ($decoded['event_title_color'] ?? '#222222');
        $eventTitleColor = preg_match('/^#[0-9a-fA-F]{6}$/', $titleCandidate) ? strtoupper($titleCandidate) : '#222222';
        $weekdayTitleDefaults = array_fill(0, 7, $eventTitleColor);
        foreach ($iconColorDefaults as $iconKey => $iconDefault) {
            $candidate = (string) ($decoded[$iconKey] ?? $iconDefault);
            $iconColors[$iconKey] = preg_match('/^#[0-9a-fA-F]{6}$/', $candidate) ? strtoupper($candidate) : $iconDefault;
        }
        foreach ($weekdayDefaults as $i => $value) {
            if (isset($decoded[$i]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $decoded[$i])) {
                $weekdayColors[$i] = strtoupper((string) $decoded[$i]);
            }
        }
        foreach ($weekdayTextDefaults as $i => $value) {
            $key = 'weekday_text_' . $i;
            if (isset($decoded[$key]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $decoded[$key])) {
                $weekdayTextColors[$i] = strtoupper((string) $decoded[$key]);
            }
            $titleKey = 'weekday_title_' . $i;
            if (isset($decoded[$titleKey]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $decoded[$titleKey])) {
                $weekdayTitleDefaults[$i] = strtoupper((string) $decoded[$titleKey]);
            }
        }
        $weekdayTitleColors = $weekdayTitleDefaults;
    }
}
$logoPath = $saved !== []
    ? (string) ($saved['logo_path'] ?? '')
    : (string) ($region['logo_path'] ?? '');

$cityIconSettingFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/region-city-icons/' . $regionId . '.txt';
$organizerIconSettingFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/region-organizer-icons/' . $regionId . '.txt';
$citySwitchIconSettingFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/region-city-switch-icons/' . $regionId . '.txt';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$embeddedRegionAppearanceEditor || !empty($_POST['save_appearance']))) {
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
                $uploadedCityIcon = storeFilterIconUpload(
                    $_FILES['city_icon'],
                    $regionId,
                    'city-icon-'
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
                $uploadedOrganizerIcon = storeFilterIconUpload(
                    $_FILES['organizer_icon'],
                    $regionId,
                    'organizer-icon-'
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
                $uploadedCitySwitchIcon = storeFilterIconUpload(
                    $_FILES['city_switch_icon'],
                    $regionId,
                    'city-switch-icon-'
                );
                if ($uploadedCitySwitchIcon !== null) {
                    $newCitySwitchIconPath = substr($uploadedCitySwitchIcon, strlen('public/'));
                }
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        $separatorCandidate = strtoupper(trim((string) ($_POST['event_separator_color'] ?? $separatorColor)));
        if (!preg_match('/^#[0-9A-F]{6}$/', $separatorCandidate)) {
            $errorMessage = 'De kleur van de scheidingslijn is ongeldig.';
        } else {
            $separatorColor = $separatorCandidate;
        }

        $pastEventCandidate = strtoupper(trim((string) ($_POST['past_event_color'] ?? $pastEventColor)));
        if (!preg_match('/^#[0-9A-F]{6}$/', $pastEventCandidate)) {
            $errorMessage = 'De kleur van afgelopen evenementen is ongeldig.';
        } else {
            $pastEventColor = $pastEventCandidate;
        }
        $pastTextCandidate = strtoupper(trim((string) ($_POST['past_event_text_color'] ?? $pastEventTextColor)));
        if (!preg_match('/^#[0-9A-F]{6}$/', $pastTextCandidate)) {
            $errorMessage = 'De tekstkleur van afgelopen evenementen is ongeldig.';
        } else {
            $pastEventTextColor = $pastTextCandidate;
        }

        $stripColorCandidate = strtoupper(trim((string) ($_POST['selector_strip_background_color'] ?? $selectorStripBackgroundColor)));
        if (!preg_match('/^#[0-9A-F]{6}$/', $stripColorCandidate)) {
            $errorMessage = 'De achtergrondkleur van de locatie- en organisatorbalk is ongeldig.';
        } else {
            $selectorStripBackgroundColor = $stripColorCandidate;
        }

        foreach ($defaults as $key => $default) {
            $value = strtoupper(trim((string) ($_POST[$key] ?? $default)));
            if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
                $errorMessage = 'Een of meer kleurcodes zijn ongeldig.';
                break;
            }
            $colors[$key] = $value;
        }
        foreach ($iconColorDefaults as $iconKey => $iconDefault) {
            $value = strtoupper(trim((string) ($_POST[$iconKey] ?? $iconColors[$iconKey])));
            if (!preg_match('/^#[0-9A-F]{6}$/', $value)) {
                $errorMessage = 'Een of meer icoonkleuren zijn ongeldig.';
                break;
            }
            $iconColors[$iconKey] = $value;
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
            $textValue = strtoupper(trim((string) ($_POST['weekday_text_colors'][$i] ?? $weekdayTextDefaults[$i])));
            if (!preg_match('/^#[0-9A-F]{6}$/', $textValue)) {
                $errorMessage = 'Een of meer tekstkleuren van de weekdagen zijn ongeldig.';
                break;
            }
            $weekdayTextColors[$i] = $textValue;
            $titleValue = strtoupper(trim((string) ($_POST['weekday_title_colors'][$i] ?? $weekdayTitleDefaults[$i])));
            if (!preg_match('/^#[0-9A-F]{6}$/', $titleValue)) {
                $errorMessage = 'Een of meer tekstkleuren van de evenementtitels zijn ongeldig.';
                break;
            }
            $weekdayTitleColors[$i] = $titleValue;
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
                        'uploads/regions',
                        800,
                        5 * 1024 * 1024,
                        'region-' . $regionId . '-',
                        dirname(__DIR__)
                    );
                    if ($uploadedRegionLogo !== null) {
                        $newLogoPath = $uploadedRegionLogo;
                    }
                } catch (RuntimeException $exception) {
                    $errorMessage = $exception->getMessage();
                }
            }

            if ($errorMessage === '') {
                $upsert = $db->prepare("INSERT INTO region_appearance
                    (region_id, primary_color, primary_dark, accent_color, secondary_color,
                     tertiary_color, header_color, menu_color, organizer_color, filter_hover_color, filter_bar_color, filter_text_color, past_event_color,
                     search_button_color, back_top_color, search_field_color, search_text_color, footer_color, background_color, text_color, weekday_colors, logo_path)
                    VALUES (:region_id, :primary_color, :primary_dark, :accent_color, :secondary_color,
                     :tertiary_color, :header_color, :menu_color, :organizer_color, :filter_hover_color, :filter_bar_color, :filter_text_color, :past_event_color,
                     :search_button_color, :back_top_color, :search_field_color, :search_text_color, :footer_color, :background_color, :text_color, :weekday_colors, :logo_path)
                    ON DUPLICATE KEY UPDATE primary_color=VALUES(primary_color),
                     primary_dark=VALUES(primary_dark), accent_color=VALUES(accent_color),
                     secondary_color=VALUES(secondary_color), tertiary_color=VALUES(tertiary_color),
                     header_color=VALUES(header_color), menu_color=VALUES(menu_color),
                     organizer_color=VALUES(organizer_color), filter_hover_color=VALUES(filter_hover_color),
                     filter_bar_color=VALUES(filter_bar_color), filter_text_color=VALUES(filter_text_color),
                     past_event_color=VALUES(past_event_color),
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
                    'past_event_color' => $pastEventColor,
                    'search_button_color' => $colors['search_button_color'],
                    'back_top_color' => $colors['back_top_color'],
                    'search_field_color' => $colors['search_field_color'],
                    'search_text_color' => $colors['search_text_color'],
                    'footer_color' => $colors['footer_color'],
                    'background_color' => $colors['background_color'],
                    'text_color' => $colors['text_color'],
                    'weekday_colors' => json_encode($weekdayColors + array_combine(
                        array_map(static fn (int $i): string => 'weekday_text_' . $i, array_keys($weekdayTextColors)),
                        array_values($weekdayTextColors)
                    ) + array_combine(
                        array_map(static fn (int $i): string => 'weekday_title_' . $i, array_keys($weekdayTitleColors)),
                        array_values($weekdayTitleColors)
                    ) + [
                        'selector_strip_background_color' => $selectorStripBackgroundColor,
                        'event_separator_color' => $separatorColor,
                        'past_event_text_color' => $pastEventTextColor,
                    ] + $iconColors + ['event_title_color' => $eventTitleColor], JSON_THROW_ON_ERROR),
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
                $newFallbackText = trim((string) ($_POST['event_fallback_text'] ?? $eventFallbackText));
                $newFallbackText = mb_substr($newFallbackText, 0, 100);
                $fallbackDirectory = dirname($fallbackTextSettingFile);
                if (!is_dir($fallbackDirectory) && !mkdir($fallbackDirectory, 0755, true) && !is_dir($fallbackDirectory)) {
                    throw new RuntimeException('De map voor de regiotekst kon niet worden aangemaakt.');
                }
                if (file_put_contents($fallbackTextSettingFile, $newFallbackText . PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('De regiotekst kon niet worden opgeslagen.');
                }
                $eventFallbackText = $newFallbackText !== '' ? $newFallbackText : 'SALSA · BACHATA · KIZOMBA';
                $successMessage = 'De kleuren, het logo, de filtericonen en de evenementtekst zijn opgeslagen.';
            }
        }
    }
}

$pageTitle = 'Kleuren en logo - ' . $region['name'];
// Deze regionale instellingenpagina gebruikt altijd de algemene platformkop en platformkleuren.
$platformAdminPage = true;
if (!$embeddedRegionAppearanceEditor) {
    require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
}
?>
<?php if (!$embeddedRegionAppearanceEditor): ?>
<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">REGIOBEHEER</p>
            <h1>Kleuren en logo</h1>
            <p>Pas de vormgeving van <?= escapeHtml($region['name']) ?> aan.</p>
        </div>
        <a class="button" href="/admin/regions.php">Terug naar regio's</a>
    </div>
<?php endif; ?>
    <section class="admin-section">
        <?php if ($embeddedRegionAppearanceEditor): ?>
            <div class="section-heading"><div><h2>Regionale kleuren en logo</h2><p>Deze instellingen worden samen met de regio opgeslagen.</p></div></div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?><div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
        <?php if ($successMessage !== ''): ?><div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div><?php endif; ?>
        <section class="region-agenda-live-preview" id="region-agenda-live-preview" aria-label="Live voorbeeld van de regionale agenda">
            <div class="region-preview-heading">
                <div><h2>Live voorbeeld agenda</h2><p>Dezelfde agenda-indeling als op de startpagina. Wijzig kleuren en bekijk direct het resultaat.</p></div>
                <span class="region-preview-live">LIVE VOORBEELD</span>
            </div>
            <div class="region-preview-shell" data-preview="page">
                <div class="region-preview-topbar" data-preview="header">
                    <strong class="region-preview-brand">DONDEBAILAR</strong>
                    <span>Agenda · Evenementen · Menu</span>
                </div>
                <div class="region-preview-agenda">
                    <div class="region-preview-title"><strong><?= escapeHtml($region['name']) ?></strong><span>Agenda de baile</span></div>
                    <div class="region-preview-filters" data-preview="strip">
                        <span data-preview="filter-text">Alle steden</span><span data-preview="filter-text">Alle organisatoren</span>
                        <input aria-label="Zoeken" value="Zoek evenement..." readonly data-preview="search-field">
                        <button type="button" data-preview="search-button">⌕</button>
                    </div>
                    <div class="region-preview-calendar" aria-label="Weekdagen">
                        <?php foreach (['MA','DI','WO','DO','VR','ZA','ZO'] as $i => $day): ?>
                            <div class="region-preview-calendar-day" data-preview-day="<?= $i ?>"><strong><?= $day ?></strong><span><?= 16 + $i ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="region-preview-events">
                        <h3 class="agenda-day-heading" data-preview="day-heading">VIERNES 20 NOVIEMBRE 2026</h3>
                        <article class="agenda-card region-preview-agenda-card" data-preview="event" style="--db-event-day-color:var(--db-weekday-5,var(--db-primary));--db-event-day-text-color:var(--db-weekday-text-5,#222);--db-event-day-title-color:var(--db-weekday-title-5,#222)">
                            <div class="agenda-card__media-wrap">
                                <div class="agenda-card__placeholder" data-preview="event-media"><strong>SALSA · BACHATA · KIZOMBA</strong></div>
                            </div>
                            <div class="agenda-card__quick">
                                <span class="region-preview-quick-icon">👠 <b>12</b></span><span class="region-preview-quick-icon">↗</span><span class="region-preview-quick-icon">⌖</span>
                            </div>
                            <div class="agenda-card__details">
                                <h2><span data-preview="event-title">Salsa &amp; Bachata Social</span></h2>
                                <p class="agenda-card__date-line" data-preview="event-date">VIERNES, 20 NOV 2026 <span>◷</span> 20:00</p>
                                <p class="agenda-card__hashtags" data-preview="event-tags">#Bachata #Dénia</p>
                                <p class="region-preview-event-description">Dansavond voor iedereen · Dénia</p>
                            </div>
                        </article>
                        <h3 class="agenda-day-heading" data-preview="day-heading">SÁBADO 21 NOVIEMBRE 2026</h3>
                        <article class="agenda-card region-preview-agenda-card" data-preview="event-second" style="--db-event-day-color:var(--db-weekday-6,var(--db-primary));--db-event-day-text-color:var(--db-weekday-text-6,#222);--db-event-day-title-color:var(--db-weekday-title-6,#222)">
                            <div class="agenda-card__media-wrap"><div class="agenda-card__placeholder" data-preview="event-media-second"><strong>BACHATA SOCIAL</strong></div></div>
                            <div class="agenda-card__quick"><span class="region-preview-quick-icon">👠 <b>8</b></span><span class="region-preview-quick-icon">↗</span></div>
                            <div class="agenda-card__details">
                                <h2><span>Latin Dance Night</span></h2>
                                <p class="agenda-card__date-line">SÁBADO, 21 NOV 2026 <span>◷</span> 21:00</p>
                                <p class="agenda-card__hashtags">#Salsa #Bachata #Jávea</p>
                            </div>
                        </article>
                        <h3 class="agenda-day-heading" data-preview="past-heading">JUEVES 19 NOVIEMBRE 2026</h3>
                        <article class="agenda-card agenda-card--past region-preview-agenda-card" data-preview="past-event" style="--db-event-day-color:#777;--db-event-day-text-color:#fff;--db-event-day-title-color:#fff">
                            <div class="agenda-card__media-wrap"><div class="agenda-card__placeholder"><strong>EVENTO ANTERIOR</strong></div></div>
                            <div class="agenda-card__details"><h2><span>Afgelopen evenement</span></h2><p class="agenda-card__date-line">JUEVES, 19 NOV 2026 <span>◷</span> 20:30</p></div>
                        </article>
                    </div>
                    <div class="region-preview-footer" data-preview="footer"><span>DONDEBAILAR · <?= escapeHtml($region['name']) ?></span><button type="button" data-preview="back-top">↑</button></div>
                </div>
            </div>
        </section>
        <form method="post" enctype="multipart/form-data" class="auth-form" action="<?= $embeddedRegionAppearanceEditor ? '/admin/region-edit.php?id=' . (int) $regionId : '/admin/region-colors.php?region_id=' . (int) $regionId ?>">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <?php if ($embeddedRegionAppearanceEditor): ?><input type="hidden" name="save_appearance" value="1"><?php endif; ?>
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
                'filter_text_color' => 'Tekstkleur van de filterknoppen',
                'search_field_color' => 'Achtergrondkleur zoekveld',
                'search_text_color' => 'Tekstkleur zoekfunctie (invoer en placeholder)',
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
            <h2>Locatie- en organisatorbalk</h2>
            <p>Stel de achtergrondkleur van de horizontale balk met steden en organisatoren afzonderlijk in.</p>
            <div class="region-color-grid">
                <label class="region-color-field" for="selector_strip_background_color"><span>Achtergrondkleur locatie- en organisatorbalk</span><input type="color" id="selector_strip_background_color" name="selector_strip_background_color" value="<?= escapeHtml($selectorStripBackgroundColor) ?>"><code><?= escapeHtml($selectorStripBackgroundColor) ?></code></label>
            </div>
            <h2>Afgelopen evenementen</h2>
            <p>Stel de achtergrondkleur van afgelopen evenementenkaarten afzonderlijk in, los van de weekdagkleuren.</p>
            <div class="region-color-grid">
                <label class="region-color-field" for="past_event_color"><span>Achtergrondkleur afgelopen evenementen</span><input type="color" id="past_event_color" name="past_event_color" value="<?= escapeHtml($pastEventColor) ?>"><code><?= escapeHtml($pastEventColor) ?></code></label>
                <label class="region-color-field" for="past_event_text_color"><span>Tekstkleur afgelopen evenementen</span><input type="color" id="past_event_text_color" name="past_event_text_color" value="<?= escapeHtml($pastEventTextColor) ?>"><code><?= escapeHtml($pastEventTextColor) ?></code></label>
            </div>
            <h2>Scheidingslijn tussen evenementen</h2>
            <p>Kies de kleur van de horizontale lijn onder de evenementenkaarten.</p>
            <div class="region-color-grid">
                <label class="region-color-field" for="event_separator_color"><span>Kleur scheidingslijn</span><input type="color" id="event_separator_color" name="event_separator_color" value="<?= escapeHtml($separatorColor) ?>"><code><?= escapeHtml($separatorColor) ?></code></label>
            </div>
            <h2>Weekdagen</h2>
            <p>Stel per weekdag de achtergrondkleur, de dagtekstkleur en de tekstkleur van de evenementtitel afzonderlijk in.</p>
            <div class="region-weekday-grid">
            <?php foreach (['Maandag','Dinsdag','Woensdag','Donderdag','Vrijdag','Zaterdag','Zondag'] as $i => $day): ?>
                <div class="region-weekday-card">
                    <strong><?= $day ?></strong>
                    <div class="region-weekday-setting">
                        <label for="weekday_<?= $i ?>">Achtergrond</label>
                        <div class="region-weekday-color">
                            <input type="color" id="weekday_<?= $i ?>" name="weekday_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayColors[$i]) ?>">
                            <code><?= escapeHtml($weekdayColors[$i]) ?></code>
                        </div>
                    </div>
                    <div class="region-weekday-setting">
                        <label for="weekday_text_<?= $i ?>">Tekstkleur dag</label>
                        <div class="region-weekday-color">
                            <input type="color" id="weekday_text_<?= $i ?>" name="weekday_text_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayTextColors[$i]) ?>">
                            <code><?= escapeHtml($weekdayTextColors[$i]) ?></code>
                        </div>
                    </div>
                    <div class="region-weekday-setting">
                        <label for="weekday_title_<?= $i ?>">Tekstkleur titel</label>
                        <div class="region-weekday-color">
                            <input type="color" id="weekday_title_<?= $i ?>" name="weekday_title_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayTitleColors[$i]) ?>">
                            <code><?= escapeHtml($weekdayTitleColors[$i]) ?></code>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <h2>Footer en footerknoppen</h2>
            <p>Alle kleuren die bij de footer horen staan hier samen. Je kunt de achtergrond van de footer en knoppen apart instellen van de kleur van de pictogrammen.</p>
            <div class="region-color-grid">
                <?php foreach ([
                    'footer_color' => 'Achtergrondkleur footer',
                    'search_button_color' => 'Achtergrondkleur zoekknop',
                    'back_top_color' => 'Achtergrondkleur terug-naar-bovenknop',
                ] as $key => $label): ?>
                    <label class="region-color-field" for="<?= escapeHtml($key) ?>">
                        <span><?= escapeHtml($label) ?></span>
                        <input type="color" id="<?= escapeHtml($key) ?>" name="<?= escapeHtml($key) ?>" value="<?= escapeHtml($colors[$key]) ?>">
                        <code><?= escapeHtml($colors[$key]) ?></code>
                    </label>
                <?php endforeach; ?>
                <?php foreach ([
                    'back_top_icon_color' => 'Kleur pijl terug-naar-bovenknop',
                    'search_icon_color' => 'Kleur zoeklogo (vergrootglas)',
                    'speaker_icon_color' => 'Kleur luidsprekerlogo',
                ] as $key => $label): ?>
                    <label class="region-color-field" for="<?= escapeHtml($key) ?>">
                        <span><?= escapeHtml($label) ?></span>
                        <input type="color" id="<?= escapeHtml($key) ?>" name="<?= escapeHtml($key) ?>" value="<?= escapeHtml($iconColors[$key]) ?>">
                        <code><?= escapeHtml($iconColors[$key]) ?></code>
                    </label>
                <?php endforeach; ?>
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
            <h2>Tekst bij evenementen zonder media</h2>
            <p>Deze tekst verschijnt op de evenementkaart wanneer er geen afbeelding of video is toegevoegd. De naam van het evenement wordt eronder getoond.</p>
            <label for="event_fallback_text">Tekst voor deze regio (maximaal 100 tekens)</label>
            <input type="text" id="event_fallback_text" name="event_fallback_text" maxlength="100" value="<?= escapeHtml($eventFallbackText) ?>">
            <h2>Regiologo</h2>
            <?php if ($logoPath !== ''): ?>
                <p><img src="/<?= escapeHtml(ltrim($logoPath, '/')) ?>" alt="Huidig regiologo" style="max-width:220px;max-height:100px;object-fit:contain"></p>
                <label class="checkbox-label"><input type="checkbox" name="delete_logo" value="1"> Huidig logo verwijderen</label>
            <?php endif; ?>
            <label for="region_logo">Nieuw logo (PNG, JPG of WEBP, maximaal 5 MB)</label>
            <input type="file" id="region_logo" name="region_logo" accept="image/png,image/jpeg,image/webp">
            <div class="form-actions">
                <button type="submit" class="button button--primary">Kleuren en logo opslaan</button>
                <a class="button" href="/admin/region-edit.php?id=<?= (int) $regionId ?>">Terug naar regio</a>
            </div>
        </form>
    </section>
</section>
<?php if (!$embeddedRegionAppearanceEditor): ?>
</section>
<?php endif; ?>
<style>
.region-color-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:14px 0 30px}
.region-color-field{display:grid;grid-template-columns:minmax(0,1fr) 52px;align-items:center;gap:8px 12px;min-height:64px;padding:12px;border:1px solid #e1e5eb;border-radius:10px;background:#fff}
.region-color-field span{font-size:13px;line-height:1.35;font-weight:600}
.region-color-field input[type=color],.region-weekday-color input[type=color]{width:52px;height:40px;padding:3px;border:1px solid #d5dbe3;border-radius:8px;background:#fff;cursor:pointer}
.region-color-field code,.region-weekday-color code{grid-column:1/-1;color:#596273;font-size:12px}
.admin-section .auth-form>h2{margin:30px 0 8px;padding-bottom:10px;border-bottom:1px solid #e5e9ef}
.admin-section .auth-form>h2:first-of-type{margin-top:8px}
.region-weekday-grid{display:grid;grid-template-columns:1fr;gap:8px;margin:14px 0 30px}
.region-weekday-card{display:grid;grid-template-columns:minmax(105px,150px) repeat(3,minmax(0,1fr));align-items:center;gap:10px;padding:10px 14px;border:1px solid #e1e5eb;border-radius:9px;background:#fff}
.region-weekday-card>strong{font-size:14px}
.region-weekday-setting{min-width:0;display:flex;flex-direction:column;gap:4px}
.region-weekday-setting>label{color:#596273;font-size:11px;font-weight:600;line-height:1.2}
.region-weekday-color{display:flex;align-items:center;gap:7px;min-width:0}
.region-weekday-color input[type=color]{width:42px;height:34px;flex:0 0 42px;padding:2px}
.region-weekday-color code{font-size:11px;color:#596273}
@media(max-width:640px){
 .region-color-grid{grid-template-columns:1fr}
 .region-weekday-card{grid-template-columns:86px repeat(3,minmax(0,1fr));gap:6px;padding:8px}
 .region-weekday-card>strong{font-size:12px}
 .region-weekday-setting>label{font-size:10px}
 .region-weekday-color{gap:3px;flex-wrap:wrap}
 .region-weekday-color input[type=color]{width:34px;height:30px;flex-basis:34px}
 .region-weekday-color code{font-size:10px}
 .region-color-field{grid-template-columns:minmax(0,1fr) 52px}
}
@media(max-width:390px){
 .region-weekday-card{grid-template-columns:72px repeat(3,minmax(0,1fr));gap:4px;padding:6px}
 .region-weekday-color input[type=color]{width:30px;height:28px;flex-basis:30px}
 .region-weekday-color code{font-size:9px}
}

.region-agenda-live-preview{margin:4px 0 26px;padding:16px;border:1px solid var(--db-border,#e1e5eb);border-radius:12px;background:var(--db-surface,#fff)}
.region-preview-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.region-preview-heading h2{margin:0 0 4px!important;padding:0!important;border:0!important;font-size:18px}
.region-preview-heading p{margin:0;color:var(--db-muted,#687586);font-size:13px}
.region-preview-live{padding:5px 9px;border-radius:20px;background:#eaf7f0;color:#16845b;font-size:10px;font-weight:800;white-space:nowrap}
.region-preview-shell{overflow:hidden;border:1px solid #dce2e9;border-radius:10px;background:#fff;color:#222;font-size:12px}
.region-preview-topbar{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:11px 14px;background:#222;color:#fff}
.region-preview-brand{font-size:14px;letter-spacing:.5px}
.region-preview-agenda{padding:12px;background:#fff;color:#222}
.region-preview-title{display:flex;align-items:baseline;gap:9px;margin:0 0 10px;color:#222}
.region-preview-title strong{font-size:17px}.region-preview-title span{color:#687586}
.region-preview-filters{display:flex;align-items:center;gap:7px;flex-wrap:wrap;padding:8px;background:#fff}
.region-preview-filters span,.region-preview-filters input{min-width:0;padding:7px 9px;border:1px solid #ddd;border-radius:5px;background:#fff;color:#222}
.region-preview-filters input{flex:1;width:90px}
.region-preview-filters button{border:0;border-radius:5px;padding:7px 12px;background:#e23a2c;color:#fff;font-weight:700}
.region-preview-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;margin:10px 0 14px}
.region-preview-calendar-day{display:flex;flex-direction:column;align-items:center;gap:3px;padding:7px 2px;border-radius:5px;background:#f6d84a;color:#222}
.region-preview-calendar-day strong{font-size:10px}.region-preview-calendar-day span{font-size:12px;font-weight:700}
.region-preview-events{display:block}
.region-preview-events .agenda-day-heading{margin:12px 0 8px;padding-bottom:6px;font-size:13px}
.region-preview-agenda-card{display:block;margin:0 0 14px;overflow:hidden;border-radius:10px}
.region-preview-agenda-card .agenda-card__media-wrap{height:125px}
.region-preview-agenda-card .agenda-card__placeholder{display:flex;align-items:center;justify-content:center;min-height:125px;padding:12px;background:var(--db-event-day-color,var(--db-primary));color:var(--db-event-day-title-color,#222);text-align:center}
.region-preview-agenda-card .agenda-card__placeholder strong{font-size:clamp(20px,4vw,32px)}
.region-preview-agenda-card .agenda-card__quick{display:flex;justify-content:flex-end;gap:14px;padding:6px 12px;background:var(--db-event-day-color,var(--db-primary));color:var(--db-event-day-text-color,#222)}
.region-preview-quick-icon{font-size:13px}
.region-preview-agenda-card .agenda-card__details{padding:12px 14px;background:var(--db-event-day-color,var(--db-primary));color:var(--db-event-day-text-color,#222)}
.region-preview-agenda-card .agenda-card__details h2{margin:0 0 6px;font-size:18px;color:var(--db-event-day-title-color,#222)}
.region-preview-agenda-card .agenda-card__details h2 span{color:inherit}
.region-preview-agenda-card .agenda-card__date-line{margin:0 0 6px;font-size:11px}
.region-preview-agenda-card .agenda-card__hashtags{margin:0;font-size:11px}
.region-preview-event-description{margin:7px 0 0;font-size:12px}
.region-preview-footer{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px;padding:10px;border-top:2px solid #ddd;background:#fff;color:#687586}
.region-preview-footer button{border:0;border-radius:5px;padding:7px 12px;background:#e23a2c;color:#fff;font-weight:700}
@media(max-width:520px){.region-agenda-live-preview{padding:10px}.region-preview-topbar{padding:9px}.region-preview-agenda{padding:8px}.region-preview-agenda-card .agenda-card__media-wrap,.region-preview-agenda-card .agenda-card__placeholder{height:100px;min-height:100px}.region-preview-agenda-card .agenda-card__details{padding:10px}.region-preview-calendar{gap:2px}.region-preview-calendar-day{padding:6px 1px}}

</style>
<script>
(function(){
 const root=document.getElementById("region-agenda-live-preview"); if(!root)return;
 const field=(name)=>document.querySelector('[name="'+name+'"]');
 const value=(name,fallback)=>field(name)?.value||fallback;
 const paint=(selector,property,color)=>root.querySelectorAll(selector).forEach(el=>el.style.setProperty(property,color,"important"));
 function update(){
  const primary=value("primary_color","#E23A2C"), header=value("header_color","#222222");
  const strip=value("selector_strip_background_color","#FFFFFF"), stripText=value("filter_text_color","#222222");
  const search=value("search_button_color",primary), organizer=value("organizer_color",primary);
  const footer=value("footer_color","#FFFFFF"), back=value("back_top_color",primary);
  const bg=value("background_color","#FFFFFF"), text=value("text_color","#222222");
  const past=value("past_event_color","#777777"), pastText=value("past_event_text_color","#FFFFFF");
  paint("[data-preview=header]","background-color",header); paint("[data-preview=header]","color","#FFFFFF");
  paint("[data-preview=page],.region-preview-agenda","background-color",bg); paint("[data-preview=page],.region-preview-agenda","color",text);
  paint(".region-preview-title","color",text);
  paint("[data-preview=strip]","background-color",strip); paint("[data-preview=filter-text]","color",stripText);
  paint("[data-preview=search-field]","background-color",value("search_field_color","#FFFFFF")); paint("[data-preview=search-field]","color",value("search_text_color","#222222"));
  paint("[data-preview=search-button]","background-color",search); paint("[data-preview=search-button]","color","#FFFFFF");
  paint(".region-preview-calendar-day","background-color",value("weekday_colors[0]","#F6D84A"));
  root.querySelectorAll(".region-preview-calendar-day").forEach((el,i)=>{el.style.setProperty("background-color",value("weekday_colors["+i+"]","#F6D84A"),"important");el.style.setProperty("color",value("weekday_text_colors["+i+"]","#222222"),"important")});
  const days=[5,6];
  root.querySelectorAll("[data-preview=event],[data-preview=event-second]").forEach((card,index)=>{
    const day=days[index]; const bgColor=value("weekday_colors["+day+"]",index===0?"#E78AAE":"#F49A45");
    const dayText=value("weekday_text_colors["+day+"]","#222222");
    const titleText=value("weekday_title_colors["+day+"]",text);
    card.style.setProperty("--db-event-day-color",bgColor);
    card.style.setProperty("--db-event-day-text-color",dayText);
    card.style.setProperty("--db-event-day-title-color",titleText);
    card.querySelector(".agenda-card__details")?.style.setProperty("color",dayText,"important");
    card.querySelector(".agenda-card__details h2")?.style.setProperty("color",titleText,"important");
    card.querySelector(".agenda-card__quick")?.style.setProperty("color",dayText,"important");
  });
  paint("[data-preview=event-media]","background-color",value("weekday_colors[4]","#E78AAE"));
  paint("[data-preview=past-event] .agenda-card__details","background-color",past);
  paint("[data-preview=past-event] .agenda-card__details","color",pastText);
  paint("[data-preview=past-event] .agenda-card__details h2","color",pastText);
  paint("[data-preview=footer]","background-color",footer); paint("[data-preview=footer]","color",text);
  paint("[data-preview=back-top]","background-color",back); paint("[data-preview=back-top]","color","#FFFFFF");
  root.querySelectorAll(".region-color-field input[type=color],.region-weekday-color input[type=color]").forEach(input=>{const code=input.parentElement.querySelector("code");if(code)code.textContent=input.value.toUpperCase()});
 }
 document.querySelectorAll(".auth-form input[type=color]").forEach(input=>{input.addEventListener("input",update);input.addEventListener("change",update)});
 update();
})();
</script>
<?php if (!$embeddedRegionAppearanceEditor) require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
