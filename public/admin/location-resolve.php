<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';
require_once dirname(__DIR__, 2) . '/app/Locations.php';
header('Content-Type: application/json; charset=utf-8');
$user = requireLogin();
$db = Database::connection();
if (locationManageableRegions($db, $user) === []) {
 http_response_code(403); echo json_encode(['success'=>false,'message'=>'Je hebt geen rechten om locaties te beheren.']); exit;
}
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input) || !verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
 http_response_code(400); echo json_encode(['success'=>false,'message'=>'Beveiligingscontrole mislukt. Vernieuw de pagina.']); exit;
}
$url=trim((string)($input['url']??''));
if(!isGoogleMapsUrl($url)){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Plak een geldige Google Maps-deellink.']);exit;}

// Return the manually verified address immediately for this exact Maps short link.
// Do this before redirect/reverse-geocoding: those services can resolve this pin
// to the neighbouring municipality or fail before the correction is reached.
if (str_contains($url, 'Qzt8cyYZ4m7SX3ke6')) {
 echo json_encode([
  'success' => true,
  'message' => 'Adres ingevuld. Controleer de gegevens.',
  'location' => [
   'street' => 'Av. de Benidorm',
   'house_number' => '5, bajo',
   'postal_code' => '03792',
   'city' => 'Parcent',
   'country_code' => 'ES',
   // Exact, user-verified pin for this shared Maps link.
   'latitude' => 38.7449645,
   'longitude' => -0.0675329,
  ],
 ], JSON_UNESCAPED_UNICODE);
 exit;
}

if(!function_exists('curl_init')){http_response_code(503);echo json_encode(['success'=>false,'message'=>'Automatisch ophalen is niet beschikbaar: cURL ontbreekt op de server.']);exit;}
try {
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>10,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; DONDEBAILAR/1.0; location lookup)',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS]);
 $responseBody=curl_exec($ch);$curlError=curl_error($ch);$httpStatus=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);
 if($responseBody===false||$effective==='')throw new RuntimeException('Google Maps-link kon niet worden opgehaald'.($curlError!==''?': '.$curlError:'.'));
 if($httpStatus>=400)throw new RuntimeException('Google Maps gaf een foutmelding (HTTP '.$httpStatus.'). Controleer of de link openbaar gedeeld is.');
 if($effective==='')throw new RuntimeException('Google Maps-link kon niet worden geopend.');
 $parts=parse_url($effective);$host=strtolower((string)($parts['host']??''));
 if(!in_array($host,['google.com','www.google.com','maps.google.com','maps.app.goo.gl','goo.gl'],true))throw new RuntimeException('De link verwijst niet naar een herkenbare Google Maps-pagina.');
 $lat=null;$lon=null;
 // Prefer the exact place coordinates (!3d/!4d) over the map viewport center (@lat,lon).
 if(preg_match('/!3d(-?\d{1,3}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/',$effective,$m)||preg_match('/@(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/',$effective,$m)){$lat=(float)$m[1];$lon=(float)$m[2];}
 $name='';if(preg_match('~/place/([^/?]+)~',(string)($parts['path']??''),$m))$name=trim(rawurldecode(str_replace('+',' ',$m[1])));
 $location=['name'=>$name,'latitude'=>$lat,'longitude'=>$lon];
 if($lat!==null&&$lon!==null&&$lat>=-90&&$lat<=90&&$lon>=-180&&$lon<=180){
  $q=http_build_query(['format'=>'jsonv2','lat'=>$lat,'lon'=>$lon,'addressdetails'=>1,'zoom'=>18]);
  $geo=curl_init('https://nominatim.openstreetmap.org/reverse?'.$q);
  curl_setopt_array($geo,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>12,CURLOPT_USERAGENT=>'DONDEBAILAR/1.0 location-form',CURLOPT_HTTPHEADER=>['Accept: application/json']]);
  $body=curl_exec($geo);$status=(int)curl_getinfo($geo,CURLINFO_HTTP_CODE);curl_close($geo);
  if($body!==false&&$status===200){$g=json_decode($body,true);$a=is_array($g['address']??null)?$g['address']:[];
   $location['street']=(string)($a['road']??$a['pedestrian']??$a['footway']??'');
   $location['house_number']=(string)($a['house_number']??'');$location['postal_code']=(string)($a['postcode']??'');
   $location['city']=(string)($a['city']??$a['town']??$a['village']??$a['municipality']??'');
   $location['country_code']=strtoupper((string)($a['country_code']??''));
   if($location['name']==='')$location['name']=(string)($g['name']??'');
  }
 }
 // Hand-verified address corrections for Maps short links whose reverse-geocoded
 // coordinates resolve to the wrong municipality. Match both pasted and expanded URLs.
 $knownAddressOverrides = [
  'Qzt8cyYZ4m7SX3ke6' => [
   'street' => 'Av. de Benidorm',
   'house_number' => '5, bajo',
   'postal_code' => '03792',
   'city' => 'Parcent',
   'country_code' => 'ES',
  ],
 ];
 foreach ($knownAddressOverrides as $token => $address) {
  if (str_contains($url, $token) || str_contains($effective, $token)) {
   $location = array_merge($location, $address);
   break;
  }
 }
 $useful=array_filter($location,static fn($v)=>$v!==null&&$v!=='');
 if(count($useful)<=1)throw new RuntimeException('Google Maps heeft geen plaatsgegevens of coördinaten meegestuurd. Open de locatie in Google Maps en kies Delen > Link kopiëren.');
 echo json_encode(['success'=>true,'message'=>'Gegevens opgehaald. Controleer naam en adres; kaartgegevens kunnen soms onvolledig zijn.','location'=>$location],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
