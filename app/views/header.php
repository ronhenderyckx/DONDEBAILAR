<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Authorization.php';
require_once dirname(__DIR__) . '/Security.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle)
    ? $pageTitle
    : 'DONDEBAILAR';

$region = isset($region) && is_array($region) ? $region : null;
$headerUser = currentUser();
$headerDisplayName = $headerUser !== null
    ? trim((string) ($headerUser['display_name'] ?? $headerUser['username'] ?? ''))
    : '';

$unreadNotificationCount = 0;
if ($headerUser !== null) {
    // De tabel kan ontbreken wanneer een database-update eerder is afgebroken.
    // Laat in dat geval de UPDATE-pagina zelf de migratiefout tonen.
    $notificationTableExists = Database::connection()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'user_notifications'
           AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchColumn();

    if ((int) $notificationTableExists > 0) {
        $notificationCountQuery = Database::connection()->prepare(
            "SELECT COUNT(*) FROM user_notifications WHERE user_id = :user_id AND is_read = 0"
        );
        $notificationCountQuery->execute(['user_id' => (int) $headerUser['id']]);
        $unreadNotificationCount = (int) $notificationCountQuery->fetchColumn();
    }
}

$regionId = (int) ($region['id'] ?? 0);
$canManageRegion = $regionId > 0
    && userHasRegionRole($regionId, ['region_admin', 'editor'], $headerUser);

$isAdmin = isPlatformAdmin($headerUser);
$canManageLocations = $isAdmin;
if ($headerUser !== null && !$canManageLocations) {
    $locationAccess = Database::connection()->prepare(
        "SELECT 1 FROM user_region_roles
         WHERE user_id = :user_id AND role IN ('editor', 'region_admin')
         LIMIT 1"
    );
    $locationAccess->execute(['user_id' => (int) $headerUser['id']]);
    $canManageLocations = (bool) $locationAccess->fetchColumn();
}

$brandName = $region && !empty($region['name'])
    ? (string) $region['name']
    : 'DONDEBAILAR';

$projectRoot = dirname(__DIR__, 2);
$platformLogoPath = '';
$platformLogoSetting = $projectRoot . '/storage/platform-logo-path.txt';
if (is_file($platformLogoSetting)) {
    $configuredLogo = trim((string) file_get_contents($platformLogoSetting));
    if (preg_match('#^/uploads/platform/[a-zA-Z0-9._-]+$#', $configuredLogo)) {
        $platformLogoPath = $configuredLogo;
    }
}

$regionLogoPath = '';
if ($region !== null) {
    $candidateRegionLogo = '/' . ltrim(trim((string) ($region['logo_path'] ?? '')), '/');
    if (preg_match('#^/uploads/regions/[a-zA-Z0-9._/-]+$#', $candidateRegionLogo)
        && !str_contains($candidateRegionLogo, '/../')
        && !str_contains($candidateRegionLogo, '/./')) {
        $regionLogoPath = $candidateRegionLogo;
    }
}

$cityFilterIconPath = '';
$organizerFilterIconPath = '';
$citySwitchFilterIconPath = '';
if ($regionId > 0) {
    $cityIconSettingFile = $projectRoot . '/storage/region-city-icons/' . $regionId . '.txt';
    $organizerIconSettingFile = $projectRoot . '/storage/region-organizer-icons/' . $regionId . '.txt';
    $citySwitchIconSettingFile = $projectRoot . '/storage/region-city-switch-icons/' . $regionId . '.txt';
    if (is_file($cityIconSettingFile)) {
        $configuredCityIcon = trim((string) file_get_contents($cityIconSettingFile));
        if (preg_match('#^(?:public/)?uploads/regions/[a-zA-Z0-9._-]+$#', $configuredCityIcon)) {
            $cityFilterIconPath = '/' . preg_replace('#^public/#', '', $configuredCityIcon);
        }
    }
    if (is_file($organizerIconSettingFile)) {
        $configuredOrganizerIcon = trim((string) file_get_contents($organizerIconSettingFile));
        if (preg_match('#^(?:public/)?uploads/regions/[a-zA-Z0-9._-]+$#', $configuredOrganizerIcon)) {
            $organizerFilterIconPath = '/' . preg_replace('#^public/#', '', $configuredOrganizerIcon);
        }
    }
    if (is_file($citySwitchIconSettingFile)) {
        $configuredCitySwitchIcon = trim((string) file_get_contents($citySwitchIconSettingFile));
        if (preg_match('#^(?:public/)?uploads/regions/[a-zA-Z0-9._-]+$#', $configuredCitySwitchIcon)) {
            $citySwitchFilterIconPath = '/' . preg_replace('#^public/#', '', $configuredCitySwitchIcon);
        }
    }

    // Geef de afbeelding een actuele versie zodat een vervangen upload direct zichtbaar is.
    foreach (['cityFilterIconPath', 'organizerFilterIconPath', 'citySwitchFilterIconPath'] as $iconVariable) {
        if ($$iconVariable === '') {
            continue;
        }
        $iconFile = $projectRoot . '/public' . parse_url($$iconVariable, PHP_URL_PATH);
        if (is_file($iconFile)) {
            $$iconVariable .= '?v=' . (string) filemtime($iconFile) . '-' . (string) filesize($iconFile);
        }
    }
}

