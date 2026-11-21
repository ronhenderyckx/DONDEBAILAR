<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/ImageOptimization.php';

$db = Database::connection();
$regionId = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT);
if (!$regionId || $regionId < 1) {
    http_response_code(400);
    exit('Ongeldig regionummer.');
}

requireRegionRole($regionId, ['region_admin']);

$regionQuery = $db->prepare("SELECT id, name, logo_path FROM regions WHERE id = :id LIMIT 1");
$regionQuery->execute(['id' => $regionId]);
$region = $regionQuery->fetch();
if (!$region) {
    http_response_code(404);
    exit('Regio niet gevonden.');
}

$appearanceQuery = $db->prepare("SELECT logo_path FROM region_appearance WHERE region_id = :id LIMIT 1");
$appearanceQuery->execute(['id' => $regionId]);
$appearance = $appearanceQuery->fetch() ?: [];
$logoPath = (string) ($appearance['logo_path'] ?? '');
if ($logoPath === '') {
    $logoPath = (string) ($region['logo_path'] ?? '');
}

$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } else {
        $oldLogoPath = $logoPath;
        $newLogoPath = $logoPath;

        if (!empty($_POST['delete_logo'])) {
            $newLogoPath = '';
        }

        if (isset($_FILES['region_logo']) && ($_FILES['region_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedRegionLogo = storeOptimizedLogoUpload(
                    $_FILES['region_logo'],
                    'uploads/regions',
                    800,
                    5 * 1024 * 1024,
                    'region-' . $regionId . '-',
                    dirname(__DIR__)
                );
                if ($uploadedRegionLogo !== null) {
                    $newLogoPath = $uploadedRegionLogo;
                }
            } catch (RuntimeException $exception) {
                $errorMessage = $exception->getMessage();
            }
        }

        if ($errorMessage === '') {
            $saveLogo = $db->prepare("INSERT INTO region_appearance (region_id, logo_path)
                VALUES (:region_id, :logo_path)
                ON DUPLICATE KEY UPDATE logo_path = VALUES(logo_path)");
            $saveLogo->execute([
                'region_id' => $regionId,
                'logo_path' => $newLogoPath,
            ]);

            if ($oldLogoPath !== '' && $oldLogoPath !== $newLogoPath
                && str_starts_with($oldLogoPath, 'uploads/regions/')) {
                $oldFile = dirname(__DIR__) . '/' . $oldLogoPath;
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            $logoPath = $newLogoPath;
            $successMessage = $logoPath === ''
                ? 'Het regiologo is verwijderd.'
                : 'Het regiologo is opgeslagen.';
        }
    }
}

$pageTitle = 'Regiologo - ' . $region['name'];
$platformAdminPage = true;
require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">REGIOBEHEER</p>
            <h1>Regiologo</h1>
            <p>Beheer het logo van <?= escapeHtml($region['name']) ?>.</p>
        </div>
        <a class="button" href="/admin/region-edit.php?id=<?= (int) $regionId ?>">Terug naar regio</a>
    </div>

    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <h2>Huidig regiologo</h2>
            <?php if ($logoPath !== ''): ?>
                <p><img src="/<?= escapeHtml(ltrim($logoPath, '/')) ?>" alt="Huidig regiologo" class="region-logo-current"></p>
                <label class="checkbox-label">
                    <input type="checkbox" name="delete_logo" value="1">
                    Huidig logo verwijderen
                </label>
            <?php else: ?>
                <p>Er is nog geen regiologo ingesteld.</p>
            <?php endif; ?>

            <h2>Nieuw regiologo</h2>
            <p>Upload een PNG-, JPG- of WebP-afbeelding van maximaal 5 MB. Het logo wordt automatisch verkleind.</p>
            <label for="region_logo">Logo kiezen</label>
            <input type="file" id="region_logo" name="region_logo" accept="image/png,image/jpeg,image/webp">

            <div class="form-actions">
                <button type="submit" class="button button--primary">Logo opslaan</button>
                <a class="button" href="/admin/region-edit.php?id=<?= (int) $regionId ?>">Terug naar regio</a>
            </div>
        </form>
    </section>
</section>

<style>
.region-logo-current{display:block;max-width:220px;max-height:120px;object-fit:contain;margin:12px 0 18px}
.admin-section .auth-form>h2{margin:24px 0 8px;padding-bottom:10px;border-bottom:1px solid #e5e9ef}
.admin-section .auth-form>h2:first-of-type{margin-top:8px}
@media(max-width:560px){
    .admin-heading{align-items:flex-start;gap:12px;flex-direction:column}
    .admin-heading>.button{width:100%;text-align:center}
    .form-actions{display:flex;flex-direction:column;gap:10px}
    .form-actions .button{width:100%;text-align:center}
}
</style>
<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
