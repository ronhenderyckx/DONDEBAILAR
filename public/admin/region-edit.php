<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

requirePlatformAdmin();

$db = Database::connection();
$regionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$regionId || $regionId < 1) {
    http_response_code(400);
    exit('Ongeldig regionummer.');
}

$statement = $db->prepare(
    "SELECT r.id, r.name, r.slug, r.subdomain, r.country_code,
            r.timezone, r.default_language, r.is_active,
            COALESCE(ra.logo_path, r.logo_path) AS logo_path,
            ra.primary_color, ra.primary_dark, ra.accent_color,
            ra.secondary_color, ra.tertiary_color, ra.header_color,
            ra.menu_color, ra.organizer_color, ra.filter_hover_color,
            ra.filter_bar_color, ra.filter_text_color, ra.search_button_color,
            ra.back_top_color, ra.search_field_color, ra.search_text_color,
            ra.footer_color, ra.background_color, ra.text_color, ra.weekday_colors
     FROM regions r
     LEFT JOIN region_appearance ra ON ra.region_id = r.id
     WHERE r.id = :id
     LIMIT 1"
);
$statement->execute(['id' => $regionId]);
$region = $statement->fetch();

if (!$region) {
    http_response_code(404);
    exit('Regio niet gevonden.');
}

$pageTitle = 'Regio wijzigen - DONDEBAILAR';
$platformAdminPage = true;
$errorMessage = '';

$form = [
    'name' => (string) $region['name'],
    'subdomain' => (string) $region['subdomain'],
    'country_code' => (string) $region['country_code'],
    'timezone' => (string) $region['timezone'],
    'default_language' => (string) $region['default_language'],
    'is_active' => (string) $region['is_active'],
];

function createRegionEditSlug(string $name): string
{
    $value = trim($name);

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

    return trim($value, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['save_appearance'])) {
    foreach ($form as $key => $default) {
        if ($key === 'is_active') {
            $form[$key] = isset($_POST[$key]) ? '1' : '0';
        } else {
            $form[$key] = trim((string) ($_POST[$key] ?? $default));
        }
    }

    $token = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        $errorMessage = 'De beveiligingscontrole is mislukt. Vernieuw de pagina.';
    } elseif ($form['name'] === '' || strlen($form['name']) > 150) {
        $errorMessage = 'Vul een geldige regionaam in (maximaal 150 tekens).';
    } elseif (!preg_match('/^[a-z0-9-]{2,100}$/', $form['subdomain'])) {
        $errorMessage = 'Het subdomein mag alleen kleine letters, cijfers en koppeltekens bevatten.';
    } elseif (!preg_match('/^[A-Z]{2}$/', $form['country_code'])) {
        $errorMessage = 'Gebruik een landcode van twee hoofdletters.';
    } elseif (!in_array($form['default_language'], ['nl', 'en', 'es', 'fr'], true)) {
        $errorMessage = 'Kies een geldige standaardtaal.';
    } elseif (!in_array($form['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errorMessage = 'Kies een geldige tijdzone.';
    } else {
        $slug = createRegionEditSlug($form['name']);

        if ($slug === '' || strlen($slug) > 100) {
            $errorMessage = 'De regionaam kan niet worden omgezet naar een geldige slug.';
        } else {
            try {
                $update = $db->prepare(
                    "UPDATE regions
                     SET name = :name,
                         slug = :slug,
                         subdomain = :subdomain,
                         country_code = :country_code,
                         timezone = :timezone,
                         default_language = :default_language,
                         is_active = :is_active
                     WHERE id = :id"
                );

                $update->execute([
                    'name' => $form['name'],
                    'slug' => $slug,
                    'subdomain' => $form['subdomain'],
                    'country_code' => $form['country_code'],
                    'timezone' => $form['timezone'],
                    'default_language' => $form['default_language'],
                    'is_active' => (int) $form['is_active'],
                    'id' => $regionId,
                ]);

                header('Location: /admin/regions.php?updated=1');
                exit;
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $errorMessage = 'Deze slug of dit subdomein bestaat al. Kies een andere.';
                } else {
                    error_log('DONDEBAILAR regio wijzigen: ' . $exception->getMessage());
                    $errorMessage = 'De regio kon niet worden gewijzigd. Controleer de serverlog.';
                }
            }
        }
    }
}

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>