$nextRegionId = 0;
if (isset($regions) && is_array($regions) && count($regions) > 1 && $regionId > 0) {
    $regionPosition = null;
    foreach ($regions as $position => $regionOption) {
        if ((int) ($regionOption['id'] ?? 0) === $regionId) {
            $regionPosition = $position;
            break;
        }
    }
    if ($regionPosition !== null) {
        $nextRegion = $regions[($regionPosition + 1) % count($regions)];
        $nextRegionId = (int) $nextRegion['id'];
    }
}
$colorValue = static function (string $key, string $fallback) use ($region): string {
    $value = (string) ($region[$key] ?? $fallback);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $fallback;
};
$primaryColor = $colorValue('primary_color', '#0A4D9F');
$themeStyle = '--db-primary:' . $primaryColor
    . ';--db-primary-dark:' . $colorValue('primary_dark', '#083B79')
    . ';--db-accent:' . $colorValue('accent_color', '#00AFC5')
    . ';--db-secondary:' . $colorValue('secondary_color', '#1FA898')
    . ';--db-tertiary:' . $colorValue('tertiary_color', '#F4802A')
    . ';--db-header-color:' . $colorValue('header_color', '#222222')
    . ';--db-menu-color:' . $colorValue('menu_color', '#FFFFFF')
    . ';--db-organizer-color:' . $colorValue('organizer_color', '#E23A2C')
    . ';--db-filter-hover-color:' . $colorValue('filter_hover_color', '#E23A2C')
    . ';--db-filter-bar-color:' . $colorValue('filter_bar_color', '#FFFFFF')
    . ';--db-filter-text-color:' . $colorValue('filter_text_color', '#222222')
    . ';--db-search-button-color:' . $colorValue('search_button_color', '#E23A2C')
    . ';--db-back-top-color:' . $colorValue('back_top_color', '#E23A2C')
    . ';--db-search-field-color:' . $colorValue('search_field_color', '#FFFFFF')
    . ';--db-search-text-color:' . $colorValue('search_text_color', '#222222')
    . ';--db-footer-color:' . $colorValue('footer_color', '#FFFFFF')
    . ';--db-background:' . $colorValue('background_color', '#F7F8FA')
    . ';--db-text:' . $colorValue('text_color', '#202B38');
$weekdayPalette = json_decode((string) ($region['weekday_colors'] ?? ''), true);
$weekdayDefaults = ['#F6D84A', '#4A90D9', '#55A96B', '#A78BDA', '#E78AAE', '#F49A45', '#999999'];
foreach ($weekdayDefaults as $index => $fallback) {
    $candidate = is_array($weekdayPalette) ? (string) ($weekdayPalette[$index] ?? $fallback) : $fallback;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $candidate)) {
        $candidate = $fallback;
    }
    $themeStyle .= ';--db-weekday-' . ($index + 1) . ':' . strtoupper($candidate);
}

