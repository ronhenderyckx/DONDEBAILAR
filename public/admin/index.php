<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

$user = requirePlatformAdmin();
$pageTitle = 'Platformbeheer - DONDEBAILAR';

$db = Database::connection();

$userCount = (int) $db->query(
    "SELECT COUNT(*) FROM users"
)->fetchColumn();

$regionCount = (int) $db->query(
    "SELECT COUNT(*) FROM regions"
)->fetchColumn();

$eventCount = (int) $db->query(
    "SELECT COUNT(*) FROM events"
)->fetchColumn();

$regions = $db->query(
    "SELECT id, name, slug, subdomain, country_code,
            timezone, is_active
     FROM regions
     ORDER BY name"
)->fetchAll();

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">

    <div class="admin-heading">
        <div>
            <p class="eyebrow">DONDEBAILAR</p>
            <h1>Platformbeheer</h1>
            <p>Welkom, <?= escapeHtml($user['display_name']) ?>.</p>
        </div>
    </div>

    <div class="dashboard-cards">
        <article class="dashboard-card">
            <span>Gebruikers</span>
            <strong><?= $userCount ?></strong>
        </article>

        <article class="dashboard-card">
            <span>Regio's</span>
            <strong><?= $regionCount ?></strong>
        </article>

        <article class="dashboard-card">
            <span>Evenementen</span>
            <strong><?= $eventCount ?></strong>
        </article>
    </div>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Systeemupdate</h2>
                <p>Haal de nieuwste versie van DONDEBAILAR op vanaf GitHub.</p>
            </div>
            <form method="post" action="/admin/update.php"
                  onsubmit="return confirm('Wil je DONDEBAILAR nu bijwerken?');">
                <input type="hidden" name="csrf_token"
                       value="<?= escapeHtml(csrfToken()) ?>">
                <button class="button button--primary" type="submit">
                    UPDATE
                </button>
            </form>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Evenementen</h2>
                <p>Bekijk en beheer evenementen binnen de regio's die je beheert.</p>
            </div>
            <a class="button button--primary" href="/events.php">Evenementen beheren</a>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Organisatoren</h2>
                <p>Beheer centrale organisatieprofielen die in meerdere regio's evenementen kunnen organiseren.</p>
            </div>
            <a class="button button--primary" href="/admin/organizers.php">Organisatoren beheren</a>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Locaties</h2>
                <p>Beheer dansscholen, zalen en andere evenementlocaties.</p>
            </div>
            <a class="button button--primary" href="/admin/locations.php">Locaties beheren</a>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>E-mailinstellingen</h2>
                <p>Beheer de centrale SMTP-server en afzender voor systeemmails.</p>
            </div>
            <a class="button button--primary" href="/admin/mail-settings.php">E-mail instellen</a>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Gebruikers</h2>
                <p>Beheer platformgebruikers en hun regionale toegangsrechten.</p>
            </div>

            <a class="button button--primary"
               href="/admin/users.php">
                Gebruikers beheren
            </a>
        </div>
    </section>

    <section class="admin-section">
        <div class="section-heading">
            <div>
                <h2>Regio's</h2>
                <p>Beheer de regio's die op DONDEBAILAR beschikbaar zijn.</p>
            </div>

            <a class="button button--primary"
               href="/admin/regions.php">
                Regio's beheren
            </a>
        </div>

        <?php if ($regions === []): ?>
            <p>Er zijn nog geen regio's aangemaakt.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Regio</th>
                            <th>Subdomein</th>
                            <th>Land</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($regions as $region): ?>
                        <tr>
                            <td>
                                <strong><?= escapeHtml($region['name']) ?></strong>
                            </td>
                            <td><?= escapeHtml($region['subdomain']) ?></td>
                            <td><?= escapeHtml($region['country_code']) ?></td>
                            <td>
                                <?php if ((int) $region['is_active'] === 1): ?>
                                    <span class="status-active">Actief</span>
                                <?php else: ?>
                                    <span class="status-inactive">Inactief</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

</section>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
