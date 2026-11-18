<?php
declare(strict_types=1);

/**
 * DONDEBAILAR WebReus update runner.
 *
 * This file is downloaded from GitHub and included by the temporary
 * WebReus update-result.php page. It deliberately supports TEST only.
 * It does not execute shell commands or arbitrary PHP from JSON.
 */

if (!function_exists('dondebailar_webreus_run')) {
    function dondebailar_webreus_run(array $input): array
    {
        $host = strtolower((string) ($input['host'] ?? ($_SERVER['HTTP_HOST'] ?? '')));
        if ($host !== 'test.dondebailar.eu') {
            throw new RuntimeException('Deze updater is uitsluitend beschikbaar voor test.dondebailar.eu.');
        }

        if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
            throw new RuntimeException('Gebruik HTTPS om de updater uit te voeren.');
        }

        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot === false || basename($documentRoot) !== 'test') {
            throw new RuntimeException('De publieke TEST-map kon niet veilig worden vastgesteld.');
        }

        $accountRoot = realpath(dirname($documentRoot));
        if ($accountRoot === false) {
            throw new RuntimeException('De WebReus-accountmap kon niet worden vastgesteld.');
        }

        $expectedPublic = $accountRoot . DIRECTORY_SEPARATOR . 'test';
        if ($documentRoot !== $expectedPublic) {
            throw new RuntimeException('De documentroot komt niet overeen met de vaste TEST-map.');
        }

        $privateRoot = $accountRoot . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'DONDEBAILAR-TEST';
        $privateParent = $accountRoot . DIRECTORY_SEPARATOR . 'private';

        $results = [];

        $ensureDirectory = static function (string $path, string $label) use (&$results): void {
            if (is_link($path)) {
                throw new RuntimeException($label . ': symbolische links zijn niet toegestaan.');
            }
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException($label . ': map kon niet worden aangemaakt.');
            }
            $results[] = $label . ': OK';
        };

        $ensureDirectory($privateParent, 'Private-hoofdmap');
        $ensureDirectory($privateRoot, 'DONDEBAILAR-TEST');

        foreach (['app', 'config', 'database', 'uploads', 'storage', 'vendor'] as $folder) {
            $ensureDirectory($privateRoot . DIRECTORY_SEPARATOR . $folder, 'private/' . $folder);
        }

        $results[] = 'Publieke map test is niet gewijzigd.';
        $results[] = 'Preview en productie zijn niet benaderd of gewijzigd.';

        return [
            'success' => true,
            'environment' => 'test',
            'private_root' => 'private/DONDEBAILAR-TEST',
            'public_root' => 'test',
            'results' => $results,
        ];
    }
}
