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
    // Volg omleidingen zelf, zodat elke tussenliggende host opnieuw op veiligheid
    // wordt gecontroleerd. Veel websites sturen www/non-www en HTTP door.
    for ($redirect = 0; $redirect <= 5; $redirect++) {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Ongeldige website.');
        }

        $ips = gethostbynamel($host);
        if (!$ips) {
            $ipv6 = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
            if ($ipv6 !== false) $ips = [$ipv6];
        }
        if (!$ips) throw new RuntimeException('Website niet bereikbaar (DNS).');
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Ongeldig website-adres.');
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 18,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; DONDEBAILAR/1.0; +https://dondebailar.eu)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: es-ES,es;q=0.9,en;q=0.8,nl;q=0.7'],
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => $limit,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        $location = '';
        if (is_string($body) && $headerSize > 0) {
            $headers = substr($body, 0, $headerSize);
            if (preg_match('~^Location:\s*(.+?)\s*$~im', $headers, $match)) $location = trim($match[1]);
            $body = substr($body, $headerSize);
        }
        curl_close($ch);

        if ($status >= 300 && $status < 400) {
            $target = $location !== '' ? $location : $redirectUrl;
            if ($target === '') throw new RuntimeException('Website stuurde een ongeldige omleiding.');
            if (str_starts_with($target, '//')) {
                $target = $scheme . ':' . $target;
            } elseif (!preg_match('~^https?://~i', $target)) {
                $base = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
                if (str_starts_with($target, '/')) {
                    $target = $base . $target;
                } else {
                    $path = (string) ($parts['path'] ?? '/');
                    $target = $base . substr($path, 0, (int) strrpos($path, '/') + 1) . $target;
                }
            }
            $url = $target;
            continue;
        }

        if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > $limit) {
            $reason = $curlError !== '' ? ' (' . $curlError . ')' : ' (HTTP ' . $status . ')';
            throw new RuntimeException('Website kon niet worden gelezen' . $reason . '.');
        }
        return $body;
    }
    throw new RuntimeException('Website stuurde te veel omleidingen.');
}

