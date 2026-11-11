<?php
declare(strict_types=1);

/**
 * Convert an uploaded or downloaded logo to a compact WebP file.
 * Keeps the original proportions and transparency; never enlarges an image.
 */
function optimizeLogoDataToWebp(string $data, string $destination, int $maxDimension): void
{
    if (!function_exists('imagewebp')) {
        throw new RuntimeException('WebP-optimalisatie is niet beschikbaar op deze server (GD/WebP ontbreekt).');
    }

    $info = @getimagesizefromstring($data);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Gebruik een JPG-, PNG- of WebP-afbeelding.');
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000) {
        throw new RuntimeException('De afbeelding mag maximaal 6000 × 6000 pixels zijn.');
    }

    $source = @imagecreatefromstring($data);
    if ($source === false) {
        throw new RuntimeException('De afbeelding kon niet worden gelezen.');
    }

    $scale = min(1, $maxDimension / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($target === false) {
        imagedestroy($source);
        throw new RuntimeException('Er is onvoldoende geheugen om het logo te optimaliseren.');
    }

    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    $saved = @imagewebp($target, $destination, 82);
    imagedestroy($target);
    imagedestroy($source);

    if (!$saved || !is_file($destination) || filesize($destination) < 1) {
        if (is_file($destination)) {
            @unlink($destination);
        }
        throw new RuntimeException('Het geoptimaliseerde logo kon niet worden opgeslagen.');
    }
}

/** Store a validated uploaded logo and return its relative WebP path. */
function storeOptimizedLogoUpload(array $file, string $relativeDirectory, int $maxDimension, int $maxUploadBytes = 5242880, string $prefix = ''): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Het logo kon niet worden geüpload. Controleer de maximale uploadgrootte.');
    }

    $temporaryFile = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($temporaryFile === '' || !is_uploaded_file($temporaryFile) || $size < 1 || $size > $maxUploadBytes) {
        throw new RuntimeException('Kies een geldig logo binnen de maximale uploadgrootte.');
    }

    $data = file_get_contents($temporaryFile);
    if (!is_string($data)) {
        throw new RuntimeException('Het geüploade logo kon niet worden gelezen.');
    }

    return storeOptimizedLogoData($data, $relativeDirectory, $maxDimension, $prefix);
}

/** Store validated image bytes (including logos discovered online) as WebP. */
function storeOptimizedLogoData(string $data, string $relativeDirectory, int $maxDimension, string $prefix = ''): string
{
    $projectRoot = dirname(__DIR__);
    $relativeDirectory = trim($relativeDirectory, '/');
    if ($relativeDirectory === '' || str_contains($relativeDirectory, '..')) {
        throw new RuntimeException('Ongeldige uploadmap voor het logo.');
    }

    $directory = $projectRoot . '/' . $relativeDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('De uploadmap kon niet worden aangemaakt.');
    }

    $filename = $prefix . bin2hex(random_bytes(16)) . '.webp';
    $destination = $directory . '/' . $filename;
    optimizeLogoDataToWebp($data, $destination, $maxDimension);

    return $relativeDirectory . '/' . $filename;
}
