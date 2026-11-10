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

    // Haal het telefoonnummer uit meerdere bekende Google Maps-formaten.
    // Google wisselt regelmatig tussen JSON, data-attributen en tel-links.
    $mapsPhone = '';
    $mapsPage = $httpGet($finalUrl);
    $mapsPhoneDebug = [
        'http_status' => (int) $mapsPage['status'],
        'page_bytes' => strlen((string) $mapsPage['body']),
    ];
    if ($mapsPage['status'] >= 200 && $mapsPage['status'] < 300 && $mapsPage['body'] !== '') {
        $mapsHtml = html_entity_decode((string) $mapsPage['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $phonePatterns = [
            '~"phoneNumber"\s*:\s*"((?:\\\\.|[^"\\\\])*)"~i',
            '~"telephone"\s*:\s*"((?:\\\\.|[^"\\\\])*)"~i',
            '~"phone"\s*:\s*"((?:\\\\.|[^"\\\\])*)"~i',
            '~"phoneNumber"\s*,\s*"([^"]+)"~i',
            '~"telephone"\s*,\s*"([^"]+)"~i',
            '~data-item-id=["\']phone:tel:([^"\']+)["\']~i',
            '~href=["\']tel:([^"\']+)["\']~i',
            '~aria-label=["\'][^"\']*(?:telefoon|phone|tel[eé]fono)[^"\']*?(\+?[0-9][0-9().\s\/-]{6,})["\']~iu',
            '~(?:Telefoonnummer|Phone number|Telephone|Tel[eé]fono)\s*[:：]\s*(\+?[0-9][0-9().\s\/-]{6,})~iu',
        ];
        foreach ($phonePatterns as $phonePattern) {
            if (!preg_match_all($phonePattern, $mapsHtml, $phoneMatches) || empty($phoneMatches[1])) continue;
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
    $mapsPhoneDebug['found'] = $mapsPhone !== '';

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