try {
    $countryNames = ['ES' => 'Spain', 'BE' => 'Belgium', 'NL' => 'Netherlands', 'FR' => 'France', 'DE' => 'Germany', 'PT' => 'Portugal', 'IT' => 'Italy'];
    $countryName = $countryNames[$countryCode] ?? $countryCode;
    // Zoek eerst een eigen website en daarna sociale pagina's.
    $cityNeedle = mb_strtolower($city);
    $findResult = static function (string $searchHtml, string $socialOnly = '') use ($cityNeedle): string {
        if (!preg_match_all('~<a\b[^>]*>.*?</a>~is', $searchHtml, $anchors, PREG_OFFSET_CAPTURE)) return '';
        $seen = [];
        foreach ($anchors[0] as [$anchor, $offset]) {
            if (!preg_match('~<h3\b~i', $anchor)
                || !preg_match('~href=["\']([^"\']+)["\']~i', $anchor, $hrefMatch)) continue;
            $context = substr($searchHtml, max(0, $offset - 1400), 3600);
            $resultText = mb_strtolower(html_entity_decode(strip_tags($context), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($cityNeedle !== '' && !str_contains($resultText, $cityNeedle)) continue;
            $candidate = html_entity_decode($hrefMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($candidate, '//')) $candidate = 'https:' . $candidate;
            $parts = parse_url($candidate);
            if (isset($parts['query'])) {
                parse_str($parts['query'], $params);
                if (!empty($params['q']) && preg_match('~^https?://~i', (string) $params['q'])) $candidate = (string) $params['q'];
                elseif (!empty($params['url']) && preg_match('~^https?://~i', (string) $params['url'])) $candidate = (string) $params['url'];
            }
            $candidateParts = parse_url($candidate);
            $host = strtolower((string) ($candidateParts['host'] ?? ''));
            $isFacebook = preg_match('/(^|\.)facebook\.com$/i', $host) === 1;
            $isInstagram = preg_match('/(^|\.)instagram\.com$/i', $host) === 1;
            if (!in_array(strtolower((string) ($candidateParts['scheme'] ?? '')), ['http', 'https'], true) || $host === '') continue;
            if (preg_match('/(^|\.)(google\.[a-z.]+|gstatic\.com|googleusercontent\.com|youtube\.com|tripadvisor\.[a-z.]+)$/i', $host)) continue;
            if ($socialOnly === 'facebook' && !$isFacebook) continue;
            if ($socialOnly === 'instagram' && !$isInstagram) continue;
            if ($socialOnly === '' && ($isFacebook || $isInstagram)) continue;
            if (isset($seen[$candidate])) continue;
            $seen[$candidate] = true;
            return $candidate;
        }
        return '';
    };

    $searchTerms = '"' . $name . '" "' . $city . '" "' . $countryName . '" official website';
    // Eerst een eigen website, daarna Facebook en Instagram als alternatief.
    $html = locationSearchFetch('https://www.google.com/search?hl=es&num=10&q=' . rawurlencode($searchTerms));
    $website = $findResult($html);
    $socialType = '';
    if ($website === '') {
        $facebookTerms = 'site:facebook.com "' . $name . '" "' . $city . '" "' . $countryName . '"';
        $facebookHtml = locationSearchFetch('https://www.google.com/search?hl=es&num=10&q=' . rawurlencode($facebookTerms));
        $website = $findResult($facebookHtml, 'facebook');
        if ($website !== '') $socialType = 'Facebook';
    }
    if ($website === '') {
        $instagramTerms = 'site:instagram.com "' . $name . '" "' . $city . '" "' . $countryName . '"';
        $instagramHtml = locationSearchFetch('https://www.google.com/search?hl=es&num=10&q=' . rawurlencode($instagramTerms));
        $website = $findResult($instagramHtml, 'instagram');
        if ($website !== '') $socialType = 'Instagram';
    }
    if ($website === '') {
        throw new RuntimeException('Geen betrouwbare website, Facebook- of Instagrampagina gevonden voor ' . $name . ' in ' . $city . ', ' . $countryName . '. Controleer de gegevens of vul de link handmatig in.');
    }
    $siteHtml = '';
    try { $siteHtml = locationSearchFetch($website); } catch (Throwable $ignored) {}
    // Haal het telefoonnummer op uit gestructureerde gegevens of een tel:-link.
    $phone = '';
    if ($siteHtml !== '') {
        if (preg_match('~"telephone"\s*:\s*"([^"]+)"~i', $siteHtml, $phoneMatch)) {
            $phone = trim(html_entity_decode($phoneMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($phone === '' && preg_match('~href=["\']tel:([^"\']+)["\']~i', $siteHtml, $phoneMatch)) {
            $phone = trim(rawurldecode(html_entity_decode($phoneMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        if ($phone === '' && preg_match('~(?:telephone|phone|tel)\s*["\']?\s*[:=]\s*["\']?(\+?[0-9][0-9\s()./-]{6,25})~i', $siteHtml, $phoneMatch)) {
            $phone = trim($phoneMatch[1]);
        }
        if (mb_strlen($phone) > 50 || !preg_match('/^[+0-9][0-9\s().\/-]{5,49}$/', $phone)) $phone = '';
    }
    // Als de website zelf geen telefoonnummer toont, zoek gericht in Google-resultaten.
    if ($phone === '') {
        $phoneTerms = '"' . $name . '" "' . $city . '" "' . $countryName . '" (telefono OR teléfono OR phone OR contacto)';
        try {
            $phoneHtml = locationSearchFetch('https://www.google.com/search?hl=es&num=10&q=' . rawurlencode($phoneTerms));
            $phoneText = html_entity_decode(strip_tags($phoneHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Google-result snippets can show the phone before its label,
            // or show a Spanish 9-digit number without any label.
            $phonePatterns = [
                '~(?:tel(?:éfono|efono)?|phone|contacto|call|llamar)\\D{0,45}(\\+?[0-9][0-9\\s()./-]{6,24}[0-9])~iu',
                '~(\\+?[0-9][0-9\\s()./-]{6,24}[0-9])\\D{0,45}(?:tel(?:éfono|efono)?|phone|contacto|call|llamar)~iu',
                '~(?:\\+34[\\s.-]?)?[6789](?:[\\s.-]?[0-9]){8}~u',
            ];
            foreach ($phonePatterns as $phonePattern) {
                if (!preg_match_all($phonePattern, $phoneText, $phoneMatches)) continue;
                $candidates = $phoneMatches[1] ?? $phoneMatches[0];
                foreach ($candidates as $rawPhone) {
                    $candidatePhone = trim((string) $rawPhone);
                    $digits = preg_replace('/\\D+/', '', $candidatePhone);
                    if (strlen((string) $digits) >= 9 && strlen((string) $digits) <= 15) {
                        $phone = $candidatePhone;
                        break 2;
                    }
                }
            }
        } catch (Throwable $ignored) {
            // Telefoon zoeken mag de website- en logovondst niet blokkeren.
        }
    }
    // Verzamel logo-kandidaten in volgorde van betrouwbaarheid.
    $logoCandidates = [];
    if ($siteHtml !== '') {
        // Gestructureerde gegevens (Schema.org / JSON-LD).
        if (preg_match_all('~"logo"\\s*:\\s*(?:"([^"]+)"|\\{[^}]*"url"\\s*:\\s*"([^"]+)"[^}]*\\})~i', $siteHtml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) $logoCandidates[] = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
        }
        // Afbeeldingen waarvan class of id expliciet "logo" vermeldt.
        if (preg_match_all('~<img\\b[^>]*>~i', $siteHtml, $images)) {
            foreach ($images[0] as $imageTag) {
                if (!preg_match('~(?:class|id)=["\'][^"\']*logo[^"\']*["\']~i', $imageTag)) continue;
                if (preg_match('~\\bsrc=["\']([^"\']+)["\']~i', $imageTag, $src)) $logoCandidates[] = $src[1];
                elseif (preg_match('~\\bdata-src=["\']([^"\']+)["\']~i', $imageTag, $src)) $logoCandidates[] = $src[1];
            }
        }
        // Open Graph / Twitter-afbeelding als bruikbare terugval.
        if (preg_match_all('~<meta\\b[^>]*(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]*>~i', $siteHtml, $metas)) {
            foreach ($metas[0] as $meta) {
                if (preg_match('~content=["\']([^"\']+)["\']~i', $meta, $src)) $logoCandidates[] = $src[1];
            }
        }
        // Apple touch icon en gewone favicon als laatste website-opties.
        if (preg_match_all('~<link\\b[^>]*rel=["\'][^"\']*(?:apple-touch-icon|icon)[^"\']*["\'][^>]*>~i', $siteHtml, $links)) {
            foreach ($links[0] as $link) {
                if (preg_match('~href=["\']([^"\']+)["\']~i', $link, $href)) $logoCandidates[] = $href[1];
            }
        }
    }
    $base = parse_url($website);
    $origin = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '');
    $logo = '';
    foreach ($logoCandidates as $candidate) {
        $candidate = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($candidate === '' || str_starts_with($candidate, 'data:')) continue;
        if (str_starts_with($candidate, '//')) $candidate = 'https:' . $candidate;
        elseif (!preg_match('~^https?://~i', $candidate)) {
            $candidate = str_starts_with($candidate, '/') ? $origin . $candidate : $origin . '/' . $candidate;
        }
        $candidateParts = parse_url($candidate);
        if (in_array(strtolower((string) ($candidateParts['scheme'] ?? '')), ['http', 'https'], true)
            && !empty($candidateParts['host'])) {
            $logo = $candidate;
            break;
        }
    }
    // Zelfs als de website geen logo-tag heeft, probeer het standaard site-icoon.
    if ($logo === '') $logo = $origin . '/favicon.ico';
    echo json_encode([
        'success' => true,
        'message' => $socialType !== '' ? 'Geen eigen website gevonden. Mogelijke ' . $socialType . '-pagina gevonden; controleer of dit de juiste locatie is.' : 'Website gevonden. Controleer of dit de officiële website is en bekijk het logo.',
        'website_url' => $website,
        'phone' => $phone,
        'logo_url' => $logo,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
