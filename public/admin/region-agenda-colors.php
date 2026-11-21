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
            <div class="agenda-weekday-editor">
            <?php foreach ($weekdayNames as $i => $day): ?>
                <div class="agenda-weekday-editor-row">
                    <article class="agenda-card agenda-weekday-preview-card" data-day-card="<?= $i ?>">
                        <div class="agenda-card__media-wrap">
                            <div class="agenda-card__gallery" data-gallery>
                                <div class="agenda-card__gallery-track"><div class="agenda-card__media-slide"><div class="agenda-card__placeholder" data-day-placeholder="<?= $i ?>"><strong>SALSA · BACHATA · KIZOMBA</strong></div></div></div>
                                <button type="button" class="agenda-preview-slider-arrow agenda-preview-slider-prev" aria-label="Vorige foto">‹</button>
                                <button type="button" class="agenda-preview-slider-arrow agenda-preview-slider-next" aria-label="Volgende foto">›</button>
                                <span class="agenda-card__media-count">1 / 3</span>
                            </div>
                        </div>
                        <div class="agenda-card__content">
                        <div class="agenda-card__quick">
                            <button type="button" class="agenda-attendance" aria-label="Ik kom dansen"><span>👠</span><b><?= 5 + $i ?></b></button>
                            <button type="button" class="agenda-icon-button agenda-share" aria-label="Deel evenement" title="Deel evenement"><svg aria-hidden="true" viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><path d="m8.7 10.7 6.6-4.4M8.7 13.3l6.6 4.4"></path></svg></button>
                            <button type="button" class="agenda-icon-button agenda-location" aria-label="Toon locatie op kaart" title="Toon locatie op kaart"><svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg></button>
                            <button type="button" class="agenda-icon-button agenda-messages" aria-label="Berichten van evenement" title="Berichten van evenement"><svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"></path></svg><b class="agenda-message-count">2</b></button>
                        </div>
                        <div class="agenda-card__details">
                            <h2><?= escapeHtml($eventNames[$i]) ?></h2>
                            <p class="agenda-card__date-line"><?= escapeHtml($weekdayDates[$i]) ?> <span>◷</span> <?= escapeHtml($eventTimes[$i]) ?></p>
                            <p class="agenda-card__hashtags"><?= escapeHtml($eventTags[$i]) ?></p>
                            <p class="agenda-card__swipe-hint" data-swipe-hint>→ Swipe voor meer info</p>
                        </div>
                    </article>
                    <div class="agenda-weekday-color-controls">
                        <label>Kaartkleur<input type="color" name="weekday_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayColors[$i]) ?>"></label>
                        <label>Tekstkleur<input type="color" name="weekday_text_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayTextColors[$i]) ?>"></label>
                        <label>Titelkleur<input type="color" name="weekday_title_colors[<?= $i ?>]" value="<?= escapeHtml($weekdayTitleColors[$i]) ?>"></label>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="form-actions"><button class="button button--primary" type="submit">Agendakleuren opslaan</button><a class="button" href="/admin/region-colors.php?region_id=<?= (int) $regionId ?>">Terug</a></div>
        </form>
    </section>
