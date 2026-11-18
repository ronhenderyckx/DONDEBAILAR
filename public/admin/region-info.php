<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

$db = Database::connection();
$regionId = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT);
if (!$regionId || $regionId < 1) { http_response_code(400); exit('Ongeldig regionummer.'); }
requireRegionRole($regionId, ['region_admin']);

$query = $db->prepare(
    "SELECT r.id, r.name, r.default_language, r.logo_path,
            a.primary_color, a.primary_dark, a.accent_color, a.secondary_color,
            a.tertiary_color, a.header_color, a.menu_color, a.organizer_color,
            a.filter_hover_color, a.filter_bar_color, a.filter_text_color,
            a.search_button_color, a.back_top_color, a.search_field_color,
            a.search_text_color, a.footer_color, a.background_color, a.text_color,
            a.weekday_colors
     FROM regions r LEFT JOIN region_appearance a ON a.region_id = r.id
     WHERE r.id = :id AND r.is_active = 1 LIMIT 1"
);
$query->execute(['id' => $regionId]);
$region = $query->fetch(PDO::FETCH_ASSOC);
if (!$region) { http_response_code(404); exit('Actieve regio niet gevonden.'); }

$languages = ['nl'=>'Nederlands','es'=>'Spaans','en'=>'Engels','fr'=>'Frans'];
$defaultLanguage = strtolower((string) ($region['default_language'] ?? 'nl'));
if (!isset($languages[$defaultLanguage])) $defaultLanguage = 'nl';
$storageDirectory = DONDEBAILAR_PRIVATE_ROOT . '/storage/region-info';
$storageFile = $storageDirectory . '/' . $regionId . '.json';
$translations = [];
if (is_file($storageFile)) {
    $decoded = json_decode((string) file_get_contents($storageFile), true);
    if (is_array($decoded)) $translations = $decoded;
}
$errorMessage = '';
$successMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $newTranslations = [
            $defaultLanguage => [
                'title' => mb_substr($title, 0, 180),
                'body' => mb_substr($body, 0, 30000),
            ],
        ];
        if (trim($newTranslations[$defaultLanguage]['title']) === '' && trim($newTranslations[$defaultLanguage]['body']) === '') {
            $errorMessage = 'Vul minstens de titel of tekst in (' . $languages[$defaultLanguage] . ').';
        } else {
            if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0755, true) && !is_dir($storageDirectory)) {
                $errorMessage = 'De opslagmap kon niet worden aangemaakt.';
            } else {
                $json = json_encode($newTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($json === false || file_put_contents($storageFile, $json . PHP_EOL, LOCK_EX) === false) {
                    $errorMessage = 'De infopagina kon niet worden opgeslagen.';
                } else {
                    $translations = $newTranslations;
                    $successMessage = 'De infopagina is opgeslagen.';
                }
            }
        }
    }
}
$pageTitle = 'Info-pagina - ' . $region['name'];
require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div><p class="eyebrow">REGIOBEHEER</p><h1>Info-pagina – <?= escapeHtml($region['name']) ?></h1><p>Je bewerkt de openbare informatie van deze regio in de standaardtaal: <?= escapeHtml($languages[$defaultLanguage]) ?>.</p></div>
        <a class="button" href="/info.php?region_id=<?= (int) $regionId ?>" target="_blank" rel="noopener">Bekijk infopagina</a>
    </div>
    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?><div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
        <?php if ($successMessage !== ''): ?><div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div><?php endif; ?>
        <form method="post" class="auth-form region-info-editor">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <p>De infopagina gebruikt één taal: de ingestelde standaardtaal van deze regio (<?= escapeHtml($languages[$defaultLanguage]) ?>).</p>
            <?php $translation = $translations[$defaultLanguage] ?? []; if (!is_array($translation)) $translation = []; ?>
            <fieldset>
                <legend><?= escapeHtml($languages[$defaultLanguage]) ?></legend>
                <label for="info_title">Titel</label>
                <input type="text" id="info_title" name="title" maxlength="180" value="<?= escapeHtml($translation['title'] ?? '') ?>">
                <label for="info_body">Informatietekst</label>
                <textarea id="info_body" name="body" rows="9" maxlength="30000"><?= escapeHtml($translation['body'] ?? '') ?></textarea>
            </fieldset>
            <div class="form-actions"><button type="submit" class="button button--primary">Info-pagina opslaan</button><a class="button" href="<?= isPlatformAdmin(currentUser()) ? '/admin/region-edit.php?id=' . (int) $regionId : '/events.php' ?>">Terug</a></div>
        </form>
    </section>
</section>
<style>
.region-info-editor fieldset{display:grid;gap:9px;margin:22px 0;padding:18px;border:1px solid #dce1e8;border-radius:12px}
.region-info-editor legend{padding:0 8px;font-weight:800;color:var(--db-primary)}
.region-info-editor textarea{width:100%;min-height:180px;resize:vertical}
.region-info-editor input[type=text],.region-info-editor textarea{box-sizing:border-box;padding:11px;border:1px solid #cbd2dc;border-radius:8px;font:inherit}
</style>
<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
