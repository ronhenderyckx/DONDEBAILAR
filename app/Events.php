<?php
declare(strict_types=1);

require_once __DIR__ . '/Authorization.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/ImageOptimization.php';

/** Lees de platformbrede maximale evenementduur in uren. */
function platformMaxEventDurationHours(): int
{
    $settingFile = dirname(__DIR__) . '/storage/platform-max-event-duration-hours.txt';
    if (!is_file($settingFile)) {
        return 10;
    }

    $hours = filter_var(trim((string) file_get_contents($settingFile)), FILTER_VALIDATE_INT);
    return $hours !== false && $hours >= 1 && $hours <= 168 ? $hours : 10;
}

/** Regio's waarin de gebruiker evenementen mag aanmaken. */
function eventManageableRegions(PDO $db, array $user): array
{
    if (isPlatformAdmin($user)) {
        return $db->query(
            "SELECT id, name, timezone, default_language
             FROM regions WHERE is_active = 1 ORDER BY name"
        )->fetchAll();
    }

    $statement = $db->prepare(
        "SELECT DISTINCT r.id, r.name, r.timezone, r.default_language
         FROM regions r
         INNER JOIN user_region_roles urr ON urr.region_id = r.id
         WHERE urr.user_id = :user_id
           AND urr.role IN ('user', 'editor', 'region_admin')
           AND r.is_active = 1
         ORDER BY r.name"
    );
    $statement->execute(['user_id' => (int) $user['id']]);
    return $statement->fetchAll();
}

/** Controleer of een gebruiker een evenement mag wijzigen. */
function eventCanEdit(PDO $db, array $event, array $user): bool
{
    if (isPlatformAdmin($user)) {
        return true;
    }

    $regionId = (int) ($event['region_id'] ?? 0);
    if (!userHasRegionRole($regionId, ['user', 'editor', 'region_admin'], $user)) {
        return false;
    }

    if ((int) ($event['created_by'] ?? 0) === (int) $user['id']) {
        return true;
    }

    return userHasRegionRole($regionId, ['editor', 'region_admin'], $user);
}

/** Bewaar meerdere foto's, video's of reels bij een bestaand evenement. */
function storeEventMedia(PDO $db, int $eventId, int $userId, array $fileGroups, array $captions = []): array
{
    $baseDirectory = dirname(__DIR__) . '/uploads/events/' . $eventId;
    if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0755, true) && !is_dir($baseDirectory)) {
        throw new RuntimeException('De mediamap kon niet worden aangemaakt.');
    }

    $rules = [
        'photo' => [
            'field' => 'photos',
            'max' => 15 * 1024 * 1024,
            'mimes' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        ],
        'video' => [
            'field' => 'videos',
            'max' => 80 * 1024 * 1024,
            'mimes' => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'],
        ],
        'reel' => [
            'field' => 'reels',
            'max' => 80 * 1024 * 1024,
            'mimes' => ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'],
        ],
    ];

    $saved = [];
    try {
        foreach ($rules as $type => $rule) {
            $group = $fileGroups[$rule['field']] ?? null;
            if (!is_array($group) || !isset($group['name']) || !is_array($group['name'])) {
                continue;
            }

            $count = count($group['name']);
            for ($i = 0; $i < $count; $i++) {
                $error = (int) ($group['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($error !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Een mediabestand kon niet worden geüpload. Controleer de maximale uploadgrootte van PHP.');
                }

                $tmp = (string) ($group['tmp_name'][$i] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Een geüpload mediabestand is ongeldig.');
                }
                if ((int) ($group['size'][$i] ?? 0) > $rule['max']) {
                    throw new RuntimeException($type === 'photo'
                        ? 'Een foto mag maximaal 15 MB groot zijn.'
                        : 'Een video of reel mag maximaal 80 MB groot zijn.');
                }

                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($tmp);
                if (!isset($rule['mimes'][$mime])) {
                    throw new RuntimeException($type === 'photo'
                        ? 'Gebruik voor foto’s JPG, PNG of WebP.'
                        : 'Gebruik voor video’s en reels MP4, WebM of MOV.');
                }

                if ($type === 'photo') {
                    $imageData = file_get_contents($tmp);
                    if (!is_string($imageData)) {
                        throw new RuntimeException('Een fotobestand kon niet worden gelezen.');
                    }
                    $filename = bin2hex(random_bytes(16)) . '.webp';
                    $destination = $baseDirectory . '/' . $filename;
                    optimizeLogoDataToWebp($imageData, $destination, 1600);
                } else {
                    $filename = bin2hex(random_bytes(16)) . '.' . $rule['mimes'][$mime];
                    $destination = $baseDirectory . '/' . $filename;
                    if (!move_uploaded_file($tmp, $destination)) {
                        throw new RuntimeException('Een mediabestand kon niet worden opgeslagen.');
                    }
                }

                $relativePath = 'uploads/events/' . $eventId . '/' . $filename;
                $insert = $db->prepare(
                    "INSERT INTO event_media
                        (event_id, media_context, media_type, file_path, caption, uploaded_by)
                     VALUES (:event_id, 'event', :media_type, :file_path, :caption, :uploaded_by)"
                );
                $insert->execute([
                    'event_id' => $eventId,
                    'media_type' => $type === 'photo' ? 'image' : $type,
                    'file_path' => $relativePath,
                    'caption' => trim((string) ($captions[$type][$i] ?? '')) !== '' ? trim((string) $captions[$type][$i]) : null,
                    'uploaded_by' => $userId,
                ]);
                $saved[] = ['id' => (int) $db->lastInsertId(), 'path' => $relativePath];
            }
        }
    } catch (Throwable $exception) {
        foreach ($saved as $item) {
            $delete = $db->prepare("DELETE FROM event_media WHERE id = :id AND event_id = :event_id");
            $delete->execute(['id' => $item['id'], 'event_id' => $eventId]);
            $path = dirname(__DIR__) . '/' . $item['path'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        throw $exception;
    }

    return $saved;
}

/** Verwijder alleen mediabestanden die bij dit evenement horen. */
function deleteEventMediaFile(string $relativePath, int $eventId): void
{
    $prefix = 'uploads/events/' . $eventId . '/';
    if (!str_starts_with($relativePath, $prefix)) {
        return;
    }
    $filename = substr($relativePath, strlen($prefix));
    if (!preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp|mp4|webm|mov)\z/', $filename)) {
        return;
    }
    $path = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($path)) {
        @unlink($path);
    }
}


