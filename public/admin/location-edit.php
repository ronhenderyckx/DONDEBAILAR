<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Locations.php';
require_once dirname(__DIR__, 2) . '/app/LocationBranding.php';

$user = requireLogin();
$db = Database::connection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); exit('Ongeldig locatie-ID.'); }

$statement = $db->prepare("SELECT * FROM locations WHERE id = :id LIMIT 1");
$statement->execute(['id' => $id]);
$location = $statement->fetch();
if (!$location) { http_response_code(404); exit('Locatie niet gevonden.'); }
$regionId = (int) $location['region_id'];
if (!locationCanManageRegion($regionId, $user)) { http_response_code(403); exit('Je hebt geen rechten om deze locatie te wijzigen.'); }

$regionStatement = $db->prepare("SELECT id, name, country_code FROM regions WHERE id = :id LIMIT 1");
$regionStatement->execute(['id' => $regionId]);
$region = $regionStatement->fetch();
$errors = [];
$name = (string) $location['name'];
$street = (string) ($location['street'] ?? '');
$houseNumber = (string) ($location['house_number'] ?? '');
$postalCode = (string) ($location['postal_code'] ?? '');
$city = (string) $location['city'];
$countryCode = (string) $location['country_code'];
$mapsUrl = (string) ($location['google_maps_url'] ?? '');
$phone = (string) ($location['phone'] ?? '');
$website = (string) ($location['website_url'] ?? '');
$logoUrl = '';
$latitude = (string) ($location['latitude'] ?? '');
$longitude = (string) ($location['longitude'] ?? '');
$notes = (string) ($location['notes'] ?? '');
$isActive = (int) $location['is_active'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) $errors[] = 'De beveiligingscontrole is verlopen. Vernieuw de pagina.';
    $name = trim((string) ($_POST['name'] ?? ''));
    $street = trim((string) ($_POST['street'] ?? ''));
    $houseNumber = trim((string) ($_POST['house_number'] ?? ''));
    $postalCode = trim((string) ($_POST['postal_code'] ?? ''));
    $city = trim((string) ($_POST['city'] ?? ''));
    $countryCode = strtoupper(trim((string) ($_POST['country_code'] ?? '')));
    $mapsUrl = trim((string) ($_POST['google_maps_url'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $website = trim((string) ($_POST['website_url'] ?? ''));
    $logoUrl = trim((string) ($_POST['discovered_logo_url'] ?? ''));
    $latitude = trim((string) ($_POST['latitude'] ?? ''));
    $longitude = trim((string) ($_POST['longitude'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $isActive = isset($_POST['is_active']);

    if ($name === '' || mb_strlen($name) > 190) $errors[] = 'Vul een locatienaam in van maximaal 190 tekens.';
    if ($street !== '' && mb_strlen($street) > 190) $errors[] = 'De straatnaam is te lang.';
    if ($houseNumber !== '' && mb_strlen($houseNumber) > 40) $errors[] = 'Het huisnummer is te lang.';
    if ($postalCode !== '' && mb_strlen($postalCode) > 30) $errors[] = 'De postcode is te lang.';
    if ($city === '' || mb_strlen($city) > 120) $errors[] = 'Vul een gemeente in van maximaal 120 tekens.';
    if (!preg_match('/\A[A-Z]{2}\z/', $countryCode)) $errors[] = 'Gebruik een landcode van twee letters, bijvoorbeeld ES.';
    if ($mapsUrl !== '' && !isGoogleMapsUrl($mapsUrl)) $errors[] = 'Vul een geldige Google Maps-deellink in.';
    if ($phone !== '' && mb_strlen($phone) > 50) $errors[] = 'Het telefoonnummer is te lang.';
    if ($website !== '' && (mb_strlen($website) > 500 || !filter_var($website, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($website, PHP_URL_SCHEME)), ['http','https'], true))) $errors[] = 'Vul een geldige website in die begint met https:// of http://.';
    if ($latitude !== '' && (!is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90)) $errors[] = 'De breedtegraad moet tussen -90 en 90 liggen.';
    if ($longitude !== '' && (!is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180)) $errors[] = 'De lengtegraad moet tussen -180 en 180 liggen.';

    if ($errors === []) {
        try {
            $uploadedLogo = storeUploadedLocationLogo($_FILES['logo_file'] ?? []);
            $logoPath = $uploadedLogo ?? storeDiscoveredLocationLogo($logoUrl);
            $update = $db->prepare(
                "UPDATE locations SET name = :name, street = :street, house_number = :house_number,
                    postal_code = :postal_code, city = :city, country_code = :country_code,
                    latitude = :latitude, longitude = :longitude, google_maps_url = :google_maps_url,
                    phone = :phone, website_url = :website_url,
                    logo_path = COALESCE(:logo_path, logo_path), notes = :notes, is_active = :is_active
                 WHERE id = :id AND region_id = :region_id"
            );
            $update->execute([
                'name' => $name, 'street' => $street !== '' ? $street : null,
                'house_number' => $houseNumber !== '' ? $houseNumber : null,
                'postal_code' => $postalCode !== '' ? $postalCode : null,
                'city' => $city, 'country_code' => $countryCode,
                'latitude' => $latitude !== '' ? $latitude : null,
                'longitude' => $longitude !== '' ? $longitude : null,
                'google_maps_url' => $mapsUrl !== '' ? $mapsUrl : null,
                'phone' => $phone !== '' ? $phone : null,
                'website_url' => $website !== '' ? $website : null,
                'logo_path' => $logoPath,
                'notes' => $notes !== '' ? $notes : null,
                'is_active' => $isActive ? 1 : 0, 'id' => $id, 'region_id' => $regionId,
            ]);
            header('Location: /admin/locations.php?updated=1');
            exit;
        } catch (Throwable $exception) {
            $errors[] = 'De locatie kon niet worden bijgewerkt.';
        }
    }
}

$pageTitle = 'Locatie bewerken - DONDEBAILAR';
require dirname(__DIR__, 2) . '/app/views/header.php';
?>
<section class="admin-page">
    <div class="admin-heading">
        <div><p class="eyebrow">LOCATIES</p><h1>Locatie bewerken</h1><p><?= escapeHtml($region['name'] ?? '') ?></p></div>
        <a class="button" href="/admin/locations.php">Terug naar locaties</a>
    </div>
    <?php foreach ($errors as $error): ?><div class="message message--error"><?= escapeHtml($error) ?></div><?php endforeach; ?>
    <section class="admin-section location-form-section">
        <form method="post" enctype="multipart/form-data" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="google_maps_url">Google Maps-link</label>
            <div class="maps-url-row"><input id="google_maps_url" name="google_maps_url" type="url" maxlength="700" value="<?= escapeHtml($mapsUrl) ?>" placeholder="https://maps.google.com/?cid=..."><button type="button" id="maps-lookup-button" aria-label="Haal adresgegevens op uit Google Maps" style="display:inline-flex!important;visibility:visible!important;opacity:1!important;align-items:center;justify-content:center;flex:0 0 auto;min-height:42px;padding:9px 16px;border:1px solid #1769aa;border-radius:7px;background:#1769aa;color:#fff;font-weight:700;white-space:nowrap;cursor:pointer;position:relative;z-index:2">Haal gegevens</button></div>
            <p id="maps-lookup-status" class="location-form-note" role="status" aria-live="polite">Plak een Google Maps-link om het adres automatisch op te halen.</p>
            <label for="name">Naam locatie *</label><input id="name" name="name" maxlength="190" required value="<?= escapeHtml($name) ?>">
            <div class="location-form-grid">
                <div><label for="street">Straat</label><input id="street" name="street" maxlength="190" value="<?= escapeHtml($street) ?>"></div>
                <div><label for="house_number">Huisnummer</label><input id="house_number" name="house_number" maxlength="40" value="<?= escapeHtml($houseNumber) ?>"></div>
                <div><label for="postal_code">Postcode</label><input id="postal_code" name="postal_code" maxlength="30" value="<?= escapeHtml($postalCode) ?>"></div>
                <div><label for="city">Gemeente / stad *</label><input id="city" name="city" maxlength="120" required value="<?= escapeHtml($city) ?>"></div>
                <div><label for="country_code">Landcode *</label><input id="country_code" name="country_code" maxlength="2" minlength="2" required value="<?= escapeHtml($countryCode) ?>"></div>
                <div><label for="phone">Telefoon</label><input id="phone" name="phone" type="tel" maxlength="50" value="<?= escapeHtml($phone) ?>"></div>
                <div><label for="latitude">Breedtegraad</label><input id="latitude" name="latitude" type="number" step="any" min="-90" max="90" value="<?= escapeHtml($latitude) ?>"></div>
                <div><label for="longitude">Lengtegraad</label><input id="longitude" name="longitude" type="number" step="any" min="-180" max="180" value="<?= escapeHtml($longitude) ?>"></div>
            </div>
            <label for="website_url">Website</label><input id="website_url" name="website_url" type="url" maxlength="500" value="<?= escapeHtml($website) ?>">
            <?php if (preg_match('~\\Auploads/locations/[a-f0-9]{32}\\.(?:jpg|png|webp)\\z~D', (string) ($location['logo_path'] ?? ''))): ?>
                <div class="current-location-logo"><img src="/location-logo.php?file=<?= rawurlencode(basename((string) $location['logo_path'])) ?>" alt="Huidig locatielogo"></div>
            <?php endif; ?>
            <label for="logo_file">Logo uploaden (PNG, JPG of WebP – maximaal 5 MB)</label>
            <input id="logo_file" name="logo_file" type="file" accept="image/png,image/jpeg,image/webp">
            <input type="hidden" id="discovered_logo_url" name="discovered_logo_url" value="<?= escapeHtml($logoUrl) ?>">
            <div class="location-brand-search"><div id="brand-logo-preview"></div></div>
            <label for="notes">Opmerkingen</label><textarea id="notes" name="notes" rows="5"><?= escapeHtml($notes) ?></textarea>
            <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>> Actieve locatie</label>
            <div class="form-actions"><button class="button button--primary" type="submit">Wijzigingen opslaan</button><a class="button" href="/admin/locations.php">Annuleren</a></div>
        </form>
    </section>
</section>
<style>
.location-form-section{max-width:900px}.location-form-section .auth-form{max-width:760px}.location-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 14px}.location-form-grid>div{min-width:0}.location-form-note{font-size:13px;color:var(--db-muted)}.maps-url-row{display:flex!important;flex-direction:row!important;flex-wrap:nowrap!important;gap:10px;align-items:center;width:100%;max-width:100%;overflow:visible}.maps-url-row input{display:block;flex:1 1 auto;min-width:0;width:100%}.maps-url-row #maps-lookup-button{display:inline-flex!important;visibility:visible!important;opacity:1!important;flex:0 0 auto!important;white-space:nowrap!important;align-self:center!important}@media(max-width:480px){.maps-url-row{flex-wrap:wrap!important}.maps-url-row input{flex-basis:100%}.maps-url-row #maps-lookup-button{width:auto!important}}.location-brand-search{margin:12px 0 18px}.location-brand-search span{display:block;font-size:13px;color:var(--db-muted);margin-top:6px}.location-brand-search img,.current-location-logo img{max-width:180px;max-height:100px;object-fit:contain;background:#fff;padding:8px;border:1px solid #ddd;border-radius:6px}.current-location-logo{margin:10px 0 16px}@media(max-width:600px){.location-form-grid{grid-template-columns:1fr}}
</style>
<script>
(() => {
 const mapsInput=document.getElementById('google_maps_url');
 const csrf=document.querySelector('input[name="csrf_token"]')?.value||'';
 const fields=['name','street','house_number','postal_code','city','country_code','latitude','longitude','phone','website_url'];
 const logoField=document.getElementById('discovered_logo_url');
 const preview=document.getElementById('brand-logo-preview');
 if(!mapsInput||!csrf)return;
 let timer=null, lastUrl='', requestNumber=0;
 const mapsStatus=document.getElementById('maps-lookup-status');
 function showMapsStatus(message,isError=false){if(mapsStatus){mapsStatus.textContent=message;mapsStatus.style.color=isError?'#b42318':'';}}
 let lastBrandSearch='';
 async function searchLocationBranding(){
  const name=document.getElementById('name')?.value.trim()||'';
  const city=document.getElementById('city')?.value.trim()||'';
  const countryCode=document.getElementById('country_code')?.value.trim().toUpperCase()||'';
  if(!name||!city||!/^[A-Z]{2}$/.test(countryCode))return;
  const searchKey=[name,city,countryCode].join('|');
  if(searchKey===lastBrandSearch)return;
  lastBrandSearch=searchKey;
  try{
   const search=await fetch('/admin/location-search.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({name,city,country_code:countryCode})});
   const result=await search.json();
   if(!search.ok||!result.success){
    lastBrandSearch='';
    if(preview)preview.textContent='Website/logo niet gevonden: '+(result.message||'Zoeken is mislukt.');
    return;
   }
   const website=document.getElementById('website_url');
   if(website&&result.website_url)website.value=result.website_url;
   const phone=document.getElementById('phone');
   if(phone&&result.phone&&!phone.value.trim())phone.value=result.phone;
   if(result.logo_url&&logoField&&preview){
    logoField.value=result.logo_url;
    const img=document.createElement('img');
    img.src=result.logo_url;img.alt='Locatielogo';img.referrerPolicy='no-referrer';
    img.style='max-width:180px;max-height:100px;object-fit:contain;display:block;margin-top:8px';
    img.onerror=()=>{logoField.value='';img.remove();};
    preview.replaceChildren(img);
   }
  }catch(error){
   lastBrandSearch='';
   if(preview)preview.textContent='Website/logo kon niet worden opgehaald. Controleer de internetverbinding van de server.';
  }
 }
 async function resolveAndSearch(){
  const url=mapsInput.value.trim();
  if(!url)return;
  if(url===lastUrl)return;
  lastUrl=url;
  const thisRequest=++requestNumber;
  showMapsStatus('Google Maps-link wordt opgehaald…');
  try{
   const response=await fetch('/admin/location-resolve.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({url})});
   const responseText=await response.text();
   let data=null;
   try{data=JSON.parse(responseText);}catch(parseError){
    const detail=responseText.replace(/<[^>]*>/g,' ').replace(/\\s+/g,' ').trim().slice(0,220);
    throw new Error('Server gaf geen geldige JSON terug (HTTP '+response.status+'). '+(detail||'Lege serverreactie.'));
   }
   if(thisRequest!==requestNumber)return;
   if(!response.ok||!data.success){lastUrl='';showMapsStatus((data.message||'De kaartgegevens konden niet worden opgehaald.')+' (HTTP '+response.status+')',true);return;}
   for(const key of fields){
    const el=document.getElementById(key);
    if(el&&data.location[key]!==undefined&&data.location[key]!==null&&data.location[key]!=='')el.value=data.location[key];
   }
   let mapsMessage=data.message||'Adresgegevens ingevuld. Controleer het resultaat.';
   if(!data.location.phone&&data.phone_debug){
    mapsMessage+=' Telefoon niet gevonden (Maps HTTP '+data.phone_debug.http_status+', pagina '+data.phone_debug.page_bytes+' bytes).';
   }
   showMapsStatus(mapsMessage,!data.location.phone);
   await searchLocationBranding();
  }catch(error){
   lastUrl='';
   const message=error&&error.message?error.message:'Onbekende fout tijdens de aanvraag.';
   showMapsStatus('Kaartopzoeking mislukt: '+message,true);
  }
 }
 ['name','city','country_code'].forEach(id=>{
  const field=document.getElementById(id);
  if(field){
   field.addEventListener('input',()=>{lastBrandSearch='';searchLocationBranding();});
   field.addEventListener('change',()=>{lastBrandSearch='';searchLocationBranding();});
  }
 });
 document.getElementById('maps-lookup-button')?.addEventListener('click',()=>{clearTimeout(timer);lastUrl='';resolveAndSearch();});
 mapsInput.addEventListener('input',()=>{
  clearTimeout(timer);lastUrl='';
  timer=setTimeout(resolveAndSearch,700);
 });
 mapsInput.addEventListener('change',()=>{clearTimeout(timer);lastUrl='';resolveAndSearch();});
 mapsInput.addEventListener('paste',()=>{
  clearTimeout(timer);lastUrl='';
  timer=setTimeout(resolveAndSearch,900);
 });
 // Existing locations already have a Maps link when the edit form opens.
 // Resolve it on page load as well as after a manual paste/change.
 if(mapsInput.value.trim()){
  setTimeout(resolveAndSearch,150);
 }
})();
</script>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
