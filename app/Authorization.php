<?php
declare(strict_types=1);

/**
 * Central authorization helpers.
 *
 * Authentication is based on the user ID stored in the server-side session.
 * Every protected page and action must call the relevant require* function;
 * hiding a menu item is never an authorization check.
 */

function currentUser(): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }
    $loaded = true;

    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1) {
        return null;
    }

    $statement = Database::connection()->prepare(
        "SELECT id, email, display_name, preferred_language, platform_role, status
         FROM users
         WHERE id = :id AND status = 'active'
         LIMIT 1"
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch() ?: null;

    if ($user === null) {
        unset($_SESSION['user_id']);
    }

    return $user;
}

function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        $returnTo = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login.php?return=' . rawurlencode($returnTo));
        exit;
    }

    return $user;
}

function isPlatformAdmin(?array $user = null): bool
{
    $user ??= currentUser();
    return $user !== null && ($user['platform_role'] ?? '') === 'admin';
}

function userHasRegionRole(int $regionId, array $allowedRoles, ?array $user = null): bool
{
    $user ??= currentUser();
    if ($user === null || $regionId < 1 || $allowedRoles === []) {
        return false;
    }
    if (isPlatformAdmin($user)) {
        return true;
    }

    $allowedRoles = array_values(array_intersect(
        $allowedRoles,
        ['region_admin', 'editor']
    ));
    if ($allowedRoles === []) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($allowedRoles), '?'));
    $sql = "SELECT 1 FROM user_region_roles
            WHERE user_id = ? AND region_id = ? AND role IN ($placeholders)
            LIMIT 1";
    $statement = Database::connection()->prepare($sql);
    $statement->execute(array_merge([(int) $user['id'], $regionId], $allowedRoles));

    return (bool) $statement->fetchColumn();
}

function requireRegionRole(int $regionId, array $allowedRoles): array
{
    $user = requireLogin();
    if (!userHasRegionRole($regionId, $allowedRoles, $user)) {
        http_response_code(403);
        exit('Je hebt geen toegang tot deze regio of deze handeling.');
    }

    return $user;
}

function requirePlatformAdmin(): array
{
    $user = requireLogin();
    if (!isPlatformAdmin($user)) {
        http_response_code(403);
        exit('Alleen de platformbeheerder heeft toegang tot deze pagina.');
    }

    return $user;
}
