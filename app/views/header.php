<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Authorization.php';
require_once dirname(__DIR__) . '/Security.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle)
    ? $pageTitle
    : 'DONDEBAILAR';

$region = isset($region) && is_array($region) ? $region : null;
$headerUser = currentUser();

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
    $candidateRegionLogo = trim((string) ($region['logo_path'] ?? ''));
    if ($candidateRegionLogo !== '' && preg_match('#^/uploads/regions/[a-zA-Z0-9._/-]+$#', $candidateRegionLogo)) {
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
$primaryColor = $region['primary_color'] ?? '#0A4D9F';

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
<body>

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



        <details class="site-menu">
            <summary class="site-menu__toggle">
                <span>Menu</span>
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
.site-menu{margin-left:auto}
@media(max-width:600px){.site-header__inner{gap:8px}.site-brand-group{gap:7px}.site-brand--region{padding-left:7px}.site-brand__logo{max-width:112px;max-height:42px}.site-brand__region-name{font-size:12px}}
</style>
</header>

<main class="site-main">
