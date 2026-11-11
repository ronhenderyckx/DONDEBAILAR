<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Organizers.php';
require_once dirname(__DIR__, 2) . '/app/ImageOptimization.php';

$user = requireOrganizerManagement();
$db = Database::connection();

$pageTitle = 'Organisator toevoegen - DONDEBAILAR';
$errors = [];
$name = '';
$phone = '';
$website = '';
$notes = '';
$isActive = true;

function saveOrganizerLogo(array $file): ?string
{
    return storeOptimizedLogoUpload($file, 'uploads/organizers', 256, 2 * 1024 * 1024);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'De beveiligingscontrole is verlopen. Vernieuw de pagina en probeer opnieuw.';
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $website = trim((string) ($_POST['website'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $isActive = isset($_POST['is_active']);

    if ($name === '' || mb_strlen($name) > 190) {
        $errors[] = 'Vul een naam in van maximaal 190 tekens.';
    }
    if (mb_strlen($phone) > 50) {
        $errors[] = 'Het telefoonnummer mag maximaal 50 tekens bevatten.';
    }
    if ($website !== '' && (mb_strlen($website) > 500 || !filter_var($website, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($website, PHP_URL_SCHEME)), ['http', 'https'], true))) {
        $errors[] = 'Vul een geldige website in die begint met https:// of http://.';
    }

    if ($errors === []) {
        $logoPath = null;

        try {
            $logoPath = saveOrganizerLogo($_FILES['logo'] ?? []);
            $statement = $db->prepare(
                "INSERT INTO organizers (name, phone, website, logo_path, notes, is_active)
                 VALUES (:name, :phone, :website, :logo_path, :notes, :is_active)"
            );
            $statement->execute([
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'website' => $website !== '' ? $website : null,
                'logo_path' => $logoPath,
                'notes' => $notes !== '' ? $notes : null,
                'is_active' => $isActive ? 1 : 0,
            ]);
            header('Location: /admin/organizers.php?created=1');
            exit;
        } catch (Throwable $exception) {
            // Ruim het logo op als de database-insert mislukt.
            if (
                is_string($logoPath)
                && preg_match('~\\Auploads/organizers/[a-f0-9]{32}\\.webp\\z~D', $logoPath) === 1
            ) {
                $logoFile = dirname(__DIR__, 2) . '/' . $logoPath;
                if (is_file($logoFile)) {
                    @unlink($logoFile);
                }
            }

            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'De organisator kon niet worden opgeslagen.';
        }
    }
}

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">BEHEER</p>
            <h1>Organisator toevoegen</h1>
            <p>Maak een platformbrede organisator aan.</p>
        </div>
        <a class="button" href="/admin/organizers.php">Terug naar organisatoren</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="message message--error"><?= escapeHtml($error) ?></div>
    <?php endforeach; ?>

    <section class="admin-section organizer-form-section">
        <form method="post" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <label for="name">Naam organisator *</label>
            <input id="name" name="name" type="text" maxlength="190"
                   value="<?= escapeHtml($name) ?>" required>

            <label for="phone">Telefoon</label>
            <input id="phone" name="phone" type="tel" maxlength="50"
                   value="<?= escapeHtml($phone) ?>">

            <label for="website">Website</label>
            <input id="website" name="website" type="url" maxlength="500"
                   placeholder="https://www.voorbeeld.eu"
                   value="<?= escapeHtml($website) ?>">

            <label for="logo">Logo (JPG, PNG of WebP — maximaal 2 MB; wordt automatisch geoptimaliseerd)</label>
            <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp">

            <label for="notes">Informatie / opmerkingen</label>
            <textarea id="notes" name="notes" rows="6"><?= escapeHtml($notes) ?></textarea>

            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                Actieve organisator
            </label>

            <div class="form-actions">
                <button class="button button--primary" type="submit">Organisator opslaan</button>
                <a class="button" href="/admin/organizers.php">Annuleren</a>
            </div>
        </form>
    </section>
</section>

<style>
.organizer-form-section { max-width:760px; }
.organizer-form-section .auth-form { max-width:620px; }
.organizer-form-section input[type="file"] { padding:8px; }
</style>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