</section>
<style>
.agenda-weekday-editor{display:flex;flex-direction:column;gap:12px}
.agenda-preview-slider-arrow{position:absolute;z-index:2;top:50%;transform:translateY(-50%);width:30px;height:36px;border:0;border-radius:5px;background:rgba(0,0,0,.58);color:#fff;font-size:25px;line-height:1;cursor:default}
.agenda-preview-slider-prev{left:8px}.agenda-preview-slider-next{right:8px}
.agenda-weekday-preview-card .agenda-card__media-wrap{position:relative;overflow:hidden}
.agenda-weekday-preview-card .agenda-card__gallery{position:relative;width:100%;height:100%;min-height:118px}
.agenda-weekday-preview-card .agenda-card__gallery-track,.agenda-weekday-preview-card .agenda-card__media-slide{height:100%;min-height:118px}
.agenda-weekday-preview-card .agenda-card__placeholder{height:100%;display:flex;align-items:center;justify-content:center}
.agenda-weekday-preview-card .agenda-card__media-count{position:absolute;right:9px;bottom:8px;z-index:2}
.agenda-weekday-preview-card .agenda-card__content{position:relative}
.agenda-weekday-preview-card .agenda-card__quick{display:flex;align-items:center;gap:9px;padding:8px 12px;background:var(--db-event-day-color);color:var(--db-event-day-text-color)}
.agenda-weekday-preview-card .agenda-attendance,.agenda-weekday-preview-card .agenda-icon-button{display:inline-flex;align-items:center;justify-content:center;gap:4px;min-width:34px;min-height:30px;border:0;background:transparent;color:var(--db-event-day-text-color);font-size:16px}
.agenda-weekday-preview-card .agenda-icon-button,.agenda-weekday-preview-card .agenda-icon-button:hover{font-size:20px;background:var(--db-event-day-color)!important;color:var(--db-event-day-text-color)!important}.agenda-weekday-preview-card .agenda-icon-button svg,.agenda-weekday-preview-card .agenda-icon-button svg *{stroke:var(--db-event-day-text-color)!important;color:var(--db-event-day-text-color)!important}.agenda-weekday-preview-card .agenda-attendance{background:var(--db-event-day-color)!important;color:var(--db-event-day-text-color)}.agenda-weekday-preview-card .agenda-message-count{color:var(--db-event-day-text-color)}
.agenda-weekday-preview-card .agenda-preview-switch{float:right;margin:0 0 6px 8px;padding:4px 9px;border:1px solid #cbd2dc;border-radius:5px;background:#f7f8fa;color:#333;font-size:11px}
.agenda-weekday-preview-card .agenda-card__details h2{clear:both}
.agenda-weekday-editor-row{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:14px;align-items:stretch;padding:10px;border:1px solid #e1e5eb;border-radius:10px;background:#fff}
.agenda-weekday-preview-card{margin:0;min-width:0}
.agenda-weekday-preview-card .agenda-card__media-wrap,.agenda-weekday-preview-card .agenda-card__placeholder{min-height:118px}
.agenda-weekday-preview-card .agenda-card__swipe-hint{
  display:block!important;
  width:100%;
  box-sizing:border-box;
  margin:0!important;
  padding:4px 12px 10px!important;
  text-align:left!important;
  color:var(--db-event-day-text-color)!important;
  font-size:12px;
  font-weight:700;
  line-height:1.4;
}
.agenda-weekday-preview-card .agenda-card__details{padding:12px}
.agenda-weekday-description{margin:6px 0 0;font-size:13px}
.agenda-weekday-color-controls{display:grid;align-content:center;gap:10px;padding:12px;border:1px solid #e1e5eb;border-radius:8px;background:#f8f9fb}
.agenda-weekday-color-controls h3{margin:0 0 3px;font-size:15px}
.agenda-weekday-color-controls label{display:grid;grid-template-columns:1fr 48px;align-items:center;gap:8px;font-size:12px;font-weight:600;color:#596273}
.agenda-weekday-color-controls input[type=color]{width:48px;height:34px;padding:2px;border:1px solid #d5dbe3;border-radius:6px;background:#fff;cursor:pointer}
@media(max-width:600px){.agenda-weekday-editor-row{grid-template-columns:1fr;gap:8px;padding:8px}.agenda-weekday-preview-card .agenda-card__media-wrap,.agenda-weekday-preview-card .agenda-card__placeholder{min-height:90px}.agenda-weekday-color-controls{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;padding:8px}.agenda-weekday-color-controls h3{grid-column:1/-1}.agenda-weekday-color-controls label{display:flex;flex-direction:column;align-items:flex-start;font-size:10px}.agenda-weekday-color-controls input[type=color]{width:100%;height:32px}}
</style>
<script>
(function(){
 const form=document.getElementById('agenda-weekday-colors-form'); if(!form)return;
 function update(){
  for(let i=0;i<7;i++){
   const card=form.querySelector('[data-day-card="'+i+'"]');
   const bg=form.querySelector('[name="weekday_colors['+i+']"]').value;
   const text=form.querySelector('[name="weekday_text_colors['+i+']"]').value;
   const title=form.querySelector('[name="weekday_title_colors['+i+']"]').value;
   card.style.setProperty('--db-event-day-color',bg);
   card.style.setProperty('--db-event-day-text-color',text);
   card.style.setProperty('--db-event-day-title-color',title);
   const media=card.querySelector('.agenda-card__media-wrap');
   const placeholder=card.querySelector('.agenda-card__placeholder');
   const details=card.querySelector('.agenda-card__details');
   const quick=card.querySelector('.agenda-card__quick');
   if(media)media.style.setProperty('background-color',bg,'important');
   if(quick){quick.style.setProperty('background-color',bg,'important');quick.style.setProperty('color',text,'important');quick.querySelectorAll('.agenda-attendance').forEach(el=>{el.style.setProperty('background-color',bg,'important');el.style.setProperty('color',text,'important')});quick.querySelectorAll('.agenda-message-count').forEach(el=>el.style.setProperty('color',text,'important'));quick.querySelectorAll('.agenda-icon-button').forEach(el=>{el.style.setProperty('background-color',bg,'important');el.style.setProperty('color',text,'important');el.querySelectorAll('svg,svg *').forEach(svg=>{svg.style.setProperty('stroke',text,'important');svg.style.setProperty('color',text,'important')})});}
   if(placeholder){placeholder.style.setProperty('background-color',bg,'important');placeholder.style.setProperty('color',text,'important')}
   if(details)details.style.setProperty('color',text,'important');
   const heading=details?.querySelector('h2');
   if(heading)heading.style.setProperty('color',title,'important');
   details?.querySelectorAll('.agenda-card__date-line,.agenda-card__hashtags,.agenda-weekday-description').forEach(el=>el.style.setProperty('color',text,'important'));
  }
 }
 form.querySelectorAll('input[type=color]').forEach(input=>{input.addEventListener('input',update);input.addEventListener('change',update)});
 update();
})();
</script>
<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
