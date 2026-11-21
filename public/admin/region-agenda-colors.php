<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

$db = Database::connection();
$regionId = filter_input(INPUT_GET, 'region_id', FILTER_VALIDATE_INT);
if (!$regionId || $regionId < 1) {
    http_response_code(400);
    exit('Ongeldig regionummer.');
}
requireRegionRole((int) $regionId, ['region_admin']);

$regionQuery = $db->prepare('SELECT id, name FROM regions WHERE id = :id LIMIT 1');
$regionQuery->execute(['id' => $regionId]);
$region = $regionQuery->fetch();
if (!$region) {
    http_response_code(404);
    exit('Regio niet gevonden.');
}

$weekdayNames = ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'];
$weekdayDates = [
    'MAANDAG, 16 NOV 2026', 'DINSDAG, 17 NOV 2026', 'WOENSDAG, 18 NOV 2026',
    'DONDERDAG, 19 NOV 2026', 'VRIJDAG, 20 NOV 2026', 'ZATERDAG, 21 NOV 2026', 'ZONDAG, 22 NOV 2026'
];
$eventNames = ['Salsa beginnersles', 'Bachata open training', 'Latin dance social', 'Kizomba workshop', 'Salsa & Bachata Social', 'Latin Dance Night', 'Sunday dance practice'];
$eventTags = ['#Salsa #Dénia', '#Bachata #Jávea', '#Latin #Calpe', '#Kizomba #Altea', '#Bachata #Dénia', '#Salsa #Bachata #Jávea', '#Dansen #MarinaAlta'];
$eventTimes = ['19:00', '19:30', '20:00', '20:30', '21:00', '21:30', '18:00'];
$defaultColors = ['#F6D84A', '#4A90D9', '#55A96B', '#A78BDA', '#E78AAE', '#F49A45', '#999999'];
$defaultText = array_fill(0, 7, '#222222');
$defaultTitles = array_fill(0, 7, '#222222');

$appearanceQuery = $db->prepare('SELECT weekday_colors FROM region_appearance WHERE region_id = :id LIMIT 1');
$appearanceQuery->execute(['id' => $regionId]);
$appearance = $appearanceQuery->fetch() ?: [];
$weekdayData = json_decode((string) ($appearance['weekday_colors'] ?? ''), true);
if (!is_array($weekdayData)) $weekdayData = [];
$weekdayColors = $defaultColors;
$weekdayTextColors = $defaultText;
$weekdayTitleColors = $defaultTitles;
foreach ($weekdayNames as $i => $_day) {
    $candidate = strtoupper((string) ($weekdayData[$i] ?? $defaultColors[$i]));
    if (preg_match('/^#[0-9A-F]{6}$/', $candidate)) $weekdayColors[$i] = $candidate;
    $candidate = strtoupper((string) ($weekdayData['weekday_text_' . $i] ?? '#222222'));
    if (preg_match('/^#[0-9A-F]{6}$/', $candidate)) $weekdayTextColors[$i] = $candidate;
    $candidate = strtoupper((string) ($weekdayData['weekday_title_' . $i] ?? '#222222'));
    if (preg_match('/^#[0-9A-F]{6}$/', $candidate)) $weekdayTitleColors[$i] = $candidate;
}