<style>
#region-edit-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 22px;
    row-gap: 8px;
    align-items: end;
    padding: 18px;
}
#region-edit-form .region-form-field {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 5px;
}
#region-edit-form .region-form-field label {
    margin: 0;
}
#region-edit-form .region-form-field input,
#region-edit-form .region-form-field select {
    min-height: 40px;
}
#region-edit-form .region-form-wide {
    grid-column: 1 / -1;
}
#region-edit-form .checkbox-label {
    margin: 8px 0 0;
}
#region-edit-form .form-actions {
    margin-top: 12px;
    gap: 8px;
}
@media (max-width: 760px) {
    #region-edit-form {
        grid-template-columns: minmax(0, 1fr);
        row-gap: 7px;
        padding: 16px;
    }
    #region-edit-form .region-form-wide {
        grid-column: auto;
    }
}
    .region-appearance-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}\n    @media(max-width:560px){.region-appearance-actions{width:100%;flex-direction:column;align-items:stretch}.region-appearance-actions .button{width:100%;justify-content:center}}\n</style>

<section class="admin-page region-edit-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Regio wijzigen</h1>
            <p>Wijzig de gegevens van <?= escapeHtml($region['name']) ?>.</p>
        </div>
        <a class="button" href="/admin/regions.php">Terug naar overzicht</a>
    </div>

    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert">
                <?= escapeHtml($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post"
              action="/admin/region-edit.php?id=<?= (int) $regionId ?>"
              class="auth-form" id="region-edit-form">

            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <div class="region-form-field">
                <label for="name">Naam van de regio</label>
                <input type="text" id="name" name="name" maxlength="150"
                       value="<?= escapeHtml($form['name']) ?>" required>
            </div>

            <div class="region-form-field">
                <label for="subdomain">Subdomein</label>
                <div class="input-with-suffix">
                    <input type="text" id="subdomain" name="subdomain" maxlength="100"
                           pattern="[a-z0-9-]{2,100}"
                           value="<?= escapeHtml($form['subdomain']) ?>" required>
                    <span>.dondebailar.eu</span>
                </div>
            </div>

            <div class="region-form-field">
                <label for="country_code">Landcode</label>
                <input type="text" id="country_code" name="country_code"
                       minlength="2" maxlength="2" pattern="[A-Z]{2}"
                       value="<?= escapeHtml($form['country_code']) ?>" required>
            </div>

            <div class="region-form-field">
                <label for="timezone">Tijdzone</label>
                <select id="timezone" name="timezone" required>
                <?php foreach ([
                    'Europe/Madrid', 'Europe/Brussels', 'Europe/Amsterdam',
                    'Europe/Paris', 'Europe/London', 'Europe/Berlin',
                    'Europe/Rome', 'Europe/Lisbon',
                ] as $timezone): ?>
                    <option value="<?= escapeHtml($timezone) ?>"
                        <?= $form['timezone'] === $timezone ? 'selected' : '' ?>>
                        <?= escapeHtml($timezone) ?>
                    </option>
                <?php endforeach; ?>
                </select>
            </div>

            <div class="region-form-field">
                <label for="default_language">Standaardtaal</label>
                <select id="default_language" name="default_language" required>
                <option value="nl" <?= $form['default_language'] === 'nl' ? 'selected' : '' ?>>Nederlands</option>
                <option value="en" <?= $form['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                <option value="es" <?= $form['default_language'] === 'es' ? 'selected' : '' ?>>Español</option>
                <option value="fr" <?= $form['default_language'] === 'fr' ? 'selected' : '' ?>>Français</option>
                </select>
            </div>

            <label class="checkbox-label region-form-wide">
                <input type="checkbox" name="is_active" value="1"
                    <?= $form['is_active'] === '1' ? 'checked' : '' ?>>
                Regio actief
            </label>

            <div class="form-actions region-form-wide">
                <button type="submit" class="button button--primary">Wijzigingen opslaan</button>
                <a class="button" href="/admin/regions.php">Annuleren</a>
            </div>
        </form>
    </section>

    <section class="admin-section region-appearance-link">
        <div class="section-heading">
            <div>
                <h2>Regiokleuren en logo</h2>
                <p>Pas de algemene regiokleuren, filterknoppen, footer en het regiologo aan. De weekdag- en evenementkleuren stel je apart in bij Agendakleuren.</p>
            </div>
            <div class="region-appearance-actions">
                <a class="button button--primary" href="/admin/region-agenda-colors.php?region_id=<?= (int) $regionId ?>">Kleuren aanpassen</a>
                <a class="button" href="/admin/region-logo.php?region_id=<?= (int) $regionId ?>#region-logo">Logo aanpassen</a>
            </div>
        </div>
    </section>
</section>

<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
