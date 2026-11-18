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
        $results[] = 'Runner-versie: webreus-central-private-root-20261118-1';

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
        $executedPublicDeployStep = false;
        $executedPlaceholderCleanup = false;
        $executedAdminStep = false;
        $executedOrganizerAuditStep = false;
        $executedPastEventColorStep = false;
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

            if (($step['type'] ?? '') === 'remove_public_placeholder') {
                $filename = (string) ($step['filename'] ?? '');
                if ($filename !== 'index.html') {
                    throw new RuntimeException('Alleen de tijdelijke publieke index.html mag door deze opdracht worden verwijderd.');
                }
                $placeholderPath = $documentRoot . DIRECTORY_SEPARATOR . 'index.html';
                if (is_link($placeholderPath)) {
                    throw new RuntimeException('De publieke index.html is een symbolische link; niet verwijderd.');
                }
                if (!is_file($placeholderPath)) {
                    $results[] = 'Tijdelijke index.html: niet aanwezig; niets verwijderd.';
                } else {
                    $placeholderContent = file_get_contents($placeholderPath);
                    if (!is_string($placeholderContent)) {
                        throw new RuntimeException('De bestaande index.html kon niet worden gelezen.');
                    }
                    $normalizedPlaceholder = html_entity_decode($placeholderContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $isKnownPlaceholder = stripos($normalizedPlaceholder, 'DONDEBAILAR') !== false
                        && (
                            stripos($normalizedPlaceholder, 'en construcción') !== false
                            || stripos($normalizedPlaceholder, 'en construcciÃ³n') !== false
                            || stripos($normalizedPlaceholder, 'sección está en construcción') !== false
                            || stripos($normalizedPlaceholder, 'secciÃ³n estÃ¡ en construcciÃ³n') !== false
                        );
                    if (!$isKnownPlaceholder) {
                        throw new RuntimeException('index.html bevat geen herkende tijdelijke DONDEBAILAR-pagina. Bestand is bewust behouden; controleer eerst de inhoud.');
                    }
                    if (!unlink($placeholderPath)) {
                        throw new RuntimeException('De herkende tijdelijke index.html kon niet worden verwijderd.');
                    }
                    $results[] = 'Tijdelijke DONDEBAILAR index.html verwijderd; index.php kan nu de startpagina leveren.';
                }
                $executedPlaceholderCleanup = true;
                continue;
            }

            if (($step['type'] ?? '') === 'deploy_public_repository_tree') {
                $sourceRef = (string) ($manifest['source_ref'] ?? '');
                if ($sourceRef === '' || !preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $sourceRef)) {
                    throw new RuntimeException('De GitHub source_ref is ongeldig voor de publieke deployment.');
                }
                $requestedPrefix = (string) ($step['source_path'] ?? '');
                if ($requestedPrefix !== 'public/') {
                    throw new RuntimeException('De publieke bronmap moet exact public/ zijn.');
                }

                $branchUrl = 'https://api.github.com/repos/ronhenderyckx/DONDEBAILAR/branches/' . rawurlencode($sourceRef);
                $publicBranchData = json_decode($curlGet($branchUrl), true, 128, JSON_THROW_ON_ERROR);
                $publicSourceCommit = (string) ($publicBranchData['commit']['sha'] ?? '');
                $publicTreeSha = (string) ($publicBranchData['commit']['commit']['tree']['sha'] ?? '');
                if (!preg_match('/^[a-f0-9]{40}$/', $publicSourceCommit) || !preg_match('/^[a-f0-9]{40}$/', $publicTreeSha)) {
                    throw new RuntimeException('GitHub leverde geen geldige bronversie voor de publieke deployment.');
                }
                $publicTreeUrl = 'https://api.github.com/repos/ronhenderyckx/DONDEBAILAR/git/trees/' . rawurlencode($publicTreeSha) . '?recursive=1';
                $publicTreeData = json_decode($curlGet($publicTreeUrl), true, 128, JSON_THROW_ON_ERROR);
                if (!empty($publicTreeData['truncated']) || !is_array($publicTreeData['tree'] ?? null)) {
                    throw new RuntimeException('GitHub kon de volledige publieke bestandslijst niet leveren.');
                }

                $publicDeployedCount = 0;
                $protectedPublicFiles = ['install-update.php', 'update-result.php'];
                foreach ($publicTreeData['tree'] as $entry) {
                    if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
                        continue;
                    }
                    $sourcePath = (string) ($entry['path'] ?? '');
                    if (!str_starts_with($sourcePath, 'public/')) {
                        continue;
                    }
                    $relativePath = substr($sourcePath, strlen('public/'));
                    if ($relativePath === '' || in_array($relativePath, $protectedPublicFiles, true)) {
                        continue;
                    }
                    if (str_contains($relativePath, "\0")
                        || str_starts_with($relativePath, '/')
                        || preg_match('~(^|/)\.\.?(/|$)~', $relativePath)
                        || preg_match('~(^|/)(vendor|uploads|storage)(/|$)~i', $relativePath)) {
                        throw new RuntimeException('Onveilig publiek bronpad: ' . $sourcePath);
                    }
                    $size = (int) ($entry['size'] ?? 0);
                    if ($size < 0 || $size > 2097152) {
                        throw new RuntimeException('Publiek bronbestand is groter dan 2 MB of ongeldig: ' . $sourcePath);
                    }
                    $segments = explode('/', $relativePath);
                    foreach ($segments as $segment) {
                        if ($segment === '' || $segment === '.' || $segment === '..') {
                            throw new RuntimeException('Ongeldig publiek bestandspad: ' . $sourcePath);
                        }
                    }

                    $destination = $documentRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                    $parentSegments = count($segments) > 1 ? array_slice($segments, 0, -1) : [];
                    $walk = $documentRoot;
                    foreach ($parentSegments as $segment) {
                        $walk .= DIRECTORY_SEPARATOR . $segment;
                        if (is_link($walk)) {
                            throw new RuntimeException('Symbolische link in publieke doelmap: ' . $relativePath);
                        }
                        if (!is_dir($walk) && !mkdir($walk, 0755) && !is_dir($walk)) {
                            throw new RuntimeException('Publieke doelmap kon niet worden aangemaakt: ' . $relativePath);
                        }
                    }
                    if (is_link($destination)) {
                        throw new RuntimeException('Publiek doelbestand is een symbolische link: ' . $relativePath);
                    }

                    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $sourcePath)));
                    $rawUrl = 'https://raw.githubusercontent.com/ronhenderyckx/DONDEBAILAR/' . rawurlencode($publicSourceCommit) . '/' . $encodedPath;
                    $fileBody = $curlGet($rawUrl, 'text/plain');
                    if (strlen($fileBody) !== $size) {
                        throw new RuntimeException('Publieke bestandsgrootte komt niet overeen: ' . $relativePath);
                    }

                    $temporary = $destination . '.db-update-' . bin2hex(random_bytes(6));
                    if (file_put_contents($temporary, $fileBody, LOCK_EX) === false) {
                        throw new RuntimeException('Tijdelijk publiek bestand kon niet worden geschreven: ' . $relativePath);
                    }
                    @chmod($temporary, 0644);
                    if (!rename($temporary, $destination)) {
                        @unlink($temporary);
                        throw new RuntimeException('Publiek bestand kon niet veilig worden geplaatst: ' . $relativePath);
                    }
                    $publicDeployedCount++;
                }
                if ($publicDeployedCount === 0) {
                    throw new RuntimeException('Geen bestanden gevonden in de GitHub-map public/.');
                }
                $results[] = 'Publieke TEST-bestanden bijgewerkt: ' . $publicDeployedCount . ' bestanden.';
                $results[] = 'install-update.php en update-result.php zijn beschermd en niet overschreven.';
                $results[] = 'Publieke GitHub-bronversie: ' . $publicSourceCommit;
                $executedPublicDeployStep = true;
                continue;
            }

            if (($step['type'] ?? '') === 'ensure_region_past_event_color') {
                $bootstrapFile = $privateRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
                if (!is_file($bootstrapFile)) {
                    throw new RuntimeException('De applicatie-bootstrap ontbreekt; regiokleurmigratie afgebroken.');
                }
                require_once $bootstrapFile;
                $pdo = Database::connection();
                $columnQuery = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
                );
                $columnQuery->execute(['table_name' => 'region_appearance', 'column_name' => 'past_event_color']);
                if ((int) $columnQuery->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE region_appearance ADD COLUMN past_event_color CHAR(7) NOT NULL DEFAULT '#777777' AFTER filter_text_color");
                    $results[] = 'Database: region_appearance.past_event_color toegevoegd.';
                } else {
                    $results[] = 'Database: region_appearance.past_event_color bestond al.';
                }
                $executedPastEventColorStep = true;
                continue;
            }

            if (($step['type'] ?? '') === 'ensure_organizer_audit_columns') {
                $bootstrapFile = $privateRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
                if (!is_file($bootstrapFile)) {
                    throw new RuntimeException('De applicatie-bootstrap ontbreekt; organisatormigratie afgebroken.');
                }
                require_once $bootstrapFile;
                if (!class_exists('Database') || !method_exists('Database', 'connection')) {
                    throw new RuntimeException('De databaseverbinding is niet beschikbaar voor de organisatormigratie.');
                }

                $pdo = Database::connection();
                $columnExists = static function (PDO $pdo, string $column): bool {
                    $query = $pdo->prepare(
                        'SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
                    );
                    $query->execute(['table_name' => 'organizers', 'column_name' => $column]);
                    return (int) $query->fetchColumn() > 0;
                };
                $indexExists = static function (PDO $pdo, string $index): bool {
                    $query = $pdo->prepare(
                        'SELECT COUNT(*) FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name'
                    );
                    $query->execute(['table_name' => 'organizers', 'index_name' => $index]);
                    return (int) $query->fetchColumn() > 0;
                };

                if (!$columnExists($pdo, 'created_by')) {
                    $pdo->exec('ALTER TABLE organizers ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER notes');
                    $results[] = 'Database: kolom organizers.created_by toegevoegd.';
                } else {
                    $results[] = 'Database: organizers.created_by bestond al.';
                }
                if (!$columnExists($pdo, 'updated_by')) {
                    $pdo->exec('ALTER TABLE organizers ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by');
                    $results[] = 'Database: kolom organizers.updated_by toegevoegd.';
                } else {
                    $results[] = 'Database: organizers.updated_by bestond al.';
                }
                if (!$indexExists($pdo, 'idx_organizers_created_by')) {
                    $pdo->exec('ALTER TABLE organizers ADD KEY idx_organizers_created_by (created_by)');
                }
                if (!$indexExists($pdo, 'idx_organizers_updated_by')) {
                    $pdo->exec('ALTER TABLE organizers ADD KEY idx_organizers_updated_by (updated_by)');
                }
                $executedOrganizerAuditStep = true;
                continue;
            }

            if (($step['type'] ?? '') === 'ensure_platform_admin') {
                $email = strtolower(trim((string) ($step['email'] ?? '')));
                $displayName = trim((string) ($step['display_name'] ?? 'Ron'));
                $language = (string) ($step['preferred_language'] ?? 'nl');

                if ($email !== 'ron@henderyckx.be' || $displayName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('De platform-admin opdracht bevat ongeldige accountgegevens.');
                }
                if (!in_array($language, ['nl', 'en', 'es', 'fr'], true)) {
                    throw new RuntimeException('De voorkeurstaal voor de platform-admin is ongeldig.');
                }

                $bootstrapFile = $privateRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
                if (!is_file($bootstrapFile)) {
                    throw new RuntimeException('De applicatie-bootstrap ontbreekt; de database kan niet veilig worden geopend.');
                }
                require_once $bootstrapFile;
                if (!class_exists('Database') || !method_exists('Database', 'connection')) {
                    throw new RuntimeException('De bestaande databaseverbinding van DONDEBAILAR is niet beschikbaar.');
                }

                $pdo = Database::connection();
                $pdo->beginTransaction();
                try {
                    $findUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                    $findUser->execute(['email' => $email]);
                    $existingUser = $findUser->fetch(PDO::FETCH_ASSOC);

                    if (is_array($existingUser)) {
                        $updateUser = $pdo->prepare(
                            "UPDATE users
                             SET display_name = :display_name,
                                 preferred_language = :preferred_language,
                                 platform_role = 'admin',
                                 status = 'active',
                                 email_verified_at = COALESCE(email_verified_at, NOW()),
                                 updated_at = NOW()
                             WHERE id = :id"
                        );
                        $updateUser->execute([
                            'display_name' => $displayName,
                            'preferred_language' => $language,
                            'id' => (int) $existingUser['id'],
                        ]);
                        $pdo->commit();
                        $results[] = 'Platform-admin hersteld voor ' . $email . '; bestaand wachtwoord behouden.';
                    } else {
                        $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
                        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                        if (!is_string($passwordHash) || $passwordHash === '') {
                            throw new RuntimeException('Het tijdelijke wachtwoord kon niet veilig worden aangemaakt.');
                        }
                        $insertUser = $pdo->prepare(
                            "INSERT INTO users
                                (email, password_hash, display_name, preferred_language, platform_role, status, email_verified_at, created_at, updated_at)
                             VALUES
                                (:email, :password_hash, :display_name, :preferred_language, 'admin', 'active', NOW(), NOW(), NOW())"
                        );
                        $insertUser->execute([
                            'email' => $email,
                            'password_hash' => $passwordHash,
                            'display_name' => $displayName,
                            'preferred_language' => $language,
                        ]);
                        $pdo->commit();
                        $results[] = 'Nieuwe platform-admin aangemaakt: ' . $email;
                        $results[] = 'Eenmalig tijdelijk wachtwoord (bewaar dit veilig): ' . $temporaryPassword;
                        $results[] = 'Log daarna in en wijzig het wachtwoord zodra de accountinstellingen dit toelaten.';
                    }
                } catch (Throwable $adminError) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $adminError;
                }

                $executedAdminStep = true;
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

            $branchUrl = 'https://api.github.com/repos/ronhenderyckx/DONDEBAILAR/branches/' . rawurlencode($sourceRef);
            $branchData = json_decode($curlGet($branchUrl), true, 128, JSON_THROW_ON_ERROR);
            $sourceCommit = (string) ($branchData['commit']['sha'] ?? '');
            $treeSha = (string) ($branchData['commit']['commit']['tree']['sha'] ?? '');
            if (!preg_match('/^[a-f0-9]{40}$/', $sourceCommit) || !preg_match('/^[a-f0-9]{40}$/', $treeSha)) {
                throw new RuntimeException('De GitHub-branch leverde geen geldige commit- en treeversie op.');
            }
            $treeUrl = 'https://api.github.com/repos/ronhenderyckx/DONDEBAILAR/git/trees/' . rawurlencode($treeSha) . '?recursive=1';
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
                $rawUrl = 'https://raw.githubusercontent.com/ronhenderyckx/DONDEBAILAR/' . rawurlencode($sourceCommit) . '/' . $encodedPath;
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
        if (!$executedPublicDeployStep) {
            throw new RuntimeException('Geen deploy_public_repository_tree-opdracht gevonden in update-commands.json.');
        }
        if (!$executedPlaceholderCleanup) {
            throw new RuntimeException('Geen controle-opdracht voor de tijdelijke publieke index.html gevonden.');
        }
        if (!$executedPastEventColorStep) {
            throw new RuntimeException('Geen opdracht gevonden om de kleurinstelling voor afgelopen evenementen te controleren.');
        }
        if (!$executedOrganizerAuditStep) {
            throw new RuntimeException('Geen opdracht gevonden om de organisatorauditkolommen te controleren.');
        }
        if (!$executedAdminStep) {
            throw new RuntimeException('Geen opdracht gevonden om de platform-admin te controleren of aan te maken.');
        }


        $results[] = 'GitHub-bronversie: ' . $sourceCommit;
        $results[] = 'GitHub-opdrachtenbestand gelezen: ' . (string) ($manifest['release'] ?? 'onbekende release');
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
