<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/Authorization.php';
require_once dirname(__DIR__, 2) . '/app/Security.php';

requirePlatformAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Gebruik de UPDATE-knop in het platformbeheer.');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('De beveiligingscontrole is verlopen. Vernieuw de pagina en probeer opnieuw.');
}

$pageTitle = 'DONDEBAILAR bijwerken';
$projectRoot = dirname(__DIR__, 2);
$messages = [];
$success = false;

function runUpdateGit(array $arguments, string $workingDirectory): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is niet beschikbaar op de server.');
    }

    $command = array_merge(['git'], $arguments);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException('Git kon niet worden gestart.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'code' => $exitCode,
        'output' => trim($stdout . ($stderr !== '' ? "\n" . $stderr : '')),
    ];
}

function runRequiredGit(array $arguments, string $workingDirectory): string
{
    $result = runUpdateGit($arguments, $workingDirectory);
    if ($result['code'] !== 0) {
        throw new RuntimeException(
            $result['output'] !== ''
                ? $result['output']
                : 'Git-opdracht is mislukt.'
        );
    }

    return $result['output'];
}

try {
    set_time_limit(180);

    if (!is_dir($projectRoot . '/.git')) {
        throw new RuntimeException('Deze installatie is geen Git-checkout. Automatisch bijwerken is hier niet mogelijk.');
    }

    $branch = runRequiredGit(['branch', '--show-current'], $projectRoot);
    if ($branch !== 'develop') {
        throw new RuntimeException(
            'De actieve Git-branch is "' . $branch . '". De updater werkt uitsluitend op branch develop.'
        );
    }

    $changes = runRequiredGit(
        ['status', '--porcelain', '--untracked-files=normal'],
        $projectRoot
    );
    if ($changes !== '') {
        throw new RuntimeException(
            'Er staan lokale wijzigingen of niet-gevolgde bestanden op de Pi. Om te voorkomen dat werk verloren gaat, is de update gestopt.'
        );
    }

    runRequiredGit(['fetch', '--prune', 'origin', 'develop'], $projectRoot);

    $currentCommit = runRequiredGit(['rev-parse', 'HEAD'], $projectRoot);
    $availableCommit = runRequiredGit(['rev-parse', 'FETCH_HEAD'], $projectRoot);

    if (!hash_equals($currentCommit, $availableCommit)) {
        runRequiredGit(['merge-base', '--is-ancestor', 'HEAD', 'FETCH_HEAD'], $projectRoot);
        runRequiredGit(['pull', '--ff-only', 'origin', 'develop'], $projectRoot);
        $success = true;
        $messages[] = 'DONDEBAILAR is bijgewerkt naar de nieuwste versie van develop.';
    } else {
        $messages[] = 'DONDEBAILAR is al up-to-date. Er was geen nieuwe versie beschikbaar.';
        $success = true;
    }

    $shortCommit = runRequiredGit(['rev-parse', '--short', 'HEAD'], $projectRoot);
    $messages[] = 'Geïnstalleerde versie: ' . $shortCommit;
} catch (Throwable $exception) {
    http_response_code(500);
    $messages[] = 'UPDATE NIET UITGEVOERD';
    $messages[] = $exception->getMessage();
}

require dirname(__DIR__, 2) . '/app/views/header.php';
?>

<section class="admin-page">
    <div class="admin-heading">
        <div>
            <p class="eyebrow">DONDEBAILAR</p>
            <h1>Platformupdate</h1>
        </div>
    </div>

    <section class="admin-section">
        <?php foreach ($messages as $message): ?>
            <p><?= escapeHtml($message) ?></p>
        <?php endforeach; ?>

        <p>
            <a class="button button--primary" href="/admin/">
                Terug naar platformbeheer
            </a>
        </p>
    </section>
</section>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
