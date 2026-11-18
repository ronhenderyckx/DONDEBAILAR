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

        $manifestUrl = 'https://raw.githubusercontent.com/ronhenderyckx/DONDEBAILAR/develop/database/update-commands.json';
        $ch = curl_init($manifestUrl);
        if ($ch === false) {
            throw new RuntimeException('GitHub-verbinding kon niet worden gestart.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $manifestBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if (!is_string($manifestBody) || $httpCode !== 200) {
            throw new RuntimeException('Opdrachtenbestand kon niet van GitHub worden gelezen. HTTP ' . $httpCode . ' ' . $curlError);
        }
        $manifest = json_decode($manifestBody, true, 64, JSON_THROW_ON_ERROR);
        if (($manifest['project'] ?? '') !== 'DONDEBAILAR'
            || ($manifest['environment'] ?? '') !== 'test'
            || !is_array($manifest['steps'] ?? null)) {
            throw new RuntimeException('Het GitHub-opdrachtenbestand is niet geldig voor DONDEBAILAR TEST.');
        }
        $target = $manifest['deployment']['targets']['test'] ?? [];
        if (($target['host'] ?? '') !== 'test.dondebailar.eu'
            || ($target['private_root'] ?? '') !== 'private/DONDEBAILAR-TEST'
            || ($target['public_root'] ?? '') !== 'test') {
            throw new RuntimeException('De TEST-doelpaden in het opdrachtenbestand zijn gewijzigd of ongeldig.');
        }
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

        $executedDirectoryStep = false;
        foreach ($manifest['steps'] as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'create_directories') {
                continue;
            }
            if (($step['id'] ?? '') !== 'create_test_private_directories'
                || !is_array($step['directories'] ?? null)
                || $step['directories'] === []) {
                throw new RuntimeException('De opdracht voor private TEST-mappen is ongeldig.');
            }

            foreach ($step['directories'] as $relativePath) {
                if (!is_string($relativePath)
                    || ($relativePath !== 'private/DONDEBAILAR-TEST'
                        && !str_starts_with($relativePath, 'private/DONDEBAILAR-TEST/'))) {
                    throw new RuntimeException('Een mapopdracht valt buiten private/DONDEBAILAR-TEST.');
                }
                $segments = explode('/', $relativePath);
                foreach ($segments as $segment) {
                    if ($segment === '' || $segment === '.' || $segment === '..') {
                        throw new RuntimeException('Ongeldig pad in de mapopdracht.');
                    }
                }
                $absolutePath = $accountRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $ensureDirectory($absolutePath, $relativePath);
            }
            $executedDirectoryStep = true;
        }

        if (!$executedDirectoryStep) {
            throw new RuntimeException('Geen geldige create_directories-opdracht gevonden in update-commands.json.');
        }

        $results[] = 'GitHub-opdrachtenbestand gelezen: ' . (string) ($manifest['release'] ?? 'onbekende release');
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
