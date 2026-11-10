<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Authorization.php';
require_once dirname(__DIR__) . '/app/Security.php';

$pageTitle = 'Inloggen - DONDEBAILAR';
$errorMessage = '';
$email = '';

$returnTo = $_GET['return'] ?? $_POST['return'] ?? '/admin/';

if (
    !is_string($returnTo)
    || !str_starts_with($returnTo, '/')
    || str_starts_with($returnTo, '//')
    || preg_match('/[\r\n]/', $returnTo)
) {
    $returnTo = '/admin/';
}

if (currentUser() !== null) {
    header('Location: ' . $returnTo);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        $errorMessage = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $errorMessage = 'Vul een geldig e-mailadres en wachtwoord in.';
    } else {
        $statement = Database::connection()->prepare(
            "SELECT id, password_hash, status
             FROM users
             WHERE email = :email
             ORDER BY id DESC"
        );
        $statement->execute(['email' => strtolower($email)]);
        $matchingUsers = $statement->fetchAll();
        $user = null;

        // Meerdere testaccounts mogen hetzelfde e-mailadres delen.
        // Het wachtwoord bepaalt bij welk account de gebruiker inlogt.
        foreach ($matchingUsers as $candidate) {
            if (
                $candidate['status'] === 'active'
                && password_verify($password, $candidate['password_hash'])
            ) {
                $user = $candidate;
                break;
            }
        }

        if ($user === null) {
            $errorMessage = 'E-mailadres of wachtwoord is niet correct.';
        } else {
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            unset($_SESSION['csrf_token']);

            header('Location: ' . $returnTo);
            exit;
        }
    }
}

require dirname(__DIR__) . '/app/views/header.php';
?>

<section class="auth-card">
    <h1>Inloggen</h1>
    <p>Log in om DONDEBAILAR te beheren.</p>

    <?php if ($errorMessage !== ''): ?>
        <div class="message message--error" role="alert">
            <?= escapeHtml($errorMessage) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/login.php" class="auth-form">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= escapeHtml(csrfToken()) ?>"
        >

        <input
            type="hidden"
            name="return"
            value="<?= escapeHtml($returnTo) ?>"
        >

        <label for="email">E-mailadres</label>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= escapeHtml($email) ?>"
            autocomplete="username"
            maxlength="190"
            required
        >

        <label for="password">Wachtwoord</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit" class="button button--primary">
            Inloggen
        </button>
    </form>
</section>

<?php require dirname(__DIR__) . '/app/views/footer.php'; ?>
