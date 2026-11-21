<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_private-root.php';

require_once DONDEBAILAR_PRIVATE_ROOT . '/app/bootstrap.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Authorization.php';
require_once DONDEBAILAR_PRIVATE_ROOT . '/app/Security.php';

$user = requirePlatformAdmin();
$db = Database::connection();

$pageTitle = 'Nieuwe gebruiker - DONDEBAILAR';
$platformAdminPage = true;
$errorMessage = '';

$platformFormColors = [
    'form_panel_bg' => '#F7F8FA',
    'form_label_text' => '#202B38',
    'form_input_bg' => '#FFFFFF',
    'form_input_text' => '#202B38',
    'form_input_border' => '#E7EAF0',
    'form_input_hover_border' => '#0A4D9F',
    'form_button_bg' => '#0A4D9F',
    'form_button_text' => '#FFFFFF',
    'form_button_hover_bg' => '#083B79',
    'form_button_hover_text' => '#FFFFFF',
    'border' => '#E7EAF0',
];
$platformFormColorsFile = DONDEBAILAR_PRIVATE_ROOT . '/storage/platform-admin-colors.json';
if (is_file($platformFormColorsFile)) {
    $savedFormColors = json_decode((string) file_get_contents($platformFormColorsFile), true);
    if (is_array($savedFormColors)) {
        foreach ($platformFormColors as $colorKey => $fallbackColor) {
            $candidateColor = strtoupper(trim((string) ($savedFormColors[$colorKey] ?? '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $candidateColor)) {
                $platformFormColors[$colorKey] = $candidateColor;
            }
        }
    }
}

$regions = $db->query(
    "SELECT id, name FROM regions ORDER BY name"
)->fetchAll();

$form = [
    'display_name' => '',
    'email' => '',
    'password' => '',
    'preferred_language' => 'nl',
    'platform_role' => 'user',
    'status' => 'active',
];

$regionRoles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['display_name', 'email', 'password', 'preferred_language', 'platform_role', 'status'] as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

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
        $errorMessage = 'Vul een geldige naam in (maximaal 120 tekens).';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 190) {
        $errorMessage = 'Vul een geldig e-mailadres in.';
    } elseif (strlen($form['password']) < 12) {
        $errorMessage = 'Het wachtwoord moet minimaal 12 tekens bevatten.';
    } elseif (!in_array($form['preferred_language'], ['nl', 'en', 'es', 'fr'], true)) {
        $errorMessage = 'Kies een geldige taal.';
    } elseif (!in_array($form['platform_role'], ['user', 'admin'], true)) {
        $errorMessage = 'Kies een geldige platformrol.';
    } elseif (!in_array($form['status'], ['pending_email', 'active', 'suspended'], true)) {
        $errorMessage = 'Kies een geldige accountstatus.';
    } else {
        try {
            $db->beginTransaction();

            $insert = $db->prepare(
                "INSERT INTO users
                    (email, password_hash, display_name, preferred_language,
                     platform_role, status, email_verified_at)
                 VALUES
                    (:email, :password_hash, :display_name, :language,
                     :platform_role, :status, :verified_at)"
            );

            $insert->execute([
                'email' => strtolower($form['email']),
                'password_hash' => password_hash($form['password'], PASSWORD_DEFAULT),
                'display_name' => $form['display_name'],
                'language' => $form['preferred_language'],
                'platform_role' => $form['platform_role'],
                'status' => $form['status'],
                'verified_at' => $form['status'] === 'active' ? date('Y-m-d H:i:s') : null,
            ]);

            $newUserId = (int) $db->lastInsertId();

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
                    'user_id' => $newUserId,
                    'region_id' => $regionId,
                    'role' => $role,
                    'assigned_by' => (int) $user['id'],
                ]);
            }

            $db->commit();

            header('Location: /admin/users.php?created=1');
            exit;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('DONDEBAILAR gebruiker aanmaken: ' . $exception->getMessage());
            $errorMessage = 'De gebruiker kon niet worden aangemaakt. Controleer de serverlog.';
        }
    }
}

