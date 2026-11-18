<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

requirePlatformAdmin();

$pageTitle = 'Updates uitvoeren - DONDEBAILAR';
$platformAdminPage = true;

// Geef het menu ook op deze platformpagina een actieve regio-context.
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

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Updates uitvoeren</h1>
            <p>Haal de nieuwste versie van DONDEBAILAR op vanaf GitHub en werk de database bij.</p>
        </div>
    </div>

    <section class="admin-section">
        <h2>DONDEBAILAR bijwerken</h2>
        <p>De updater controleert eerst of de installatie op branch <strong>develop</strong> staat en of er geen lokale wijzigingen zijn. Daarna wordt de nieuwste versie opgehaald en worden de bijbehorende database-updates uitgevoerd.</p>
        <p>Start de update alleen wanneer je klaar bent om de nieuwe versie te installeren.</p>

        <form method="post" action="/admin/update.php"
              onsubmit="return confirm('Wil je DONDEBAILAR nu bijwerken?');">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <button class="button button--primary" type="submit">UPDATE STARTEN</button>
        </form>
    </section>
</section>

<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