$errorMessage = '';
$successMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errorMessage = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    } else {
        $submitted = [
            'weekday_colors' => is_array($_POST['weekday_colors'] ?? null) ? $_POST['weekday_colors'] : [],
            'weekday_text_colors' => is_array($_POST['weekday_text_colors'] ?? null) ? $_POST['weekday_text_colors'] : [],
            'weekday_title_colors' => is_array($_POST['weekday_title_colors'] ?? null) ? $_POST['weekday_title_colors'] : [],
        ];
        foreach ($weekdayNames as $i => $_day) {
            $values = [
                ['source' => 'weekday_colors', 'current' => $weekdayColors[$i]],
                ['source' => 'weekday_text_colors', 'current' => $weekdayTextColors[$i]],
                ['source' => 'weekday_title_colors', 'current' => $weekdayTitleColors[$i]],
            ];
            foreach ($values as $item) {
                $candidate = strtoupper(trim((string) ($submitted[$item['source']][$i] ?? $item['current'])));
                if (!preg_match('/^#[0-9A-F]{6}$/', $candidate)) {
                    $errorMessage = 'Een of meer kleurcodes zijn ongeldig.';
                    break 2;
                }
                if ($item['source'] === 'weekday_colors') $weekdayColors[$i] = $candidate;
                if ($item['source'] === 'weekday_text_colors') $weekdayTextColors[$i] = $candidate;
                if ($item['source'] === 'weekday_title_colors') $weekdayTitleColors[$i] = $candidate;
            }
        }
        if ($errorMessage === '') {
            foreach ($weekdayNames as $i => $_day) {
                $weekdayData[$i] = $weekdayColors[$i];
                $weekdayData['weekday_text_' . $i] = $weekdayTextColors[$i];
                $weekdayData['weekday_title_' . $i] = $weekdayTitleColors[$i];
            }
            $encoded = json_encode($weekdayData, JSON_THROW_ON_ERROR);
            $exists = $db->prepare('SELECT region_id FROM region_appearance WHERE region_id = :id LIMIT 1');
            $exists->execute(['id' => $regionId]);
            if ($exists->fetchColumn()) {
                $save = $db->prepare('UPDATE region_appearance SET weekday_colors = :colors WHERE region_id = :id');
                $save->execute(['id' => $regionId, 'colors' => $encoded]);
            } else {
                $save = $db->prepare("INSERT INTO region_appearance
                    (region_id, primary_color, primary_dark, accent_color, secondary_color, tertiary_color,
                     header_color, menu_color, organizer_color, filter_hover_color, filter_bar_color, filter_text_color,
                     past_event_color, search_button_color, back_top_color, search_field_color, search_text_color,
                     footer_color, background_color, text_color, weekday_colors, logo_path)
                    VALUES (:id, '#E23A2C', '#8F211B', '#F6B22E', '#1FA898', '#F4802A',
                     '#222222', '#FFFFFF', '#E23A2C', '#E23A2C', '#FFFFFF', '#222222',
                     '#777777', '#E23A2C', '#E23A2C', '#FFFFFF', '#222222',
                     '#FFFFFF', '#FFFFFF', '#222222', :colors, NULL)");
                $save->execute(['id' => $regionId, 'colors' => $encoded]);
            }
            $successMessage = 'De agendakleuren zijn opgeslagen.';
        }
    }
}

$pageTitle = 'Agendakleuren - ' . $region['name'];
$platformAdminPage = true;
require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<section class="admin-page region-agenda-colors-page">
    <div class="admin-heading">
        <div><p class="eyebrow">REGIOBEHEER</p><h1>Agendakleuren</h1><p>Stel per weekdag de kleuren van de evenementenkaarten in voor <?= escapeHtml($region['name']) ?>.</p></div>
        <a class="button" href="/admin/region-colors.php?region_id=<?= (int) $regionId ?>">Terug naar kleuren en logo</a>
    </div>
    <?php if ($errorMessage !== ''): ?><div class="message message--error" role="alert"><?= escapeHtml($errorMessage) ?></div><?php endif; ?>
    <?php if ($successMessage !== ''): ?><div class="message message--success" role="status"><?= escapeHtml($successMessage) ?></div><?php endif; ?>
    <section class="admin-section">
        <div class="section-heading"><div><h2>Live agenda-voorbeeld</h2><p>Elke kaart toont fictieve evenementgegevens. Pas de kleuren rechts aan en zie onmiddellijk het resultaat.</p></div></div>
        <form method="post" id="agenda-weekday-colors-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <div class="agenda-fullscreen-editor">
                <div class="agenda-fullscreen-preview">
                    <iframe
                        id="agenda-live-preview"
                        title="Volledige live agenda Costa Blanca"
                        src="/?region_id=<?= (int) $regionId ?>"
                        loading="eager"
                    ></iframe>
                </div>
                <aside class="agenda-weekday-color-panel" aria-label="Kleuren per weekdag">
                    <h3>Kleuren per weekdag</h3>
                    <p>De volledige agenda wordt links getoond. Wijzig rechts de kleuren en bekijk meteen het resultaat.</p>
                    <?php foreach ($weekdayNames as $i => $day): ?>
                        <section class="agenda-weekday-color-group">
                            <h4><?= escapeHtml($day) ?></h4>
                            <label>Kaartkleur<input type="color" name="weekday_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayColors[$i]) ?>"></label>
                            <label>Tekstkleur<input type="color" name="weekday_text_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayTextColors[$i]) ?>"></label>
                            <label>Titelkleur<input type="color" name="weekday_title_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayTitleColors[$i]) ?>"></label>
                        </section>
                    <?php endforeach; ?>
                </aside>
            </div>
            <div class="form-actions"><button class="button button--primary" type="submit">Agendakleuren opslaan</button><a class="button" href="/admin/region-colors.php?region_id=<?= (int) $regionId ?>">Terug</a></div>
        </form>
    </section>
