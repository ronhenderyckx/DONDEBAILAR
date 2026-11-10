<?php
declare(strict_types=1);

require_once __DIR__ . '/Authorization.php';

/**
 * Alle organisatoren zijn platformbreed.
 * Platformbeheerders en regionale beheerders mogen alle organisatoren beheren.
 */
function requireOrganizerManagement(): array
{
    $user = requireLogin();

    if (isPlatformAdmin($user)) {
        return $user;
    }

    $statement = Database::connection()->prepare(
        "SELECT 1
         FROM user_region_roles
         WHERE user_id = :user_id
           AND role = 'region_admin'
         LIMIT 1"
    );
    $statement->execute(['user_id' => (int) $user['id']]);

    if (!$statement->fetchColumn()) {
        http_response_code(403);
        exit('Je hebt geen rechten om organisatoren te beheren.');
    }

    return $user;
}
