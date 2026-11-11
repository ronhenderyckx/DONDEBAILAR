<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Organizers.php';

$user = requireOrganizerManagement();
$db = Database::connection();

$pageTitle = 'Organisatoren beheren - DONDEBAILAR';
$errors = [];

/*
 * Organisatoren worden nooit fysiek verwijderd.
 * Evenementen verwijzen via events.organizer_id naar deze tabel.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'De beveiligingscontrole is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } else {
        $organizerId = filter_var($_POST['organizer_id'] ?? null, FILTER_VALIDATE_INT);
        $action = (string) ($_POST['action'] ?? '');

        if (!$organizerId || $organizerId < 1 || !in_array($action, ['activate', 'deactivate'], true)) {
            $errors[] = 'Ongeldige actie voor de organisator.';
        } else {
            try {
                $active = $action === 'activate' ? 1 : 0;
                $update = $db->prepare(
                    'UPDATE organizers
                     SET is_active = :is_active, updated_by = :updated_by
                     WHERE id = :id'
                );
                $update->execute([
                    'is_active' => $active,
                    'updated_by' => (int) $user['id'],
                    'id' => $organizerId,
                ]);

                header('Location: /admin/organizers.php?' . ($active ? 'activated=1' : 'deactivated=1'));
                exit;
            } catch (Throwable $exception) {
                $errors[] = 'De status van de organisator kon niet worden aangepast.';
            }
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT
            o.id,
            o.name,
            o.phone,
            o.website,
            o.logo_path,
            o.is_active,
            o.created_at,
            o.updated_at,
            COALESCE(NULLIF(creator.display_name, ''), creator.email) AS created_by_name,
            COALESCE(NULLIF(editor.display_name, ''), editor.email) AS updated_by_name
        FROM organizers o
        LEFT JOIN users creator ON creator.id = o.created_by
        LEFT JOIN users editor ON editor.id = o.updated_by";
$params = [];
$where = [];

if ($search !== '') {
    $where[] = "(o.name LIKE :search_name
              OR o.phone LIKE :search_phone
              OR o.website LIKE :search_website
              OR o.notes LIKE :search_notes)";
    $searchValue = '%' . $search . '%';
    $params = [
        'search_name' => $searchValue,
        'search_phone' => $searchValue,
        'search_website' => $searchValue,
        'search_notes' => $searchValue,
    ];
}

$sql .= ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.name';

$statement = $db->prepare($sql);
$statement->execute($params);
$organizers = $statement->fetchAll();

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">BEHEER</p>
            <h1>Organisatoren</h1>
            <p>Platformbeheerders kunnen alle organisatoren beheren.</p>
        </div>
        <a class="button" href="/admin/">Terug naar dashboard</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="message message--error"><?= escapeHtml($error) ?></div>
    <?php endforeach; ?>

    <?php if (isset($_GET['created'])): ?>
        <div class="message message--success">De organisator is aangemaakt.</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="message message--success">De organisator is bijgewerkt.</div>
    <?php elseif (isset($_GET['activated'])): ?>
        <div class="message message--success">De organisator is opnieuw geactiveerd.</div>
    <?php elseif (isset($_GET['deactivated'])): ?>
        <div class="message message--success">De organisator is inactief gemaakt. Bestaande evenementen blijven behouden.</div>
    <?php endif; ?>

    <section class="admin-section">
        <div class="section-heading">
            <h2>Organisatorenoverzicht</h2>
            <a class="button button--primary" href="/admin/organizer-new.php">
                + Organisator toevoegen
            </a>
        </div>

        <form method="get" class="organizer-search">
            <label for="organizer-search">Zoeken</label>
            <div class="organizer-search__controls">
                <input id="organizer-search" type="search" name="q"
                       value="<?= escapeHtml($search) ?>"
                       placeholder="Naam, telefoon of website">
                <button class="button" type="submit">Zoeken</button>
                <?php if ($search !== ''): ?>
                    <a class="button" href="/admin/organizers.php">Zoekopdracht wissen</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($organizers === []): ?>
            <p><?= $search !== '' ? 'Geen organisatoren gevonden.' : 'Er zijn nog geen organisatoren aangemaakt.' ?></p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>Logo</th>
                        <th>Naam</th>
                        <th>Telefoon</th>
                        <th>Website</th>
                        <th>Status</th>
                        <th>Aangemaakt / gewijzigd</th>
                        <th>Actie</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($organizers as $organizer): ?>
                        <tr>
                            <td>
                                <?php if (!empty($organizer['logo_path'])): ?>
                                    <img class="organizer-logo"
                                         src="/organizer-logo.php?file=<?= rawurlencode(basename((string) $organizer['logo_path'])) ?>"
                                         alt="Logo <?= escapeHtml($organizer['name']) ?>">
                                <?php else: ?>
                                    <span class="organizer-logo-placeholder" aria-label="Geen logo">—</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= escapeHtml($organizer['name']) ?></strong></td>
                            <td><?= escapeHtml($organizer['phone'] ?: '—') ?></td>
                            <td>
                                <?php if (!empty($organizer['website'])): ?>
                                    <a href="<?= escapeHtml($organizer['website']) ?>"
                                       target="_blank" rel="noopener noreferrer">Website</a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $organizer['is_active'] === 1): ?>
                                    <span class="status-active">Actief</span>
                                <?php else: ?>
                                    <span class="status-inactive">Inactief</span>
                                <?php endif; ?>
                            </td>
                            <td class="organizer-audit">
                                <span>Aangemaakt: <?= escapeHtml($organizer['created_by_name'] ?: 'Onbekend') ?></span>
                                <small><?= !empty($organizer['created_at']) ? escapeHtml(date('d-m-Y H:i', strtotime((string) $organizer['created_at']))) : '—' ?></small>
                                <span>Laatst gewijzigd: <?= escapeHtml($organizer['updated_by_name'] ?: 'Onbekend') ?></span>
                                <small><?= !empty($organizer['updated_at']) ? escapeHtml(date('d-m-Y H:i', strtotime((string) $organizer['updated_at']))) : '—' ?></small>
                            </td>
                            <td class="organizer-actions">
                                <a class="button button--small"
                                   href="/admin/organizer-edit.php?id=<?= (int) $organizer['id'] ?>">
                                    Wijzigen
                                </a>
                                <form method="post" class="organizer-status-form"
                                      onsubmit="return confirm('<?= (int) $organizer['is_active'] === 1 ? 'Deze organisator inactief maken? Bestaande evenementen blijven behouden.' : 'Deze organisator opnieuw activeren?' ?>');">
                                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                    <input type="hidden" name="organizer_id" value="<?= (int) $organizer['id'] ?>">
                                    <?php if ((int) $organizer['is_active'] === 1): ?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <button class="button button--small" type="submit">Inactief maken</button>
                                    <?php else: ?>
                                        <input type="hidden" name="action" value="activate">
                                        <button class="button button--small button--primary" type="submit">Activeren</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<style>
.organizer-search { margin: 0 0 22px; }
.organizer-search label { display:block; margin-bottom:7px; font-size:13px; font-weight:650; }
.organizer-search__controls { display:flex; flex-wrap:wrap; gap:9px; }
.organizer-search input { min-width:220px; min-height:42px; flex:1; padding:9px 12px; border:1px solid #DCE1E8; border-radius:8px; }
.organizer-logo { width:58px; height:48px; object-fit:contain; border:1px solid #E7EAF0; border-radius:7px; background:#fff; }
.organizer-logo-placeholder { width:58px; height:48px; display:grid; place-items:center; border:1px dashed #DCE1E8; border-radius:7px; color:#9AA3AF; }
.organizer-audit { min-width:190px; font-size:12px; line-height:1.5; }
.organizer-audit span, .organizer-audit small { display:block; }
.organizer-audit small { color:#737B87; margin-bottom:5px; }
.organizer-actions { min-width:145px; }
.organizer-status-form { margin-top:6px; }
</style>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
