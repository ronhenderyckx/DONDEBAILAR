<?php
declare(strict_types=1);

/** Download and validate a logo selected from a discovered website. */
function storeDiscoveredLocationLogo(string $url): ?string
{
    $url = trim($url);
    if ($url === '') return null;
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        throw new RuntimeException('De logo-afbeelding heeft geen geldige URL.');
    }
    $ips = gethostbynamel($host);
    if (!$ips) throw new RuntimeException('De logo-website is niet bereikbaar.');
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Ongeldig adres voor de logo-afbeelding.');
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'DONDEBAILAR/1.0',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => 2097152,
    ]);
    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!is_string($data) || $status < 200 || $status >= 300 || strlen($data) > 2097152) {
        throw new RuntimeException('Het gevonden logo kon niet worden opgehaald.');
    }
    $image = @getimagesizefromstring($data);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $actualMime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
    if (!isset($types[$actualMime]) || ($mime !== '' && stripos($mime, $actualMime) !== 0)) {
        throw new RuntimeException('De gevonden afbeelding is geen geldig JPG-, PNG- of WebP-logo.');
    }
    if (($image[0] ?? 0) > 6000 || ($image[1] ?? 0) > 6000) {
        throw new RuntimeException('Het logo is te groot qua afmetingen.');
    }
    $directory = dirname(__DIR__) . '/uploads/locations';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('De uploadmap voor locatielogo’s kon niet worden aangemaakt.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $types[$actualMime];
    if (file_put_contents($directory . '/' . $filename, $data, LOCK_EX) === false) {
        throw new RuntimeException('Het locatielogo kon niet worden opgeslagen.');
    }
    return 'uploads/locations/' . $filename;
}
