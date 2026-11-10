<?php
declare(strict_types=1);

require_once __DIR__ . '/Authorization.php';
require_once __DIR__ . '/Security.php';

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
function storeEventMedia(PDO $db, int $eventId, int $userId, array $fileGroups): array
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

                if ($type === 'photo' && @getimagesize($tmp) === false) {
                    throw new RuntimeException('Een fotobestand is geen geldige afbeelding.');
                }

                $filename = bin2hex(random_bytes(16)) . '.' . $rule['mimes'][$mime];
                $destination = $baseDirectory . '/' . $filename;
                if (!move_uploaded_file($tmp, $destination)) {
                    throw new RuntimeException('Een mediabestand kon niet worden opgeslagen.');
                }

                $relativePath = 'uploads/events/' . $eventId . '/' . $filename;
                $insert = $db->prepare(
                    "INSERT INTO event_media
                        (event_id, media_context, media_type, file_path, uploaded_by)
                     VALUES (:event_id, 'event', :media_type, :file_path, :uploaded_by)"
                );
                $insert->execute([
                    'event_id' => $eventId,
                    'media_type' => $type === 'photo' ? 'image' : $type,
                    'file_path' => $relativePath,
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