/**
 * Koppel een openbaar Facebook- of Instagram-bericht/reel aan een evenement.
 * De inhoud blijft op het oorspronkelijke platform; DONDEBAILAR bewaart de URL.
 */
function storeEventSocialMedia(PDO $db, int $eventId, int $userId, array $input): ?int
{
    $url = trim((string) ($input['social_url'] ?? ''));
    if ($url === '') return null;

    $platform = (string) ($input['social_platform'] ?? '');
    $kind = (string) ($input['social_kind'] ?? 'social_post');
    if (!in_array($platform, ['facebook', 'instagram'], true)) {
        throw new RuntimeException('Kies Facebook of Instagram voor de gekoppelde link.');
    }
    if (!in_array($kind, ['reel', 'social_post'], true)) {
        throw new RuntimeException('Kies een geldige soort bericht op sociale media.');
    }
    if (strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Vul een geldige openbare Facebook- of Instagram-link in.');
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    if ($scheme !== 'https') {
        throw new RuntimeException('Gebruik de volledige HTTPS-link van het bericht.');
    }

    if ($platform === 'instagram') {
        if (!in_array($host, ['instagram.com', 'www.instagram.com'], true)
            || !preg_match('~^/(?:p|reel|tv)/[A-Za-z0-9_-]+/?$~', $path)) {
            throw new RuntimeException('Gebruik een openbare Instagram-post- of reel-link.');
        }
    } else {
        if (!in_array($host, ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.watch'], true)
            || trim($path, '/') === '') {
            throw new RuntimeException('Gebruik een openbare Facebook-bericht-, video- of reel-link.');
        }
    }

    $insert = $db->prepare(
        "INSERT INTO event_media
            (event_id, media_context, media_type, file_path, media_source, external_url, uploaded_by)
         VALUES (:event_id, 'event', :media_type, NULL, :media_source, :external_url, :uploaded_by)"
    );
    $insert->execute([
        'event_id' => $eventId,
        'media_type' => $kind,
        'media_source' => $platform,
        'external_url' => $url,
        'uploaded_by' => $userId,
    ]);
    return (int) $db->lastInsertId();
}