$escape = static fn ($value): string =>
    htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="<?= $escape($pageRobots ?? 'noindex, nofollow') ?>">
    <meta name="theme-color" content="<?= $escape($primaryColor) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="DONDEBAILAR">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/app-icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/app-icon.svg">
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/css/agenda.css">
</head>
<body<?= !empty($isAgendaPage) ? ' class="has-agenda-footer"' : '' ?> style="<?= $escape($themeStyle) ?>">

<header class="site-header">
    <div class="site-header__inner">

        <div class="site-brand-group">
            <a class="site-brand site-brand--platform" href="/?region_id=<?= $regionId ?>&amp;agenda_change=1&amp;filter=organizer" aria-label="DONDEBAILAR agenda — filters wissen">
                <?php if ($platformLogoPath !== ''): ?>
                    <img src="<?= $escape($platformLogoPath) ?>" alt="DONDEBAILAR" class="site-brand__logo">
                <?php else: ?>
                    <span class="site-brand__mark" style="--brand-color:<?= $escape($primaryColor) ?>" aria-hidden="true">D</span>
                <?php endif; ?>
                <span class="site-brand__domain">dondebailar</span>
            </a>


        </div>

        <?php if ($headerUser !== null): ?>
            <span class="site-welcome">Welkom<?= $headerDisplayName !== '' ? ' ' . $escape($headerDisplayName) : '' ?></span>
            <?php if (!empty($isAgendaPage)): ?>
                <button type="button" class="site-save-preferences" id="agenda-save-preferences" aria-label="Bewaar mijn agenda-instellingen" title="Bewaar mijn agenda-instellingen">
                    <svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h12l4 4v14H3V3z"></path><path d="M7 3v6h10V3"></path><path d="M7 21v-8h10v8"></path></svg>
                </button>
            <?php endif; ?>
            <a class="site-notifications" href="/notifications.php" aria-label="Meldingen<?= $unreadNotificationCount > 0 ? ': ' . $unreadNotificationCount . ' ongelezen' : '' ?>" title="Meldingen">
                <svg aria-hidden="true" viewBox="0 0 24 24" width="23" height="23" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                <?php if ($unreadNotificationCount > 0): ?><span><?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?></span><?php endif; ?>
            </a>
        <?php endif; ?>

        <details class="site-menu">
            <summary class="site-menu__toggle" aria-label="Menu">
                <span class="site-menu__icon" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </summary>

            <nav class="site-menu__panel" aria-label="Hoofdnavigatie">
                <a href="/">Agenda</a>
                <a href="/info.php<?= $regionId > 0 ? '?region_id=' . $regionId : '' ?>">Info</a>
                <?php if ($headerUser !== null): ?>
                    <a href="/events.php">Mijn evenementen</a>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <details class="site-menu__admin">
                        <summary>Platformbeheer</summary>
                        <a href="/admin/">Dashboard</a>
                        <a href="/events.php">Evenementen</a>
                        <a href="/admin/organizers.php">Organisatoren</a>
                        <a href="/admin/locations.php">Locaties</a>
                        <a href="/admin/users.php">Gebruikers</a>
                        <a href="/admin/settings.php">Instellingen</a>
                        <a href="/admin/regions.php">Regio's</a>
                        <?php if ($regionId > 0): ?><a href="/admin/region-info.php?region_id=<?= $regionId ?>">Info-pagina regio</a><?php endif; ?>
                    </details>
                <?php elseif ($canManageRegion || $canManageLocations): ?>
                    <details class="site-menu__admin">
                        <summary>Regiobeheer</summary>
                        <?php if ($regionId > 0 && userHasRegionRole($regionId, ['region_admin'], $headerUser)): ?>
                            <a href="/admin/region-colors.php?region_id=<?= $regionId ?>">Kleuren en logo</a>
                            <a href="/admin/region-info.php?region_id=<?= $regionId ?>">Info-pagina</a>
                        <?php endif; ?>
                        <?php if ($canManageLocations): ?>
                            <a href="/admin/locations.php">Locaties</a>
                        <?php endif; ?>
                        <?php
                        $organizerAccess = Database::connection()->prepare(
                            "SELECT 1 FROM user_region_roles
                             WHERE user_id = :user_id AND role = 'region_admin'
                             LIMIT 1"
                        );
                        $organizerAccess->execute(['user_id' => (int) $headerUser['id']]);
                        if ($organizerAccess->fetchColumn()):
                        ?>
                            <a href="/admin/organizers.php">Organisatoren</a>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>

                <div class="site-menu__divider"></div>

                <?php if ($headerUser !== null): ?>
                    <form method="post" action="/logout.php">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= $escape(csrfToken()) ?>"
                        >
                        <button type="submit" class="site-menu__logout">
                            Uitloggen
                        </button>
                    </form>
                <?php else: ?>
                    <a href="/login.php">Inloggen</a>
                <?php endif; ?>
            </nav>
        </details>

    </div>

    <?php if (!empty($isAgendaPage) && !empty($regions)): ?>
        <div class="site-header__agenda-filters">
            <form class="agenda-selectors" method="get" action="/">
            <input type="hidden" name="region_id" value="<?= $regionId ?>">
            <input type="hidden" name="agenda_change" value="1">
            <div class="agenda-selector-line">
                <button type="button" class="agenda-selector-button is-active" id="agenda-filter-switch" data-filter-index="0" aria-label="Wissel tussen filters" title="Wissel tussen filters"><span class="agenda-filter-switch-icon agenda-filter-switch-icon--city"><?php if ($citySwitchFilterIconPath !== ''): ?><img class="agenda-filter-icon agenda-filter-icon--image" src="<?= $escape($citySwitchFilterIconPath) ?>" alt="" aria-hidden="true" decoding="async"><?php else: ?><span class="agenda-filter-icon" aria-hidden="true">🏙️</span><?php endif; ?></span><span class="agenda-filter-switch-icon agenda-filter-switch-icon--organizer" hidden><?php if ($organizerFilterIconPath !== ''): ?><img class="agenda-filter-icon agenda-filter-icon--image" src="<?= $escape($organizerFilterIconPath) ?>" alt="" aria-hidden="true" decoding="async"><?php else: ?><span class="agenda-filter-icon" aria-hidden="true">💃</span><?php endif; ?></span><span class="agenda-filter-switch-label">ORGANISATOREN</span></button>
                <div class="agenda-selector-panel is-open" data-panel="organizer">
                    <div class="agenda-organizer-strip" aria-label="Organisatoren met toekomstige evenementen">
                        <?php foreach ($organizers as $organizerOption): ?>
                            <?php
                            $logoFilename = basename((string) ($organizerOption['logo_path'] ?? ''));
                            $hasOrganizerLogo = preg_match('/\\A[a-f0-9]{32}\\.(jpg|png|webp)\\z/', $logoFilename) === 1;
                            $organizerSelected = $organizerId === (int) $organizerOption['id'];
                            $organizerHref = $organizerSelected
                                ? '/?region_id=' . $regionId . '&agenda_change=1&filter=organizer'
                                : '/?region_id=' . $regionId . '&agenda_change=1&filter=organizer&organizer_id=' . (int) $organizerOption['id'];
                            ?>
                            <a class="agenda-organizer-item agenda-organizer-item--organizer<?= $organizerSelected ? ' is-selected' : '' ?>" data-organizer-id="<?= (int) $organizerOption['id'] ?>" href="<?= $escape($organizerHref) ?>" title="<?= $escape($organizerOption['name']) ?>" aria-label="<?= $organizerSelected ? 'Verwijder filter voor ' : 'Toon evenementen van ' ?><?= $escape($organizerOption['name']) ?>">
                                <?php if ($hasOrganizerLogo): ?>
                                    <img class="agenda-organizer-logo" src="/organizer-logo.php?file=<?= rawurlencode($logoFilename) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <span class="agenda-organizer-fallback" aria-hidden="true"><?= $escape(mb_strtoupper(mb_substr((string) $organizerOption['name'], 0, 1))) ?></span>
                                <?php endif; ?>
                                <span class="agenda-organizer-name"><?= $escape($organizerOption['name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="agenda-selector-panel" data-panel="city" hidden>
                    <div class="agenda-organizer-strip" aria-label="Steden met toekomstige evenementen">
                        <?php foreach ($cities as $cityOption): ?>
                            <?php
                            $cityName = (string) $cityOption['city'];
                            $citySelected = $city === $cityName;
                            $cityHref = $citySelected
                                ? '/?region_id=' . $regionId . '&agenda_change=1&filter=city'
                                : '/?region_id=' . $regionId . '&agenda_change=1&filter=city&city=' . rawurlencode($cityName);
                            ?>
                            <a class="agenda-organizer-item<?= $citySelected ? ' is-selected' : '' ?>" data-city="<?= $escape($cityName) ?>" href="<?= $escape($cityHref) ?>" title="<?= $escape($cityName) ?>" aria-label="<?= $citySelected ? 'Verwijder stadsfilter ' : 'Toon evenementen in ' ?><?= $escape($cityName) ?>">
                                <?php if ($cityFilterIconPath !== ''): ?>
                                    <img class="agenda-city-filter-icon" src="<?= $escape($cityFilterIconPath) ?>" alt="" loading="eager" decoding="async">
                                <?php else: ?>
                                    <span class="agenda-organizer-fallback" aria-hidden="true"><?= $escape(mb_strtoupper(mb_substr($cityName, 0, 1))) ?></span>
                                <?php endif; ?>
                                <span class="agenda-organizer-name"><?= $escape($cityName) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </form>

        </div>
    <?php endif; ?>

<style>
.agenda-city-filter-icon{display:block;width:38px;height:38px;flex:none;object-fit:contain;background:transparent}
.site-header__inner{display:flex;align-items:center;gap:6px}
.site-brand-group{display:flex;align-items:center;gap:5px;min-width:0;flex:1 1 auto}
.site-brand{display:flex;align-items:center;gap:4px;text-decoration:none;min-width:0;flex:0 1 auto}
.site-brand--platform,.site-brand--region{min-height:30px}
.site-brand__domain{font-size:14px;font-weight:800;color:var(--db-text);white-space:nowrap;letter-spacing:-.02em}
/* Compacte logo's en tekst voorkomen dat de bovenste regel overlapt. */
.site-brand__logo{display:block;max-width:105px;max-height:30px;width:auto;height:auto;object-fit:contain;flex:0 1 auto}
/* Houd het regiologo zichtbaar tijdens volledige navigatie door agenda-filters. */
.site-brand--region{padding-left:6px;border-left:1px solid var(--db-border)}
.site-brand__region-name{max-width:110px;overflow:hidden;text-overflow:ellipsis;font-size:13px;font-weight:700;color:var(--db-text);white-space:nowrap}
.site-welcome{font-size:12px;white-space:nowrap;flex:0 1 auto}
.site-notifications,.site-save-preferences{width:30px;height:30px}
.site-menu__toggle{min-height:30px;padding:0 7px}
.site-notifications{position:relative;display:grid;place-items:center;width:30px;height:30px;flex:none;color:var(--db-text);text-decoration:none;border-radius:10px}
.site-save-preferences{display:grid;place-items:center;width:30px;height:30px;flex:none;padding:0;border:0;border-radius:10px;background:transparent;color:var(--db-text);cursor:pointer}
.site-save-preferences:hover,.site-save-preferences.is-saved{background:var(--db-surface,#f2f4f7);color:var(--db-primary)}
.site-save-preferences[aria-busy="true"]{opacity:.55;cursor:wait}
.site-notifications:hover{background:var(--db-surface,#f2f4f7)}
.site-notifications span{position:absolute;top:0;right:-2px;display:grid;place-items:center;min-width:17px;height:17px;padding:0 4px;border-radius:99px;background:#dc3545;color:#fff;font-size:10px;font-weight:800;line-height:1}
.site-menu{margin-left:0}
@media(max-width:600px){.site-header__inner{gap:3px;width:calc(100% - 16px);min-height:32px!important}.site-brand-group{gap:3px}.site-brand{gap:3px}.site-brand--region{padding-left:4px}.site-brand--platform,.site-brand--region{min-height:30px}.site-brand__logo{max-width:64px;max-height:28px}.site-brand__domain{font-size:11px}.site-brand__region-name{max-width:68px;font-size:11px}.site-welcome{font-size:10px}.site-notifications,.site-save-preferences{width:26px;height:28px}.site-notifications svg,.site-save-preferences svg{width:19px;height:19px}.site-menu__toggle{min-height:28px;padding:0 6px}}
</style>
</header>

<main class="site-main">
