<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Authorization.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'DONDEBAILAR';
$region = isset($region) && is_array($region) ? $region : null;
$headerUser = currentUser();
$regionId = (int) ($region['id'] ?? 0);
$canManageRegion = $regionId > 0 && userHasRegionRole($regionId, ['region_admin', 'editor'], $headerUser);
$isAdmin = isPlatformAdmin($headerUser);
$siteName = 'DONDEBAILAR';
$brandName = $region && !empty($region['name']) ? (string) $region['name'] : $siteName;
$logoPath = $region && !empty($region['logo_path']) ? (string) $region['logo_path'] : null;
$primaryColor = $region['primary_color'] ?? '#E23A2C';
$headerColor = $region['header_color'] ?? '#222222';
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<header class="site-header" style="--region-primary:<?= $escape($primaryColor) ?>;--region-header:<?= $escape($headerColor) ?>">
    <a class="site-brand" href="/" aria-label="<?= $escape($siteName) ?> startpagina">
        <?php if ($logoPath !== null): ?>
            <img src="<?= $escape($logoPath) ?>" alt="<?= $escape($brandName) ?>" class="site-brand__logo">
        <?php else: ?>
            <span class="site-brand__mark" aria-hidden="true">DB</span>
        <?php endif; ?>
        <span class="site-brand__name"><?= $escape($brandName) ?></span>
    </a>

    <button class="site-menu-toggle" type="button" aria-expanded="false" aria-controls="site-navigation">
        <span class="sr-only">Menu openen</span>
        <span aria-hidden="true">☰</span>
    </button>

    <nav class="site-navigation" id="site-navigation" aria-label="Hoofdnavigatie">
        <a href="/">Agenda</a>
        <a href="/organizers.php">Organisatoren</a>
        <a href="/locations.php">Locaties</a>
        <?php if ($headerUser !== null): ?>
            <a href="/account.php">Mijn account</a>
        <?php endif; ?>
        <?php if ($canManageRegion || $isAdmin): ?>
            <a href="/admin/">Beheer</a>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <a href="/admin/regions.php">Regio's beheren</a>
        <?php endif; ?>
        <?php if ($headerUser !== null): ?>
            <a href="/logout.php">Uitloggen</a>
        <?php else: ?>
            <a href="/login.php">Inloggen</a>
        <?php endif; ?>
    </nav>
</header>
<main class="site-main">
