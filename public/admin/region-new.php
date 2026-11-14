<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

$user = requirePlatformAdmin();
$db = Database::connection();

$pageTitle = 'Nieuwe regio - DONDEBAILAR';
$platformAdminPage = true;
$errorMessage = '';

$form = [
    'name' => '',
    'subdomain' => '',
    'country_code' => 'ES',
    'timezone' => 'Europe/Madrid',
    'default_language' => 'es',
    'is_active' => '1',
];

function createRegionSlug(string $name): string
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $errorMessage = 'Gebruik een landcode van twee hoofdletters, bijvoorbeeld ES of BE.';
    } elseif (!in_array($form['default_language'], ['nl', 'en', 'es', 'fr'], true)) {
        $errorMessage = 'Kies een geldige standaardtaal.';
    } elseif (!in_array($form['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errorMessage = 'Kies een geldige tijdzone.';
    } else {
        $slug = createRegionSlug($form['name']);

        if ($slug === '' || strlen($slug) > 100) {
            $errorMessage = 'De regionaam kan niet worden omgezet naar een geldige slug.';
        } else {
            try {
                $db->beginTransaction();

                $insert = $db->prepare(
                    "INSERT INTO regions
                        (name, slug, subdomain, country_code, timezone,
                         default_language, is_active)
                     VALUES
                        (:name, :slug, :subdomain, :country_code, :timezone,
                         :default_language, :is_active)"
                );

                $insert->execute([
                    'name' => $form['name'],
                    'slug' => $slug,
                    'subdomain' => $form['subdomain'],
                    'country_code' => $form['country_code'],
                    'timezone' => $form['timezone'],
                    'default_language' => $form['default_language'],
                    'is_active' => (int) $form['is_active'],
                ]);

                $regionId = (int) $db->lastInsertId();

                $appearance = $db->prepare(
                    "INSERT INTO region_appearance (region_id, updated_by)
                     VALUES (:region_id, :updated_by)"
                );
                $appearance->execute([
                    'region_id' => $regionId,
                    'updated_by' => (int) $user['id'],
                ]);

                $db->commit();

                header('Location: /admin/regions.php?created=1');
                exit;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                    $errorMessage = 'Deze slug of dit subdomein bestaat al. Kies een andere.';
                } else {
                    error_log('DONDEBAILAR regio aanmaken: ' . $exception->getMessage());
                    $errorMessage = 'De regio kon niet worden aangemaakt. Controleer de serverlog.';
                }
            }
        }
    }
}

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Nieuwe regio</h1>
            <p>Vul de basisgegevens van de regionale agenda in.</p>
        </div>
        <a class="button" href="/admin/regions.php">Terug naar overzicht</a>
    </div>

    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert">
                <?= escapeHtml($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/region-new.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <label for="name">Naam van de regio</label>
            <input type="text" id="name" name="name" maxlength="150"
                   value="<?= escapeHtml($form['name']) ?>"
                   placeholder="Bijvoorbeeld Costa Blanca" required>

            <label for="subdomain">Subdomein</label>
            <div class="input-with-suffix">
                <input type="text" id="subdomain" name="subdomain" maxlength="100"
                       pattern="[a-z0-9-]{2,100}"
                       value="<?= escapeHtml($form['subdomain']) ?>"
                       placeholder="costa-blanca" required>
                <span>.dondebailar.eu</span>
            </div>

            <label for="country_code">Landcode</label>
            <input type="text" id="country_code" name="country_code"
                   minlength="2" maxlength="2" pattern="[A-Z]{2}"
                   value="<?= escapeHtml($form['country_code']) ?>" required>

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

            <label for="default_language">Standaardtaal</label>
            <select id="default_language" name="default_language" required>
                <option value="nl" <?= $form['default_language'] === 'nl' ? 'selected' : '' ?>>Nederlands</option>
                <option value="en" <?= $form['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                <option value="es" <?= $form['default_language'] === 'es' ? 'selected' : '' ?>>Español</option>
                <option value="fr" <?= $form['default_language'] === 'fr' ? 'selected' : '' ?>>Français</option>
            </select>

            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1"
                    <?= $form['is_active'] === '1' ? 'checked' : '' ?>>
                Regio onmiddellijk activeren
            </label>

            <div class="form-actions">
                <button type="submit" class="button button--primary">Regio aanmaken</button>
                <a class="button" href="/admin/regions.php">Annuleren</a>
            </div>
        </form>
    </section>
</section>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
