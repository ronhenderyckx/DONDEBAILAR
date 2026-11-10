<?php
declare(strict_types=1);

require_once __DIR__ . '/Authorization.php';

/** Regio's waarin de gebruiker locaties mag beheren. */
function locationManageableRegions(PDO $db, array $user): array
{
    if (isPlatformAdmin($user)) {
        return $db->query(
            "SELECT id, name, country_code
             FROM regions WHERE is_active = 1 ORDER BY name"
        )->fetchAll();
    }

    $statement = $db->prepare(
        "SELECT DISTINCT r.id, r.name, r.country_code
         FROM regions r
         INNER JOIN user_region_roles urr ON urr.region_id = r.id
         WHERE urr.user_id = :user_id
           AND urr.role IN ('editor', 'region_admin')
           AND r.is_active = 1
         ORDER BY r.name"
    );
    $statement->execute(['user_id' => (int) $user['id']]);
    return $statement->fetchAll();
}

/** Controleer of een gebruiker locaties in deze regio mag beheren. */
function locationCanManageRegion(int $regionId, array $user): bool
{
    return userHasRegionRole($regionId, ['editor', 'region_admin'], $user);
}

/** Alleen Google Maps-deellinks worden als kaartlink aanvaard. */
function isGoogleMapsUrl(string $url): bool
{
    if ($url === '' || strlen($url) > 700 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    return in_array($scheme, ['http', 'https'], true)
        && in_array($host, [
            'google.com', 'www.google.com', 'maps.google.com',
            'maps.app.goo.gl', 'goo.gl'
        ], true);
}
