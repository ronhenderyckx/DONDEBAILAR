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
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Je hebt geen rechten om locaties te beheren.']);
    exit;
}
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input) || !verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Beveiligingscontrole mislukt. Vernieuw de pagina.']);
    exit;
}
$name = trim((string) ($input['name'] ?? ''));
$city = trim((string) ($input['city'] ?? ''));
$countryCode = strtoupper(trim((string) ($input['country_code'] ?? '')));
if ($name === '' || mb_strlen($name) > 190 || mb_strlen($city) > 120 || !preg_match('/\\A[A-Z]{2}\\z/', $countryCode)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Vul eerst de naam van de locatie in en eventueel de gemeente.']);
    exit;
}
if (!function_exists('curl_init')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Zoeken is niet beschikbaar: cURL ontbreekt op de server.']);
    exit;
}

function locationSearchFetch(string $url, int $limit = 1500000): string
{
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Ongeldige website.');
    }
    $ips = gethostbynamel($host);
    if (!$ips) throw new RuntimeException('Website niet bereikbaar.');
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Ongeldig website-adres.');
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'DONDEBAILAR/1.0 (+https://dondebailar.eu)',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => $limit,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > $limit) {
        throw new RuntimeException('Website kon niet worden gelezen.');
    }
    return $body;
}

try {
    $countryNames = ['ES' => 'Spain', 'BE' => 'Belgium', 'NL' => 'Netherlands', 'FR' => 'France', 'DE' => 'Germany', 'PT' => 'Portugal', 'IT' => 'Italy'];
    $countryName = $countryNames[$countryCode] ?? $countryCode;
    // Search explicitly with municipality AND country; never silently accept the first unrelated result.
    $searchTerms = '"' . $name . '" "' . $city . '" "' . $countryName . '" official website';
    $query = rawurlencode($searchTerms);
    $html = locationSearchFetch('https://html.duckduckgo.com/html/?q=' . $query);
    $website = '';
    $cityNeedle = mb_strtolower($city);
    if (preg_match_all('~<a\\b[^>]*>.*?</a>~is', $html, $anchors, PREG_OFFSET_CAPTURE)) {
        foreach ($anchors[0] as [$anchor, $offset]) {
            if (!preg_match('~class=["\\'][^"\\']*result__a[^"\\']*["\\']~i', $anchor)
                || !preg_match('~href=["\\']([^"\\']+)["\\']~i', $anchor, $hrefMatch)) continue;
            $context = substr($html, max(0, $offset - 900), 2600);
            $resultText = mb_strtolower(html_entity_decode(strip_tags($context), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            // Require the municipality to appear near this result, not merely elsewhere on the search page.
            if (!str_contains($resultText, $cityNeedle)) continue;
            $candidate = html_entity_decode($hrefMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($candidate, '//')) $candidate = 'https:' . $candidate;
            $parts = parse_url($candidate);
            if (isset($parts['query'])) {
                parse_str($parts['query'], $params);
                if (!empty($params['uddg'])) $candidate = (string) $params['uddg'];
            }
            $candidateParts = parse_url($candidate);
            $host = strtolower((string) ($candidateParts['host'] ?? ''));
            if (in_array(strtolower((string) ($candidateParts['scheme'] ?? '')), ['http', 'https'], true)
                && $host !== '' && !preg_match('/(^|\\.)(duckduckgo\\.com|facebook\\.com|instagram\\.com|tripadvisor\\.[a-z.]+)$/i', $host)) {
                $website = $candidate;
                break;
            }
        }
    }
    if ($website === '') {
        throw new RuntimeException('Geen betrouwbare website gevonden voor deze locatienaam in gemeente ' . $city . ', ' . $countryName . '. Controleer de gemeente of vul de website handmatig in.');
    }
    $siteHtml = '';
    try { $siteHtml = locationSearchFetch($website); } catch (Throwable $ignored) {}
    $logo = '';
    if ($siteHtml !== '') {
        $jsonLogoPattern = '~"logo"\s*:\s*"([^"]+)"~i';
        if (preg_match($jsonLogoPattern, $siteHtml, $m)) {
            $logo = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('~<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]+content=["\']([^"\']+)~i', $siteHtml, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\']~i', $siteHtml, $m)) {
            $logo = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } elseif (preg_match('~<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]+href=["\']([^"\']+)~i', $siteHtml, $m)) {
            $logo = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    if (str_starts_with($logo, '//')) {
        $logo = 'https:' . $logo;
    } elseif ($logo !== '' && !preg_match('~^https?://~i', $logo)) {
        $base = parse_url($website);
        $origin = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '');
        $logo = str_starts_with($logo, '/') ? $origin . $logo : $origin . '/' . $logo;
    }
    echo json_encode([
        'success' => true,
        'message' => 'Website gevonden. Controleer of dit de officiële website is en bekijk het logo.',
        'website_url' => $website,
        'logo_url' => $logo,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
