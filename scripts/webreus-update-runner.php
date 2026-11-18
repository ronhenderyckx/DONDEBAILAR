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

        $curlGet = static function (string $url, string $accept = 'application/vnd.github+json'): string {
            $ch = curl_init($url);
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
                CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'User-Agent: DONDEBAILAR-WebReus-Updater'],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if (!is_string($body) || $status !== 200) {
                throw new RuntimeException('GitHub-download mislukt (HTTP ' . $status . '): ' . $error);
            }
            return $body;
        };

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
        $executedDeployStep = false;
        foreach ($manifest['steps'] as $step) {
            if (!is_array($step)) {
                throw new RuntimeException('Ongeldige opdracht in update-commands.json.');
            }

            if (($step['type'] ?? '') === 'create_directories') {
                if (!is_array($step['directories'] ?? null) || $step['directories'] === []) {
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
                continue;
            }

            if (($step['type'] ?? '') !== 'deploy_repository_tree') {
                throw new RuntimeException('Niet-ondersteund opdrachttype: ' . (string) ($step['type'] ?? 'onbekend'));
            }

            $sourceRef = (string) ($manifest['source_ref'] ?? '');
            if ($sourceRef === '' || !preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $sourceRef)) {
                throw new RuntimeException('De GitHub source_ref is ongeldig.');
            }

            $allowedPrefixes = [
                'app/',
                'config/config.example.php',
                'database/migrations/',
                'includes/',
                'modules/',
                'languages/',
                'assets/',
                'api/',
                'cron/',
                'tests/',
            ];
            $requestedPrefixes = $step['source_paths'] ?? null;
            if (!is_array($requestedPrefixes) || $requestedPrefixes === []) {
                throw new RuntimeException('De lijst met bronmappen voor de private applicatie ontbreekt.');
            }
            foreach ($requestedPrefixes as $prefix) {
                if (!is_string($prefix) || !in_array($prefix, $allowedPrefixes, true)) {
                    throw new RuntimeException('Een bronpad is niet toegestaan voor de TEST-deployment.');
                }
            }

            $treeUrl = 'https://api.github.com/repos/ronhenderyckx/DONDEBAILAR/git/trees/' . rawurlencode($sourceRef) . '?recursive=1';
            $treeData = json_decode($curlGet($treeUrl), true, 128, JSON_THROW_ON_ERROR);
            if (!empty($treeData['truncated']) || !is_array($treeData['tree'] ?? null)) {
                throw new RuntimeException('GitHub kon de volledige bestandslijst niet leveren; deployment afgebroken.');
            }

            $deployedCount = 0;
            foreach ($treeData['tree'] as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
                    continue;
                }
                $relativePath = (string) ($entry['path'] ?? '');
                $allowed = false;
                foreach ($requestedPrefixes as $prefix) {
                    if (($prefix === 'config/config.example.php' && $relativePath === $prefix)
                        || ($prefix !== 'config/config.example.php' && str_starts_with($relativePath, $prefix))) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    continue;
                }
                if (str_contains($relativePath, "\0")
                    || preg_match('~(^|/)\.\.?(/|$)~', $relativePath)
                    || str_starts_with($relativePath, '/')
                    || preg_match('~(^|/)(vendor|uploads|storage)(/|$)~i', $relativePath)
                    || $relativePath === 'config/config.local.php') {
                    throw new RuntimeException('Onveilig of beschermd bronbestand aangetroffen: ' . $relativePath);
                }
                $size = (int) ($entry['size'] ?? 0);
                if ($size < 0 || $size > 2097152) {
                    throw new RuntimeException('Bronbestand is groter dan 2 MB of heeft een ongeldige grootte: ' . $relativePath);
                }

                $segments = explode('/', $relativePath);
                foreach ($segments as $segment) {
                    if ($segment === '' || $segment === '.' || $segment === '..') {
                        throw new RuntimeException('Ongeldig bestandspad in GitHub: ' . $relativePath);
                    }
                }

                $destination = $privateRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $parent = dirname($destination);
                $relativeParent = dirname($relativePath);
                $parentSegments = $relativeParent === '.' ? [] : explode('/', $relativeParent);
                $walk = $privateRoot;
                foreach ($parentSegments as $segment) {
                    $walk .= DIRECTORY_SEPARATOR . $segment;
                    if (is_link($walk)) {
                        throw new RuntimeException('Symbolische link aangetroffen in doelpad: ' . $relativePath);
                    }
                    if (!is_dir($walk) && !mkdir($walk, 0755) && !is_dir($walk)) {
                        throw new RuntimeException('Doelmap kon niet worden aangemaakt: ' . $relativePath);
                    }
                }
                if (is_link($destination)) {
                    throw new RuntimeException('Het doelbestand is een symbolische link: ' . $relativePath);
                }

                $encodedPath = implode('/', array_map('rawurlencode', $segments));
                $rawUrl = 'https://raw.githubusercontent.com/ronhenderyckx/DONDEBAILAR/' . rawurlencode($sourceRef) . '/' . $encodedPath;
                $fileBody = $curlGet($rawUrl, 'text/plain');
                if (strlen($fileBody) !== $size) {
                    throw new RuntimeException('Bestandsgrootte komt niet overeen voor: ' . $relativePath);
                }

                $temporary = $destination . '.db-update-' . bin2hex(random_bytes(6));
                if (file_put_contents($temporary, $fileBody, LOCK_EX) === false) {
                    throw new RuntimeException('Tijdelijk bestand kon niet worden geschreven: ' . $relativePath);
                }
                @chmod($temporary, 0644);
                if (!rename($temporary, $destination)) {
                    @unlink($temporary);
                    throw new RuntimeException('Bestand kon niet veilig worden geplaatst: ' . $relativePath);
                }
                $deployedCount++;
            }

            if ($deployedCount === 0) {
                throw new RuntimeException('Geen bestanden gevonden voor de opgegeven private bronmappen.');
            }
            $results[] = 'Private applicatiebestanden bijgewerkt: ' . $deployedCount . ' bestanden.';
            $results[] = 'config/config.local.php, uploads, storage en vendor zijn beschermd en niet overschreven.';
            $executedDeployStep = true;
        }

        if (!$executedDirectoryStep) {
            throw new RuntimeException('Geen geldige create_directories-opdracht gevonden in update-commands.json.');
        }
        if (!$executedDeployStep) {
            throw new RuntimeException('Geen deploy_repository_tree-opdracht gevonden in update-commands.json.');
        }


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
            if (!is_array($step['directories'] ?? null)
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

        $results[] = 'GitHub-bronversie: ' . $sourceRef;
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
