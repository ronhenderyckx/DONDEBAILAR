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

$logoPath = $region['logo_path'] ?? null;
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

        <a class="site-brand" href="/" aria-label="DONDEBAILAR startpagina">
            <?php if (is_string($logoPath) && $logoPath !== ''): ?>
                <img
                    src="<?= $escape($logoPath) ?>"
                    alt="<?= $escape($brandName) ?>"
                    class="site-brand__logo"
                >
            <?php else: ?>
                <span
                    class="site-brand__mark"
                    style="--brand-color:<?= $escape($primaryColor) ?>"
                    aria-hidden="true"
                >D</span>
            <?php endif; ?>

            <span class="site-brand__name"><?= $escape($brandName) ?></span>
        </a>

        <?php if (isset($regions) && is_array($regions) && count($regions) > 1): ?>
            <form class="site-region-choice" method="get" action="/" aria-label="Kies regio">
                <label for="header-region">Regio</label>
                <select id="header-region" name="region_id" onchange="this.form.submit()">
                    <?php foreach ($regions as $regionOption): ?>
                        <option value="<?= (int) $regionOption['id'] ?>" <?= $regionId === (int) $regionOption['id'] ? 'selected' : '' ?>><?= $escape($regionOption['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

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
                <a href="/organizers.php">Organisatoren</a>
                <a href="/locations.php">Locaties</a>

                <?php if ($headerUser !== null): ?>
                    <a href="/account.php">Mijn account</a>
                    <a href="/events.php">Mijn evenementen</a>
                <?php endif; ?>

                <?php if ($canManageRegion || $canManageLocations || $isAdmin): ?>
                    <a href="/admin/">Beheer</a>
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
.site-header__inner{display:flex;align-items:center;gap:16px}.site-region-choice{display:flex;align-items:center;gap:8px;margin-left:auto}.site-region-choice label{font-size:12px;font-weight:700;color:var(--db-muted)}.site-region-choice select{max-width:190px;height:38px;padding:6px 10px;border:1px solid var(--db-border);border-radius:8px;background:#fff;color:var(--db-text);font:inherit}.site-menu{margin-left:0}
@media(max-width:600px){.site-header__inner{gap:9px}.site-region-choice{gap:4px}.site-region-choice label{display:none}.site-region-choice select{max-width:130px;font-size:13px}}
</style>
</header>

<main class="site-main">