</section>
<style>
.region-agenda-colors-page{width:100%;max-width:none;margin-left:0;margin-right:0}
.region-agenda-colors-page>.admin-section{width:100%;max-width:none}
.region-agenda-colors-page .section-heading{margin-bottom:10px}
.agenda-fullscreen-editor{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px;align-items:start;width:100%}
.agenda-fullscreen-preview{min-width:0;width:100%;height:calc(100vh - 245px);min-height:620px;border:1px solid #dce2e9;border-radius:9px;overflow:hidden;background:#fff}
.agenda-fullscreen-preview iframe{display:block;width:100%;height:100%;border:0;background:#fff}
.agenda-weekday-color-panel{position:sticky;top:12px;max-height:calc(100vh - 265px);overflow:auto;padding:12px;border:1px solid #e1e5eb;border-radius:9px;background:#f8f9fb}
.agenda-weekday-color-panel>h3{margin:0 0 5px;font-size:16px}
.agenda-weekday-color-panel>p{margin:0 0 10px;font-size:12px;line-height:1.4;color:#596273}
.agenda-weekday-color-group{display:grid;grid-template-columns:minmax(0,1fr) 48px;gap:5px 8px;padding:9px 0;border-top:1px solid #e1e5eb}
.agenda-weekday-color-group h4{grid-column:1/-1;margin:0;font-size:13px}
.agenda-weekday-color-group label{display:contents;font-size:11px;font-weight:600;color:#596273}
.agenda-weekday-color-group input[type=color]{grid-column:2;width:48px;height:29px;padding:2px;border:1px solid #d5dbe3;border-radius:5px;background:#fff;cursor:pointer}
.agenda-weekday-color-group label{display:grid;grid-template-columns:1fr 48px;grid-column:1/-1;align-items:center;gap:8px}
.agenda-weekday-color-group label input[type=color]{grid-column:2}
.region-agenda-colors-page .form-actions{margin-top:12px}
@media(max-width:900px){
 .agenda-fullscreen-editor{grid-template-columns:minmax(0,1fr) 250px;gap:9px}
 .agenda-fullscreen-preview{height:calc(100vh - 230px);min-height:560px}
 .agenda-weekday-color-panel{max-height:calc(100vh - 250px);padding:9px}
}
@media(max-width:680px){
 .agenda-fullscreen-editor{grid-template-columns:1fr}
 .agenda-fullscreen-preview{height:72vh;min-height:520px}
 .agenda-weekday-color-panel{position:static;max-height:none;overflow:visible}
 .agenda-weekday-color-group{grid-template-columns:repeat(3,minmax(0,1fr))}
 .agenda-weekday-color-group h4{grid-column:1/-1}
 .agenda-weekday-color-group label{display:flex;flex-direction:column;align-items:flex-start;gap:4px}
 .agenda-weekday-color-group input[type=color]{width:100%;height:34px}
}
</style>
<script>
(function(){
 const form=document.getElementById('agenda-weekday-colors-form');
 const frame=document.getElementById('agenda-live-preview');
 if(!form||!frame)return;
 function applyAgendaColors(){
  try{
   const doc=frame.contentDocument;
   if(!doc||!doc.documentElement)return;
   const root=doc.documentElement;
   for(let i=0;i<7;i++){
    const bg=form.querySelector('[name="weekday_colors['+i+']"]').value;
    const text=form.querySelector('[name="weekday_text_colors['+i+']"]').value;
    const title=form.querySelector('[name="weekday_title_colors['+i+']"]').value;
    root.style.setProperty('--db-weekday-'+(i+1),bg);
    root.style.setProperty('--db-weekday-text-'+(i+1),text);
    root.style.setProperty('--db-weekday-title-'+(i+1),title);
   }
  }catch(error){/* Preview blijft bruikbaar als de browser iframe-toegang beperkt. */}
 }
 frame.addEventListener('load',applyAgendaColors);
 form.querySelectorAll('input[type=color]').forEach(input=>{
  input.addEventListener('input',applyAgendaColors);
  input.addEventListener('change',applyAgendaColors);
 });
})();
</script>
<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
