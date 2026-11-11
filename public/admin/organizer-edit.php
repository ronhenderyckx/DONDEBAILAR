<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Organizers.php';
require_once dirname(__DIR__, 2) . '/app/ImageOptimization.php';

$user = requireOrganizerManagement();
$db = Database::connection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Ongeldig organisatienummer.');
}

$statement = $db->prepare("SELECT * FROM organizers WHERE id = :id LIMIT 1");
$statement->execute(['id' => $id]);
$organizer = $statement->fetch();
if (!$organizer) {
    http_response_code(404);
    exit('Organisator niet gevonden.');
}
$pageTitle = 'Organisator wijzigen - DONDEBAILAR';
$errors = [];
$name = (string) $organizer['name'];
$phone = (string) ($organizer['phone'] ?? '');
$website = (string) ($organizer['website'] ?? '');
$notes = (string) ($organizer['notes'] ?? '');
$isActive = (int) $organizer['is_active'] === 1;

function isManagedOrganizerLogo(?string $path): bool
{
    return is_string($path)
        && preg_match('~\Auploads/organizers/[a-f0-9]{32}\.(?:jpg|png|webp)\z~D', $path) === 1;
}

function deleteManagedOrganizerLogo(?string $path): void
{
    if (!isManagedOrganizerLogo($path)) {
        return;
    }

    $file = dirname(__DIR__, 2) . '/' . $path;
    if (is_file($file)) {
        @unlink($file);
    }
}

function storeOrganizerLogo(array $file): ?string
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
    if ($website !== '' && (
        mb_strlen($website) > 500
        || !filter_var($website, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string) parse_url($website, PHP_URL_SCHEME)), ['http', 'https'], true)
    )) {
        $errors[] = 'Vul een geldige website in die begint met https:// of http://.';
    }

    if ($errors === []) {
        $oldLogoPath = $organizer['logo_path'] !== null
            ? (string) $organizer['logo_path']
            : null;
        $newLogoPath = null;

        try {
            $logoPath = $oldLogoPath;

            if (isset($_POST['remove_logo'])) {
                $logoPath = null;
            } else {
                $uploadedLogoPath = storeOrganizerLogo($_FILES['logo'] ?? []);

                if ($uploadedLogoPath !== null) {
                    $newLogoPath = $uploadedLogoPath;
                    $logoPath = $uploadedLogoPath;
                }
            }

            $update = $db->prepare(
                "UPDATE organizers
                 SET name = :name, phone = :phone, website = :website,
                     logo_path = :logo_path, notes = :notes, is_active = :is_active
                 WHERE id = :id"
            );

            $update->execute([
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'website' => $website !== '' ? $website : null,
                'logo_path' => $logoPath,
                'notes' => $notes !== '' ? $notes : null,
                'is_active' => $isActive ? 1 : 0,
                'id' => $id,
            ]);

            // Pas na een geslaagde database-update het oude logo verwijderen.
            if ($logoPath !== $oldLogoPath) {
                deleteManagedOrganizerLogo($oldLogoPath);
            }

            header('Location: /admin/organizers.php?updated=1');
            exit;
        } catch (Throwable $exception) {
            // Bij een mislukte update blijft het oude logo behouden.
            deleteManagedOrganizerLogo($newLogoPath);

            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'De organisator kon niet worden bijgewerkt.';
        }
    }
}

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">BEHEER</p>
            <h1>Organisator wijzigen</h1>
            <p>Wijzig de platformbrede organisator.</p>
        </div>
        <a class="button" href="/admin/organizers.php">Terug naar organisatoren</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="message message--error"><?= escapeHtml($error) ?></div>
    <?php endforeach; ?>

    <section class="admin-section organizer-form-section">
        <form method="post" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <label for="name">Naam van de organisator *</label>
            <input id="name" name="name" type="text" maxlength="190"
                   value="<?= escapeHtml($name) ?>" required>

            <label for="phone">Telefoon</label>
            <input id="phone" name="phone" type="tel" maxlength="50"
                   value="<?= escapeHtml($phone) ?>">

            <label for="website">Website</label>
            <input id="website" name="website" type="url" maxlength="500"
                   value="<?= escapeHtml($website) ?>">

            <?php if (!empty($organizer['logo_path'])): ?>
                <div class="current-organizer-logo">
                    <img src="/organizer-logo.php?file=<?= rawurlencode(basename((string) $organizer['logo_path'])) ?>"
                         alt="Huidig logo van <?= escapeHtml($organizer['name']) ?>">
                </div>
                <label class="checkbox-label">
                    <input type="checkbox" name="remove_logo" value="1">
                    Huidig logo verwijderen
                </label>
            <?php endif; ?>

            <label for="logo">Nieuw logo (JPG, PNG of WebP — maximaal 2 MB)</label>
            <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp">

            <label for="notes">Informatie / opmerkingen</label>
            <textarea id="notes" name="notes" rows="6"><?= escapeHtml($notes) ?></textarea>

            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                Actieve organisator
            </label>

            <div class="form-actions">
                <button class="button button--primary" type="submit">Wijzigingen opslaan</button>
                <a class="button" href="/admin/organizers.php">Annuleren</a>
            </div>
        </form>
    </section>
</section>

<style>
.organizer-form-section { max-width:760px; }
.organizer-form-section .auth-form { max-width:620px; }
.organizer-form-section input[type="file"] { padding:8px; }
.current-organizer-logo { padding:12px; border:1px solid #E7EAF0; border-radius:8px; background:#fff; }
.current-organizer-logo img { max-width:180px; max-height:110px; object-fit:contain; }
</style>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