require DONDEBAILAR_PRIVATE_ROOT . '/app/views/header.php';
?>
<style>
.admin-page .auth-form {
    background: <?= escapeHtml($platformFormColors['form_panel_bg']) ?>;
    color: <?= escapeHtml($platformFormColors['form_label_text']) ?>;
    border: 1px solid <?= escapeHtml($platformFormColors['border']) ?>;
    border-radius: 12px;
    padding: 24px;
}
.admin-page .auth-form > label,
.admin-page .auth-form h2 { color: <?= escapeHtml($platformFormColors['form_label_text']) ?>; }
.admin-page .auth-form input,
.admin-page .auth-form select,
.admin-page .auth-form textarea {
    background-color: <?= escapeHtml($platformFormColors['form_input_bg']) ?> !important;
    color: <?= escapeHtml($platformFormColors['form_input_text']) ?> !important;
    border-color: <?= escapeHtml($platformFormColors['form_input_border']) ?> !important;
}
.admin-page .auth-form input:hover,
.admin-page .auth-form select:hover,
.admin-page .auth-form textarea:hover,
.admin-page .auth-form input:focus,
.admin-page .auth-form select:focus,
.admin-page .auth-form textarea:focus {
    border-color: <?= escapeHtml($platformFormColors['form_input_hover_border']) ?> !important;
}
.admin-page .auth-form .button,
.admin-page .auth-form button,
.admin-page .admin-heading .button {
    background: <?= escapeHtml($platformFormColors['form_button_bg']) ?> !important;
    color: <?= escapeHtml($platformFormColors['form_button_text']) ?> !important;
    border-color: <?= escapeHtml($platformFormColors['form_button_bg']) ?> !important;
}
.admin-page .auth-form .button:hover,
.admin-page .auth-form button:hover,
.admin-page .admin-heading .button:hover {
    background: <?= escapeHtml($platformFormColors['form_button_hover_bg']) ?> !important;
    color: <?= escapeHtml($platformFormColors['form_button_hover_text']) ?> !important;
    border-color: <?= escapeHtml($platformFormColors['form_button_hover_bg']) ?> !important;
}
.admin-page .auth-form p { color: <?= escapeHtml($platformFormColors['form_label_text']) ?>; }
</style>

<?php
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">PLATFORMBEHEER</p>
            <h1>Nieuwe gebruiker</h1>
            <p>Maak een account aan en wijs eventueel regio's toe.</p>
        </div>
        <a class="button" href="/admin/users.php">Terug</a>
    </div>

    <section class="admin-section">
        <?php if ($errorMessage !== ''): ?>
            <div class="message message--error" role="alert">
                <?= escapeHtml($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/user-new.php" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">

            <label for="display_name">Naam</label>
            <input type="text" id="display_name" name="display_name"
                   maxlength="120" value="<?= escapeHtml($form['display_name']) ?>" required>

            <label for="email">E-mailadres</label>
            <input type="email" id="email" name="email"
                   maxlength="190" value="<?= escapeHtml($form['email']) ?>" required>

            <label for="password">Tijdelijk wachtwoord</label>
            <input type="password" id="password" name="password"
                   minlength="12" autocomplete="new-password" required>
            <p>Minimaal 12 tekens. De gebruiker kan later zelf een nieuw wachtwoord instellen via ‘Wachtwoord vergeten’.</p>

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
            <p>Kies per regio welke rechten deze gebruiker krijgt.</p>

            <?php foreach ($regions as $region): ?>
                <?php $rid = (int) $region['id']; ?>
                <div class="admin-section">
                    <label>
                        <?= escapeHtml($region['name']) ?>
                    </label>
                    <select name="regions[<?= $rid ?>]">
                        <option value="">Geen toegang</option>
                        <option value="user" <?= ($regionRoles[$rid] ?? '') === 'user' ? 'selected' : '' ?>>Gewone gebruiker</option>
                        <option value="editor" <?= ($regionRoles[$rid] ?? '') === 'editor' ? 'selected' : '' ?>>Regioredacteur</option>
                        <option value="region_admin" <?= ($regionRoles[$rid] ?? '') === 'region_admin' ? 'selected' : '' ?>>Regionale beheerder</option>
                    </select>
                </div>
            <?php endforeach; ?>

            <div class="form-actions">
                <button type="submit" class="button button--primary">Gebruiker aanmaken</button>
                <a class="button" href="/admin/users.php">Annuleren</a>
            </div>
        </form>
    </section>
</section>

<?php require DONDEBAILAR_PRIVATE_ROOT . '/app/views/footer.php'; ?>
