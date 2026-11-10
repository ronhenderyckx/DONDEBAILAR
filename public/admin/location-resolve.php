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

$url = trim((string) ($input['url'] ?? ''));
if (!isGoogleMapsUrl($url)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Plak een geldige Google Maps-deellink.']);
    exit;
}

$userAgent = 'DONDEBAILAR/1.0 (Google Maps location lookup)';

try {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Automatisch ophalen is niet beschikbaar: cURL ontbreekt op de server.');
    }

    /*
     * Zelfde werkwijze als de PHP-code die op de andere website werkte:
     * redirects niet automatisch volgen, maar iedere Google Maps-redirect
     * afzonderlijk controleren en verwerken.
     */
    $httpGet = static function (string $requestUrl) use ($userAgent): array {
        $location = '';
        $ch = curl_init($requestUrl);
        if ($ch === false) {
            return ['status' => 0, 'location' => '', 'body' => ''];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept-Language: nl'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'location' => '', 'body' => ''];
        }

        return ['status' => $status, 'location' => $location, 'body' => (string) $body];
    };

    $hostKind = static function (string $candidate): string {
        $parts = parse_url($candidate);
        if (!is_array($parts) || empty($parts['host'])
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '');

        if ($host === 'maps.app.goo.gl' || $host === 'goo.gl') {
            return 'short';
        }
        if (preg_match('/^consent\.google\.[a-z.]{2,}$/', $host)) {
            return 'consent';
        }
        if (preg_match('/^maps\.google\.[a-z.]{2,}$/', $host)) {
            return 'maps';
        }
        if (preg_match('/^(www\.)?google\.[a-z.]{2,}$/', $host) && str_starts_with($path, '/maps')) {
            return 'maps';
        }
        return '';
    };

    // Resolve short links and consent.google redirects, validating every hop.
    $finalUrl = $url;
    $resolved = false;
    for ($hop = 0; $hop < 6; $hop++) {
        $kind = $hostKind($finalUrl);
        if ($kind === '') {
            throw new RuntimeException('De Google Maps-link bevat een onbekende doorverwijzing.');
        }
        if ($kind === 'maps') {
            $resolved = true;
            break;
        }
        if ($kind === 'consent') {
            $query = [];
            parse_str((string) parse_url($finalUrl, PHP_URL_QUERY), $query);
            $next = (string) ($query['continue'] ?? '');
            if ($next === '') {
                throw new RuntimeException('Google Maps stuurde geen bruikbare doorverwijzing.');
            }
            $finalUrl = $next;
            continue;
        }

        $response = $httpGet($finalUrl);
        if ($response['location'] === '' || !preg_match('#^https?://#i', $response['location'])) {
            throw new RuntimeException('De verkorte Google Maps-link gaf geen bruikbare doorverwijzing.');
        }
        $finalUrl = $response['location'];
    }

    if (!$resolved) {
        throw new RuntimeException('Google Maps-link bevat te veel doorverwijzingen.');
    }

    // Download the resolved Maps page once. Shared links sometimes contain only a CID,
    // so the place name/address must also be read from the page metadata and embedded JSON.
    $mapsPage = null;
    for ($pageHop = 0; $pageHop < 6; $pageHop++) {
        if ($hostKind($finalUrl) === '') {
            throw new RuntimeException('Google Maps stuurde door naar een niet-toegestane website.');
        }

        $mapsPage = $httpGet($finalUrl);
        $pageStatus = (int) $mapsPage['status'];
        $redirectLocation = trim((string) $mapsPage['location']);

        if (!in_array($pageStatus, [301, 302, 303, 307, 308], true) || $redirectLocation === '') {
            break;
        }

        // Google can return absolute, protocol-relative or root-relative redirects.
        if (str_starts_with($redirectLocation, '//')) {
            $scheme = (string) (parse_url($finalUrl, PHP_URL_SCHEME) ?: 'https');
            $redirectLocation = $scheme . ':' . $redirectLocation;
        } elseif (str_starts_with($redirectLocation, '/')) {
            $parts = parse_url($finalUrl);
            $origin = (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? '');
            if (isset($parts['port'])) $origin .= ':' . (int) $parts['port'];
            $redirectLocation = $origin . $redirectLocation;
        } elseif (!preg_match('#^https?://#i', $redirectLocation)) {
            throw new RuntimeException('Google Maps gaf een ongeldige doorverwijzing.');
        }

        if ($hostKind($redirectLocation) === '') {
            throw new RuntimeException('Google Maps stuurde door naar een niet-toegestane website.');
        }
        $finalUrl = $redirectLocation;
    }

    if (!is_array($mapsPage)) {
        throw new RuntimeException('De Google Maps-pagina kon niet worden opgehaald.');
    }
    $mapsHtml = '';
    $mapsPhone = '';
    $mapsPhoneDebug = [
        'http_status' => (int) $mapsPage['status'],
        'page_bytes' => strlen((string) $mapsPage['body']),
    ];
    if ($mapsPage['status'] >= 200 && $mapsPage['status'] < 300 && $mapsPage['body'] !== '') {
        $mapsHtml = html_entity_decode((string) $mapsPage['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Google Maps kan delen van zijn ingesloten gegevens URL- of JSON-escaped leveren.
        $mapsSearchText = str_replace(
            ['\\u003a', '\\u002b', '\\u002d', '\\u002f', '\\u003d', '&#43;'],
            [':', '+', '-', '/', '=', '+'],
            rawurldecode($mapsHtml)
        );

        // Zoek zowel de bekende Maps-velden als zichtbare telefoonlabels en tel-links.
        if ($mapsPhone === '') {
            $phonePatterns = [
                '~"(?:phoneNumber|telephone|phone)"\s*:\s*"([^"]{7,60})"~i',
                '~(?:phoneNumber|telephone|phone)\\?"?\s*[,=:]\s*\\?"([^"\\]{7,60})~i',
                '~"phoneNumber"\s*,\s*"([^"]{7,60})"~i',
                '~data-item-id=["\x27]phone:tel:([^"\x27]+)["\x27]~i',
                '~(?:href|data-href)=["\x27]tel:([^"\x27]+)["\x27]~i',
                '~(?:aria-label|title)=["\x27][^"\x27]*(?:Telefoonnummer|Phone number|Telephone|Tel[eé]fono)\s*[:：]?\s*(\+?[0-9][0-9().\s\/-]{6,})[^"\x27]*["\x27]~iu',
                '~(?:Telefoonnummer|Phone number|Telephone|Tel[eé]fono)\s*[:：]\s*(\+?[0-9][0-9().\s\/-]{6,})~iu',
            ];
            foreach ($phonePatterns as $phonePattern) {
                if (!preg_match_all($phonePattern, $mapsSearchText, $phoneMatches) || empty($phoneMatches[1])) continue;
                foreach ($phoneMatches[1] as $rawCandidate) {
                    $candidatePhone = trim(rawurldecode(stripcslashes((string) $rawCandidate)));
                    $candidatePhone = preg_replace('/[^\d+().\/\s-]/u', '', $candidatePhone) ?? '';
                    $digits = preg_replace('/\D+/', '', $candidatePhone);
                    if (strlen((string) $digits) >= 7 && strlen((string) $digits) <= 15) {
                        $mapsPhone = trim($candidatePhone);
                        break 2;
                    }
                }
            }
        }
    }
    $mapsPhoneDebug['found'] = $mapsPhone !== '';

    // Extract the exact place pin first; only use the map viewport as fallback.
    $pair = '(-?\d{1,3}(?:\.\d+)?)';
    $coords = null;
    $match = [];
    if (preg_match('/!3d' . $pair . '!4d' . $pair . '/', $finalUrl, $match)) {
        // Exact selected place coordinates, as in the supplied working implementation.
        $coords = [(float) $match[1], (float) $match[2]];
    } elseif (preg_match('/@' . $pair . ',' . $pair . '/', $finalUrl, $match)) {
        // Use the @ coordinates as fallback, matching the supplied implementation.
        $coords = [(float) $match[1], (float) $match[2]];
    }

    $parts = parse_url($finalUrl);
    $path = (string) ($parts['path'] ?? '');
    $query = [];
    parse_str((string) ($parts['query'] ?? ''), $query);

    $isCoordinate = static fn(string $value): bool =>
        (bool) preg_match('/^\s*' . $pair . '\s*,\s*' . $pair . '\s*$/', $value);

    if ($coords === null) {
        foreach (['q', 'll', 'query', 'destination'] as $key) {
            $value = $query[$key] ?? null;
            if (is_string($value) && preg_match('/^\s*' . $pair . '\s*,\s*' . $pair . '\s*$/', $value, $match)) {
                $coords = [(float) $match[1], (float) $match[2]];
                break;
            }
        }
    }

    if ($coords !== null && (abs($coords[0]) > 90 || abs($coords[1]) > 180)) $coords = null;

    $placeText = '';
    if (preg_match('#/maps/(?:place|search)/([^/@]+)#', $path, $match)) {
        $placeText = rawurldecode(str_replace('+', ' ', $match[1]));
    } else {
        foreach (['q', 'query'] as $key) {
            if (isset($query[$key]) && is_string($query[$key])) {
                $placeText = $query[$key];
                break;
            }
        }
    }
    $placeText = trim($placeText);
    if ($placeText !== '' && $isCoordinate($placeText)) {
        $placeText = '';
    }
    $name = trim(explode(',', $placeText)[0]);

    $address = [
        'street' => '',
        'house_number' => '',
        'postal_code' => '',
        'city' => '',
        'country_code' => '',
    ];

    // If Maps gives an address/place but no exact pin coordinates, geocode that place text.
    // Do not reverse-geocode the @ viewport centre: it can belong to another municipality.
    if ($coords === null && $placeText !== '') {
        $params = [
            'format' => 'jsonv2', 'addressdetails' => 1, 'extratags' => 1,
            'accept-language' => 'nl', 'q' => $placeText, 'limit' => 1,
        ];
        $geoUrl = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
        $geo = curl_init($geoUrl);
        curl_setopt_array($geo, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: nl'],
        ]);
        $geoBody = curl_exec($geo);
        $geoStatus = (int) curl_getinfo($geo, CURLINFO_RESPONSE_CODE);
        curl_close($geo);
        if ($geoBody !== false && $geoStatus === 200) {
            $rows = json_decode((string) $geoBody, true);
            if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
                $row = $rows[0];
                if (isset($row['lat'], $row['lon'])) $coords = [(float) $row['lat'], (float) $row['lon']];
                $a = is_array($row['address'] ?? null) ? $row['address'] : [];
                $pick = static function (array $source, array $keys): string {
                    foreach ($keys as $key) if (!empty($source[$key]) && is_string($source[$key])) return $source[$key];
                    return '';
                };
                $address = [
                    'street' => $pick($a, ['road', 'pedestrian', 'footway', 'path', 'square', 'residential']),
                    'house_number' => $pick($a, ['house_number']),
                    'postal_code' => $pick($a, ['postcode']),
                    'city' => $pick($a, ['city', 'town', 'village', 'municipality', 'hamlet', 'suburb']),
                    'country_code' => strtoupper($pick($a, ['country_code'])),
                ];
            }
        }
    }

    // Same Nominatim reverse lookup and address-field priority as the supplied working code.
    if ($coords !== null && ($address['street'] === '' || $address['city'] === '')) {
        $params = [
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'extratags' => 1,
            'accept-language' => 'nl',
            'lat' => $coords[0],
            'lon' => $coords[1],
            'zoom' => 18,
        ];
        $geoUrl = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query($params);
        $geo = curl_init($geoUrl);
        curl_setopt_array($geo, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: nl'],
        ]);
        $geoBody = curl_exec($geo);
        $geoStatus = (int) curl_getinfo($geo, CURLINFO_RESPONSE_CODE);
        curl_close($geo);

        if ($geoBody !== false && $geoStatus === 200) {
            $geoData = json_decode((string) $geoBody, true);
            $a = is_array($geoData['address'] ?? null) ? $geoData['address'] : [];
            $pick = static function (array $source, array $keys): string {
                foreach ($keys as $key) {
                    if (!empty($source[$key]) && is_string($source[$key])) {
                        return $source[$key];
                    }
                }
                return '';
            };
            $address = [
                'street' => $pick($a, ['road', 'pedestrian', 'footway', 'path', 'square', 'residential']),
                'house_number' => $pick($a, ['house_number']),
                'postal_code' => $pick($a, ['postcode']),
                'city' => $pick($a, ['city', 'town', 'village', 'municipality', 'hamlet', 'suburb']),
                'country_code' => strtoupper($pick($a, ['country_code'])),
            ];
            if ($name === '' && !empty($geoData['name'])) {
                $name = (string) $geoData['name'];
            }
        }
    } elseif ($placeText !== '') {
        // If Maps supplies a place name but no coordinates, search using that text.
        $params = [
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'extratags' => 1,
            'accept-language' => 'nl',
            'q' => $placeText,
            'limit' => 1,
        ];
        $geoUrl = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
        $geo = curl_init($geoUrl);
        curl_setopt_array($geo, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: nl'],
        ]);
        $geoBody = curl_exec($geo);
        $geoStatus = (int) curl_getinfo($geo, CURLINFO_RESPONSE_CODE);
        curl_close($geo);
        if ($geoBody !== false && $geoStatus === 200) {
            $rows = json_decode((string) $geoBody, true);
            $a = is_array($rows[0]['address'] ?? null) ? $rows[0]['address'] : [];
            $pick = static function (array $source, array $keys): string {
                foreach ($keys as $key) {
                    if (!empty($source[$key]) && is_string($source[$key])) {
                        return $source[$key];
                    }
                }
                return '';
            };
            $address = [
                'street' => $pick($a, ['road', 'pedestrian', 'footway', 'path', 'square', 'residential']),
                'house_number' => $pick($a, ['house_number']),
                'postal_code' => $pick($a, ['postcode']),
                'city' => $pick($a, ['city', 'town', 'village', 'municipality', 'hamlet', 'suburb']),
                'country_code' => strtoupper($pick($a, ['country_code'])),
            ];
        }
    }

    // Prefer the explicit place title, not the generic Google Maps page title.
    $metaPatterns = [
        '~<meta[^>]+(?:property|name)=["\x27](?:og:title|twitter:title)["\x27][^>]+content=["\x27]([^"\x27]+)["\x27]~i',
        '~<meta[^>]+content=["\x27]([^"\x27]+)["\x27][^>]+(?:property|name)=["\x27](?:og:title|twitter:title)["\x27]~i',
        '~<title[^>]*>(.*?)</title>~is',
    ];
    foreach ($metaPatterns as $metaPattern) {
        if (preg_match($metaPattern, $mapsHtml, $metaMatch)) {
        $candidate = trim(html_entity_decode(strip_tags((string) $metaMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $candidate = preg_replace('/\\s*[-–|]\\s*Google Maps\\s*$/iu', '', $candidate) ?? $candidate;
        if ($candidate !== '' && stripos($candidate, 'Google Maps') === false) {
            $name = $candidate;
            break;
        }
        }
    }

    // Structured data, when Google includes it, can contain the complete postal address and phone.
    if (preg_match_all('~<script[^>]*type=["\x27]application/ld\\+json["\x27][^>]*>(.*?)</script>~is', $mapsHtml, $jsonBlocks)) {
        foreach ($jsonBlocks[1] as $jsonBlock) {
        $structured = json_decode(html_entity_decode((string) $jsonBlock, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (!is_array($structured)) continue;
        $items = isset($structured['@graph']) && is_array($structured['@graph']) ? $structured['@graph'] : [$structured];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if ($name === '' && !empty($item['name']) && is_string($item['name'])) $name = trim($item['name']);
            if ($mapsPhone === '' && !empty($item['telephone']) && is_string($item['telephone'])) $mapsPhone = trim($item['telephone']);
            $structuredAddress = $item['address'] ?? null;
            if (is_array($structuredAddress)) {
            $address['street'] = trim((string) ($structuredAddress['streetAddress'] ?? $address['street']));
            $address['postal_code'] = trim((string) ($structuredAddress['postalCode'] ?? $address['postal_code']));
            $address['city'] = trim((string) ($structuredAddress['addressLocality'] ?? $address['city']));
            $country = $structuredAddress['addressCountry'] ?? '';
            if (is_array($country)) $country = $country['name'] ?? '';
            if (is_string($country) && preg_match('/^[A-Z]{2}$/i', $country)) $address['country_code'] = strtoupper($country);
            }
        }
        }
    }


    // Google Maps page metadata can supplement the geocoded address.



    // If the Maps place text is just the street address, do not use it as the venue name.
    if ($name !== '' && $address['street'] !== '' && stripos($name, $address['street']) !== false) {
        $name = '';
    }

    $location = array_merge([
        'name' => $name,
        'street' => '',
        'house_number' => '',
        'postal_code' => '',
        'city' => '',
        'country_code' => '',
        'phone' => $mapsPhone,
        'latitude' => $coords[0] ?? null,
        'longitude' => $coords[1] ?? null,
    ], $address);

    $hasData = false;
    foreach ($location as $value) {
        if ($value !== null && $value !== '') {
            $hasData = true;
            break;
        }
    }
    if (!$hasData) {
        throw new RuntimeException('Geen gegevens gevonden. Controleer of de Google Maps-link openbaar gedeeld is.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Gegevens opgehaald met de Google Maps-methode. Controleer het adres.',
        'location' => $location,
        'phone_debug' => $mapsPhoneDebug,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
