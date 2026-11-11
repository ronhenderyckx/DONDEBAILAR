<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Authorization.php';
require_once dirname(__DIR__) . '/Security.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle)
    ? $pageTitle
    : 'DONDEBAILAR';

$region = isset($region) && is_array($region) ? $region : null;
$headerUser = currentUser();

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
    . ';--db-filter-bar-color:' . $colorValue('filter_bar_color', '#FFFFFF')
    . ';--db-filter-text-color:' . $colorValue('filter_text_color', '#222222')
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="<?= $escape($pageRobots ?? 'noindex, nofollow') ?>">
    <meta name="theme-color" content="#FFFFFF">
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/site.css">
</head>
<body<?= !empty($isAgendaPage) ? ' class="has-agenda-footer"' : '' ?> style="<?= $escape($themeStyle) ?>">

<header class="site-header">
    <div class="site-header__inner">

        <div class="site-brand-group">
            <a class="site-brand site-brand--platform" href="/" aria-label="DONDEBAILAR agenda">
                <?php if ($platformLogoPath !== ''): ?>
                    <img src="<?= $escape($platformLogoPath) ?>" alt="DONDEBAILAR" class="site-brand__logo">
                <?php else: ?>
                    <span class="site-brand__mark" style="--brand-color:<?= $escape($primaryColor) ?>" aria-hidden="true">D</span>
                    <span class="site-brand__name">DONDEBAILAR</span>
                <?php endif; ?>
            </a>

            <?php if ($region !== null): ?>
                <?php if ($nextRegionId > 0): ?>
                    <a class="site-brand site-brand--region" href="/?region_id=<?= $nextRegionId ?>" aria-label="Volgende regio: <?= $escape($brandName) ?>" title="Klik voor de volgende regio">
                <?php else: ?>
                    <span class="site-brand site-brand--region">
                <?php endif; ?>
                    <?php if ($regionLogoPath !== ''): ?>
                        <img src="<?= $escape($regionLogoPath) ?>" alt="<?= $escape($brandName) ?>" class="site-brand__logo">
                    <?php else: ?>
                        <span class="site-brand__region-name"><?= $escape($brandName) ?></span>
                    <?php endif; ?>
                <?php if ($nextRegionId > 0): ?>
                    </a>
                <?php else: ?>
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($headerUser !== null): ?>
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
                    </details>
                <?php elseif ($canManageRegion || $canManageLocations): ?>
                    <details class="site-menu__admin">
                        <summary>Regiobeheer</summary>
                        <?php if ($regionId > 0 && userHasRegionRole($regionId, ['region_admin'], $headerUser)): ?>
                            <a href="/admin/region-colors.php?region_id=<?= $regionId ?>">Kleuren en logo</a>
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

<style>
.site-header__inner{display:flex;align-items:center;gap:16px}
.site-brand-group{display:flex;align-items:center;gap:12px;min-width:0}
.site-brand{display:flex;align-items:center;gap:8px;text-decoration:none;min-width:0}
.site-brand--platform,.site-brand--region{min-height:48px}
.site-brand__logo{display:block;max-width:170px;max-height:52px;width:auto;height:auto;object-fit:contain}
.site-brand--region{padding-left:12px;border-left:1px solid var(--db-border)}
.site-brand__region-name{font-size:15px;font-weight:700;color:var(--db-text);white-space:nowrap}
.site-notifications{position:relative;display:grid;place-items:center;width:40px;height:40px;flex:none;color:var(--db-text);text-decoration:none;border-radius:10px}
.site-notifications:hover{background:var(--db-surface,#f2f4f7)}
.site-notifications span{position:absolute;top:0;right:-2px;display:grid;place-items:center;min-width:17px;height:17px;padding:0 4px;border-radius:99px;background:#dc3545;color:#fff;font-size:10px;font-weight:800;line-height:1}
.site-menu{margin-left:0}
@media(max-width:600px){.site-header__inner{gap:8px}.site-brand-group{gap:7px}.site-brand--region{padding-left:7px}.site-brand__logo{max-width:112px;max-height:42px}.site-brand__region-name{font-size:12px}}
</style>
</header>

<main class="site-main">
