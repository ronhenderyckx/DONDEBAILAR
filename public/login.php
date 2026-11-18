<?php
declare(strict_types=1);

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\\d+$/', '', $host) ?? '';
$environmentRoots = [
    'test.dondebailar.eu' => 'DONDEBAILAR-TEST',
];

if (!isset($environmentRoots[$host])) {
    http_response_code(404);
    exit('Inloggen is niet beschikbaar op deze website.');
}

$privateRoot = dirname(__DIR__) . '/private/' . $environmentRoots[$host];
if (!is_file($privateRoot . '/app/bootstrap.php')) {
    http_response_code(503);
    exit('De private applicatiebestanden zijn niet beschikbaar.');
}

require_once $privateRoot . '/app/bootstrap.php';
require_once $privateRoot . '/app/Authorization.php';
require_once $privateRoot . '/app/Security.php';

$pageTitle = 'Inloggen - DONDEBAILAR';
$errorMessage = '';
$login = '';

$returnTo = $_GET['return'] ?? $_POST['return'] ?? '/admin/';

if (
    !is_string($returnTo)
    || !str_starts_with($returnTo, '/')
    || str_starts_with($returnTo, '//')
    || preg_match('/[\\r\\n]/', $returnTo)
) {
    $returnTo = '/admin/';
}

if ($returnTo === '/') {
    $returnTo = '/admin/';
}

if (currentUser() !== null) {
    header('Location: ' . $returnTo);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token = $_POST['csrf_token'] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        $errorMessage = 'Je sessie is verlopen. Vernieuw de pagina en probeer opnieuw.';
    } elseif ($login === '' || strlen($login) > 190 || $password === '') {
        $errorMessage = 'Vul je gebruikersnaam of e-mailadres en wachtwoord in.';
    } else {
        try {
            $statement = Database::connection()->prepare(
                "SELECT id, password_hash, status
                 FROM users
                 WHERE email = :email OR display_name = :display_name
                 ORDER BY id DESC"
            );
            $statement->execute([
                'email' => strtolower($login),
                'display_name' => $login,
            ]);
            $matchingUsers = $statement->fetchAll();
            $user = null;

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
                $errorMessage = 'Gebruikersnaam/e-mailadres of wachtwoord is niet correct.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                unset($_SESSION['csrf_token']);
                header('Location: ' . $returnTo);
                exit;
            }
        } catch (Throwable $error) {
            error_log('DONDEBAILAR login error: ' . get_class($error) . ': ' . $error->getMessage());
            $errorMessage = 'Inloggen is tijdelijk niet mogelijk. Controleer de database-instellingen of probeer later opnieuw.';
        }
    }
}

require $privateRoot . '/app/views/header.php';
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
        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
        <input type="hidden" name="return" value="<?= escapeHtml($returnTo) ?>">

        <label for="login">Gebruikersnaam of e-mailadres</label>
        <input
            type="text"
            id="login"
            name="login"
            value="<?= escapeHtml($login) ?>"
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

        <button type="submit" class="button button--primary">Inloggen</button>
    </form>

    <p><a href="/register.php">Registreren via e-mail</a></p>
    <p><a href="/forgot-password.php">Wachtwoord vergeten?</a></p>
</section>

<?php require $privateRoot . '/app/views/footer.php'; ?>
