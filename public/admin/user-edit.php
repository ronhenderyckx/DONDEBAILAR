<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

$currentAdmin = requirePlatformAdmin();
$db = Database::connection();

$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$userId || $userId < 1) {
    http_response_code(400);
    exit('Ongeldig gebruikersnummer.');
}

$statement = $db->prepare(
    "SELECT id, email, display_name, preferred_language,
            platform_role, status
     FROM users
     WHERE id = :id
     LIMIT 1"
);
$statement->execute(['id' => $userId]);
$editUser = $statement->fetch();

if (!$editUser) {
    http_response_code(404);
    exit('Gebruiker niet gevonden.');
}

$pageTitle = 'Gebruiker wijzigen - DONDEBAILAR';
$errorMessage = '';

$regions = $db->query(
    "SELECT id, name FROM regions ORDER BY name"
)->fetchAll();

$roleStatement = $db->prepare(
    "SELECT region_id, role
     FROM user_region_roles
     WHERE user_id = :user_id"
);
$roleStatement->execute(['user_id' => $userId]);

$regionRoles = [];
foreach ($roleStatement->fetchAll() as $assignment) {
    $regionRoles[(int) $assignment['region_id']] = $assignment['role'];
}

$form = [
    'display_name' => (string) $editUser['display_name'],
    'email' => (string) $editUser['email'],
    'password' => '',
    'preferred_language' => (string) $editUser['preferred_language'],
    'platform_role' => (string) $editUser['platform_role'],
    'status' => (string) $editUser['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['display_name', 'email', 'password', 'preferred_language', 'platform_role', 'status'] as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $regionRoles = [];
    $postedRoles = $_POST['regions'] ?? [];

    if (is_array($postedRoles)) {
        foreach ($postedRoles as $regionId => $role) {
            $regionRoles[(int) $regionId] = (string) $role;
        }
    }

    $token = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        $errorMessage = 'De beveiligingscontrole is mislukt. Vernieuw de pagina.';
    } elseif ($form['display_name'] === '' || strlen($form['display_name']) > 120) {
        $errorMessage = 'Vul een geldige naam in.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 190) {
        $errorMessage = 'Vul een geldig e-mailadres in.';
    } elseif ($form['password'] !== '' && strlen($form['password']) < 12) {
        $errorMessage = 'Een nieuw wachtwoord moet minimaal 12 tekens bevatten.';
    } elseif (!in_array($form['preferred_language'], ['nl', 'en', 'es', 'fr'], true)) {
        $errorMessage = 'Kies een geldige taal.';
    } elseif (!in_array($form['platform_role'], ['user', 'admin'], true)) {
        $errorMessage = 'Kies een geldige platformrol.';
    } elseif (!in_array($form['status'], ['pending_email', 'active', 'suspended'], true)) {
        $errorMessage = 'Kies een geldige accountstatus.';
    } elseif ((int) $currentAdmin['id'] === (int) $userId
        && ($form['status'] !== 'active' || $form['platform_role'] !== 'admin')) {
        $errorMessage = 'Je kunt je eigen platformbeheerderaccount niet deactiveren of de beheerdersrol ontnemen.';
    } else {
        try {
            $db->beginTransaction();

            $sql = "UPDATE users
                    SET email = :email,
                        display_name = :display_name,
                        preferred_language = :language,
                        platform_role = :platform_role,
                        status = :status";

            $parameters = [
                'email' => strtolower($form['email']),
                'display_name' => $form['display_name'],
                'language' => $form['preferred_language'],
                'platform_role' => $form['platform_role'],
                'status' => $form['status'],
                'id' => $userId,
            ];

            if ($form['password'] !== '') {
                $sql .= ", password_hash = :password_hash";
                $parameters['password_hash'] = password_hash($form['password'], PASSWORD_DEFAULT);
            }

            if ($form['status'] === 'active') {
                $sql .= ", email_verified_at = COALESCE(email_verified_at, NOW())";
            }

            $sql .= " WHERE id = :id";

            $update = $db->prepare($sql);
            $update->execute($parameters);

            $deleteRoles = $db->prepare(
                "DELETE FROM user_region_roles WHERE user_id = :user_id"
            );
            $deleteRoles->execute(['user_id' => $userId]);

            $validRegionIds = array_map(
                'intval',
                array_column($regions, 'id')
            );

            $assign = $db->prepare(
                "INSERT INTO user_region_roles
                    (user_id, region_id, role, assigned_by)
                 VALUES
                    (:user_id, :region_id, :role, :assigned_by)"
            );

            foreach ($regionRoles as $regionId => $role) {
                if (!in_array($regionId, $validRegionIds, true)) {
                    throw new RuntimeException('Ongeldige regio geselecteerd.');
                }

                if (!in_array($role, ['user', 'editor', 'region_admin'], true)) {
                    throw new RuntimeException('Ongeldige regionale rol geselecteerd.');
                }

                $assign->execute([
                    'user_id' => $userId,
                    'region_id' => $regionId,
                    'role' => $role,
                    'assigned_by' => (int) $currentAdmin['id'],
                ]);
            }

            $db->commit();

            header('Location: /admin/users.php?updated=1');
            exit;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $errorMessage = 'Dit e-mailadres bestaat al bij een andere gebruiker.';
            } else {
                error_log('DONDEBAILAR gebruiker wijzigen: ' . $exception->getMessage());
                $errorMessage = 'De gebruiker kon niet worden bijgewerkt. Controleer de serverlog.';
            }
        }
    }
}

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Gebruiker wijzigen</h1>
            <p>Wijzig accountgegevens en regionale toegangen.</p>
        </div>
        <a class="button" href="/admin/users.php">Terug naar overzicht</a>
    </div>

    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert">
                <?= escapeHtml($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post"
              action="/admin/user-edit.php?id=<?= (int) $userId ?>"
              class="auth-form">

            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <label for="display_name">Naam</label>
            <input type="text" id="display_name" name="display_name"
                   maxlength="120" value="<?= escapeHtml($form['display_name']) ?>" required>

            <label for="email">E-mailadres</label>
            <input type="email" id="email" name="email"
                   maxlength="190" value="<?= escapeHtml($form['email']) ?>" required>

            <label for="password">Nieuw wachtwoord</label>
            <input type="password" id="password" name="password"
                   minlength="12" autocomplete="new-password">
            <p>Laat dit veld leeg om het huidige wachtwoord te behouden.</p>

            <label for="preferred_language">Voorkeurstaal</label>
            <select id="preferred_language" name="preferred_language">
                <option value="nl" <?= $form['preferred_language'] === 'nl' ? 'selected' : '' ?>>Nederlands</option>
                <option value="en" <?= $form['preferred_language'] === 'en' ? 'selected' : '' ?>>English</option>
                <option value="es" <?= $form['preferred_language'] === 'es' ? 'selected' : '' ?>>Español</option>
                <option value="fr" <?= $form['preferred_language'] === 'fr' ? 'selected' : '' ?>>Français</option>
            </select>

            <label for="platform_role">Platformrol</label>
            <select id="platform_role" name="platform_role">
                <option value="user" <?= $form['platform_role'] === 'user' ? 'selected' : '' ?>>Gebruiker</option>
                <option value="admin" <?= $form['platform_role'] === 'admin' ? 'selected' : '' ?>>Platformbeheerder</option>
            </select>

            <label for="status">Accountstatus</label>
            <select id="status" name="status">
                <option value="active" <?= $form['status'] === 'active' ? 'selected' : '' ?>>Actief</option>
                <option value="pending_email" <?= $form['status'] === 'pending_email' ? 'selected' : '' ?>>E-mail nog niet bevestigd</option>
                <option value="suspended" <?= $form['status'] === 'suspended' ? 'selected' : '' ?>>Geblokkeerd</option>
            </select>

            <h2>Regionale toegangen</h2>
            <p>Stel per regio de toegangsrechten in.</p>

            <?php foreach ($regions as $region): ?>
                <?php $rid = (int) $region['id']; ?>
                <div class="admin-section">
                    <label><?= escapeHtml($region['name']) ?></label>
                    <select name="regions[<?= $rid ?>]">
                        <option value="">Geen toegang</option>
                        <option value="user" <?= ($regionRoles[$rid] ?? '') === 'user' ? 'selected' : '' ?>>Gewone gebruiker</option>
                        <option value="editor" <?= ($regionRoles[$rid] ?? '') === 'editor' ? 'selected' : '' ?>>Editor</option>
                        <option value="region_admin" <?= ($regionRoles[$rid] ?? '') === 'region_admin' ? 'selected' : '' ?>>Regionale beheerder</option>
                    </select>
                </div>
            <?php endforeach; ?>

            <div class="form-actions">
                <button type="submit" class="button button--primary">Wijzigingen opslaan</button>
                <a class="button" href="/admin/users.php">Annuleren</a>
            </div>
        </form>
    </section>
</section>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
