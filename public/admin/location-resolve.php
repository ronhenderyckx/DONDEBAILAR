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
if(!function_exists('curl_init')){http_response_code(503);echo json_encode(['success'=>false,'message'=>'Automatisch ophalen is niet beschikbaar: cURL ontbreekt op de server.']);exit;}
try {
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>6,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_USERAGENT=>'DONDEBAILAR location resolver/1.0',CURLOPT_RANGE=>'0-2048',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS]);
 curl_exec($ch);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);
 if($effective==='')throw new RuntimeException('Google Maps-link kon niet worden geopend.');
 $parts=parse_url($effective);$host=strtolower((string)($parts['host']??''));
 if(!in_array($host,['google.com','www.google.com','maps.google.com'],true))throw new RuntimeException('De link verwijst niet naar een herkenbare Google Maps-pagina.');
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
 $useful=array_filter($location,static fn($v)=>$v!==null&&$v!=='');
 if(count($useful)<=1)throw new RuntimeException('Deze Maps-link bevat geen bruikbare coördinaten. Kopieer opnieuw de deellink vanuit Google Maps.');
 echo json_encode(['success'=>true,'message'=>'Gegevens opgehaald. Controleer naam en adres; kaartgegevens kunnen soms onvolledig zijn.','location'=>$location],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
