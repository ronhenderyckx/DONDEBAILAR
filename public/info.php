<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Security.php';
require_once dirname(__DIR__) . '/app/Authorization.php';

$db = Database::connection();
$regions = $db->query(
    "SELECT r.id, r.name, r.default_language, r.logo_path,
            a.primary_color, a.primary_dark, a.accent_color, a.secondary_color,
            a.tertiary_color, a.header_color, a.menu_color, a.organizer_color,
            a.filter_hover_color, a.filter_bar_color, a.filter_text_color,
            a.search_button_color, a.back_top_color, a.search_field_color,
            a.search_text_color, a.footer_color, a.background_color, a.text_color,
            a.logo_path AS appearance_logo_path, a.weekday_colors
     FROM regions r
     LEFT JOIN region_appearance a ON a.region_id = r.id
     WHERE r.is_active = 1 ORDER BY r.id"
)->fetchAll(PDO::FETCH_ASSOC);

$regionId = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT) ?: 0;
$region = null;
foreach ($regions as $candidate) {
    if ((int) $candidate['id'] === $regionId) { $region = $candidate; break; }
}
if ($region === null && $regions !== []) $region = $regions[0];
if ($region === null) {
    http_response_code(404);
    exit('Er is nog geen actieve regio beschikbaar.');
}
$regionId = (int) $region['id'];

$languages = ['nl', 'es', 'en', 'fr'];
$defaultLanguage = strtolower((string) ($region['default_language'] ?? 'nl'));
if (!in_array($defaultLanguage, $languages, true)) $defaultLanguage = 'nl';
$language = $defaultLanguage;

$storageFile = dirname(__DIR__) . '/storage/region-info/' . $regionId . '.json';
$translations = [];
if (is_file($storageFile)) {
    $decoded = json_decode((string) file_get_contents($storageFile), true);
    if (is_array($decoded)) $translations = $decoded;
}
$content = $translations[$defaultLanguage] ?? [];
if (!is_array($content)) $content = [];

$labels = [
    'nl' => ['info' => 'Info', 'back' => 'Terug naar agenda', 'empty' => 'De informatie voor deze regio wordt binnenkort toegevoegd.'],
    'es' => ['info' => 'Información', 'back' => 'Volver a la agenda', 'empty' => 'La información de esta región estará disponible próximamente.'],
    'en' => ['info' => 'Info', 'back' => 'Back to calendar', 'empty' => 'Information for this region will be added soon.'],
    'fr' => ['info' => 'Infos', 'back' => 'Retour à l’agenda', 'empty' => 'Les informations de cette région seront bientôt disponibles.'],
];
$label = $labels[$language];
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = 'DONDEBAILAR - ' . (string) $region['name'];
$pageRobots = 'index, follow';
require dirname(__DIR__) . '/app/views/header.php';
?>
<section class="region-info-page">
    <div class="region-info-heading">
        <div class="region-info-region">
            <?php
            $regionLogoPath = trim((string) ($region['appearance_logo_path'] ?? ''));
            if ($regionLogoPath === '') $regionLogoPath = trim((string) ($region['logo_path'] ?? ''));
            $regionIcon = '/' . ltrim($regionLogoPath, '/');
            $hasRegionIcon = preg_match('#^/uploads/regions/[a-zA-Z0-9._/-]+$#', $regionIcon)
                && !str_contains($regionIcon, '/../')
                && !str_contains($regionIcon, '/./');
            ?>
            <?php if ($hasRegionIcon): ?>
                <img src="<?= $escape($regionIcon) ?>" alt="" class="region-info-region-icon">
            <?php endif; ?>
            <h1><?= $escape($region['name']) ?></h1>
        </div>
        <?php if (trim((string) ($content['title'] ?? '')) !== ''): ?>
            <h2 class="region-info-subtitle"><?= $escape($content['title']) ?></h2>
        <?php endif; ?>
    </div>
    <?php if (trim((string) ($content['body'] ?? '')) !== ''): ?>
        <div class="region-info-content"><?= nl2br($escape($content['body'])) ?></div>
    <?php else: ?>
        <p class="region-info-empty"><?= $escape($label['empty']) ?></p>
    <?php endif; ?>
</section>
<style>
.region-info-page{max-width:900px;margin:30px auto;padding:clamp(20px,4vw,42px);background:var(--db-menu-color,#fff);color:var(--db-text,#222);border-radius:18px;box-shadow:0 8px 30px rgba(0,0,0,.08);border-top:6px solid var(--db-primary,#e23a2c)}
.region-info-region{display:flex;align-items:center;gap:12px;margin:0 0 16px}
.region-info-region-icon{display:block;width:auto;height:auto;max-width:72px;max-height:64px;object-fit:contain}
.region-info-region h1{margin:0;color:var(--db-primary-dark,var(--db-primary));font-size:clamp(28px,5vw,42px)}
.region-info-heading .region-info-subtitle{margin:0 0 24px;color:var(--db-text,#222);font-size:clamp(19px,3vw,25px)}
.region-info-content{font-size:17px;line-height:1.8;overflow-wrap:anywhere}
.region-info-empty{padding:22px;background:var(--db-background,#f7f8fa);border-radius:12px}
</style>
<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
