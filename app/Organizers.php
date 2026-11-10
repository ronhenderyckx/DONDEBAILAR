<?php
declare(strict_types=1);

require_once __DIR__ . '/Authorization.php';

/** Regio's waarin de gebruiker organisatoren mag beheren. */
function organizerManageableRegions(PDO $db, array $user): array
{
    if (isPlatformAdmin($user)) {
        return $db->query(
            "SELECT id, name FROM regions WHERE is_active = 1 ORDER BY name"
        )->fetchAll();
    }

    $statement = $db->prepare(
        "SELECT DISTINCT r.id, r.name
         FROM regions r
         INNER JOIN user_region_roles urr ON urr.region_id = r.id
         WHERE urr.user_id = :user_id
           AND urr.role = 'region_admin'
           AND r.is_active = 1
         ORDER BY r.name"
    );
    $statement->execute(['user_id' => (int) $user['id']]);
    return $statement->fetchAll();
}

/** Alleen platformbeheerders en beheerders van de betreffende regio mogen beheren. */
function organizerCanManageRegion(int $regionId, array $user): bool
{
    return $regionId > 0 && (
        isPlatformAdmin($user)
        || userHasRegionRole($regionId, ['region_admin'], $user)
    );
}
