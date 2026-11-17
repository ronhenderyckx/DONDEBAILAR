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
$platformAdminPage = true;
$projectRoot = dirname(__DIR__, 2);
$messages = [];
$success = false;

function runUpdateGit(array $arguments, string $workingDirectory): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is niet beschikbaar op de server.');
    }

    // Gebruik op de Raspberry Pi de bestaande repository-deploysleutel.
    // Deze sleutel werkt zonder interactieve passphrase en heeft alleen toegang
    // tot deze repository. De persoonlijke schrijfsleutel blijft ongemoeid.
    $deployKey = '/home/pi/.ssh/dondebailar_deploy';
    if (is_file($deployKey)) {
        $sshCommand = 'ssh -i ' . escapeshellarg($deployKey)
            . ' -o IdentitiesOnly=yes -o BatchMode=yes';
        $command = array_merge(
            ['git', '-c', 'core.sshCommand=' . $sshCommand],
            $arguments
        );
    } else {
        $command = array_merge(['git'], $arguments);
    }
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

/**
 * Laat testaccounts hetzelfde e-mailadres delen.
 * Verwijdert uitsluitend een enkelvoudige UNIQUE-index op users.email.
 */
function migrateDuplicateUserEmails(PDO $db): void
{
    // SHOW INDEX leest de daadwerkelijke indexdefinitie van de actieve database.
    $rows = $db->query('SHOW INDEX FROM `users`')->fetchAll(PDO::FETCH_ASSOC);
    $indexes = [];

    foreach ($rows as $row) {
        $name = (string) ($row['Key_name'] ?? '');
        if ($name === '' || !isset($row['Column_name'])) {
            continue;
        }

        $indexes[$name][] = [
            'column' => (string) $row['Column_name'],
            'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
        ];
    }

    foreach ($indexes as $indexName => $columns) {
        // Alleen een UNIQUE-index die uitsluitend uit users.email bestaat verwijderen.
        if (
            count($columns) !== 1
            || $columns[0]['column'] !== 'email'
            || !$columns[0]['unique']
            || !preg_match('/^[A-Za-z0-9_]+$/', $indexName)
        ) {
            continue;
        }

        $db->exec('ALTER TABLE `users` DROP INDEX `' . $indexName . '`');
    }
}


/**
 * Organisatoren zijn platformbreed en hebben geen eigen regio.
 * Verwijder de eerder toegevoegde, foutieve region_id-kolom indien aanwezig.
 */
function removeOrganizerRegionColumn(PDO $db): void
{
    $column = $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'organizers'
           AND COLUMN_NAME = 'region_id'"
    )->fetchColumn();

    if ((int) $column === 0) {
        return;
    }

    $index = $db->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'organizers'
           AND INDEX_NAME = 'idx_organizers_region_id'"
    )->fetchColumn();

    if ((int) $index > 0) {
        $db->exec("DROP INDEX idx_organizers_region_id ON organizers");
    }

    $db->exec("ALTER TABLE organizers DROP COLUMN region_id");
}



/**
 * Organisator is optioneel voor evenementen, ook bij CSV-import.
 * De bestaande organisator-koppeling blijft behouden waar die is ingevuld.
 */
function migrateOptionalEventOrganizer(PDO $db): void
{
    $column = $db->prepare(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'events'
           AND COLUMN_NAME = 'organizer_id'"
    );
    $column->execute();
    $nullable = $column->fetchColumn();
    if ($nullable === false) {
        throw new RuntimeException('Kolom events.organizer_id bestaat niet.');
    }
    if ($nullable !== 'YES') {
        $db->exec("ALTER TABLE events MODIFY organizer_id BIGINT UNSIGNED NULL");
    }
}

/**
 * Voeg het aparte hashtagveld aan evenementen toe.
 * De controle maakt de migratie veilig om bij iedere UPDATE uit te voeren.
 */
function migrateEventHashtags(PDO $db): void
{
    $column = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'events'
           AND COLUMN_NAME = 'hashtags'"
    );
    $column->execute();
    if ((int) $column->fetchColumn() === 0) {
        $db->exec("ALTER TABLE events ADD COLUMN hashtags VARCHAR(500) NULL AFTER description");
    }
}


/**
 * Voeg het afzonderlijke veld Extra info aan evenementen toe.
 * Idempotent: veilig bij iedere platformupdate uit te voeren.
 */
function migrateEventExtraInfo(PDO $db): void
{
    $check = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'events'
           AND COLUMN_NAME = 'extra_info'"
    );
    $check->execute();
    if ((int) $check->fetchColumn() === 0) {
        $db->exec("ALTER TABLE events ADD COLUMN extra_info MEDIUMTEXT NULL AFTER description");
    }
}


/**
 * Bewaar herhalingen als een reeks met afzonderlijke evenementrecords.
 * De migratie is idempotent en wordt door de website-UPDATE uitgevoerd.
 */
function migrateEventRecurrence(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS event_recurrence_series (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        frequency ENUM('weekly','monthly') NOT NULL,
        ends_on DATE NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_recurrence_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events'"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('recurrence_series_id', $columns, true)) {
        $db->exec("ALTER TABLE events ADD COLUMN recurrence_series_id BIGINT UNSIGNED NULL");
    }
    if (!in_array('recurrence_number', $columns, true)) {
        $db->exec("ALTER TABLE events ADD COLUMN recurrence_number INT UNSIGNED NULL");
    }

    $index = $db->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events'
           AND INDEX_NAME = 'idx_events_recurrence_series'"
    )->fetchColumn();
    if ((int) $index === 0) {
        $db->exec("CREATE INDEX idx_events_recurrence_series ON events (recurrence_series_id, recurrence_number)");
    }

    $constraint = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'events'
           AND CONSTRAINT_NAME = 'fk_events_recurrence_series'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchColumn();
    if ((int) $constraint === 0) {
        $db->exec("ALTER TABLE events
            ADD CONSTRAINT fk_events_recurrence_series
            FOREIGN KEY (recurrence_series_id) REFERENCES event_recurrence_series(id)
            ON DELETE SET NULL");
    }
}


/**
 * Maak de berichtenfunctie per evenement aan.
 * De controle maakt deze database-upgrade veilig om bij iedere UPDATE uit te voeren.
 */
function migrateEventMessages(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS event_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        parent_id BIGINT UNSIGNED NULL,
        message TEXT NOT NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_event_messages_event (event_id, created_at),
        KEY idx_event_messages_parent (parent_id),
        KEY idx_event_messages_user (user_id),
        CONSTRAINT fk_event_messages_event FOREIGN KEY (event_id)
            REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}


/**
 * Meldingen voor gebruikers die in evenementberichten worden genoemd.
 */
function migrateUserNotifications(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        event_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NOT NULL,
        notification_type VARCHAR(40) NOT NULL DEFAULT 'mention',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_notification_message (user_id, message_id),
        KEY idx_user_notifications_unread (user_id, is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}


/**
 * Eenmalige, tijdelijke tokens voor wachtwoordherstel.
 */
function migrateRegionAppearance(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS region_appearance (
        region_id BIGINT UNSIGNED NOT NULL,
        primary_color CHAR(7) NOT NULL DEFAULT '#E23A2C',
        primary_dark CHAR(7) NOT NULL DEFAULT '#8F211B',
        accent_color CHAR(7) NOT NULL DEFAULT '#F6B22E',
        secondary_color CHAR(7) NOT NULL DEFAULT '#1FA898',
        tertiary_color CHAR(7) NOT NULL DEFAULT '#F4802A',
        header_color CHAR(7) NOT NULL DEFAULT '#222222',
        background_color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
        text_color CHAR(7) NOT NULL DEFAULT '#222222',
        weekday_colors TEXT NULL,
        logo_path VARCHAR(255) NULL,
        PRIMARY KEY (region_id),
        CONSTRAINT fk_region_appearance_region FOREIGN KEY (region_id)
            REFERENCES regions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // CREATE TABLE IF NOT EXISTS wijzigt een reeds bestaande tabel niet.
    // Voeg daarom ontbrekende kolommen ook bij bestaande installaties toe.
    $columns = [
        'primary_color' => "CHAR(7) NOT NULL DEFAULT '#E23A2C'",
        'primary_dark' => "CHAR(7) NOT NULL DEFAULT '#8F211B'",
        'accent_color' => "CHAR(7) NOT NULL DEFAULT '#F6B22E'",
        'secondary_color' => "CHAR(7) NOT NULL DEFAULT '#1FA898'",
        'tertiary_color' => "CHAR(7) NOT NULL DEFAULT '#F4802A'",
        'header_color' => "CHAR(7) NOT NULL DEFAULT '#222222'",
        'menu_color' => "CHAR(7) NOT NULL DEFAULT '#FFFFFF'",
        'organizer_color' => "CHAR(7) NOT NULL DEFAULT '#E23A2C'",
        'filter_hover_color' => "CHAR(7) NOT NULL DEFAULT '#E23A2C'",
        'filter_bar_color' => "CHAR(7) NOT NULL DEFAULT '#FFFFFF'",
        'filter_text_color' => "CHAR(7) NOT NULL DEFAULT '#222222'",
        'search_button_color' => "CHAR(7) NOT NULL DEFAULT '#E23A2C'",
        'back_top_color' => "CHAR(7) NOT NULL DEFAULT '#E23A2C'",
        'search_field_color' => "CHAR(7) NOT NULL DEFAULT '#FFFFFF'",
        'search_text_color' => "CHAR(7) NOT NULL DEFAULT '#222222'",
        'footer_color' => "CHAR(7) NOT NULL DEFAULT '#FFFFFF'",
        'background_color' => "CHAR(7) NOT NULL DEFAULT '#FFFFFF'",
        'text_color' => "CHAR(7) NOT NULL DEFAULT '#222222'",
        'weekday_colors' => "TEXT NULL",
        'logo_path' => "VARCHAR(255) NULL",
    ];

    $check = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'region_appearance'
           AND COLUMN_NAME = :column_name"
    );

    foreach ($columns as $name => $definition) {
        $check->execute(['column_name' => $name]);
        if ((int) $check->fetchColumn() === 0) {
            $db->exec("ALTER TABLE region_appearance ADD COLUMN `{$name}` {$definition}");
        }
    }
}

function migrateUserAgendaPreferences(PDO $db): void
{
    // Gebruik bewust geen foreign keys: de ID-kolommen van bestaande
    // installaties kunnen verschillende integergroottes hebben.
    $db->exec("CREATE TABLE IF NOT EXISTS user_agenda_preferences (
        user_id BIGINT UNSIGNED NOT NULL,
        region_id BIGINT UNSIGNED NULL,
        active_filter ENUM('organizer','city') NOT NULL DEFAULT 'organizer',
        organizer_id BIGINT UNSIGNED NULL,
        city VARCHAR(120) NULL,
        sound_muted TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id),
        KEY idx_user_agenda_preferences_region (region_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function migrateUserEmailVerificationTokens(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS user_email_verification_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_email_verification_hash (token_hash),
        KEY idx_user_email_verification_user (user_id),
        KEY idx_user_email_verification_expiry (expires_at),
        CONSTRAINT fk_user_email_verification_user FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function migratePasswordResetTokens(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_password_reset_token_hash (token_hash),
        KEY idx_password_reset_email_created (email, created_at),
        KEY idx_password_reset_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}


/**
 * Universele registratie van aanmaak en laatste wijziging.
 * Alle applicatietabellen krijgen dezelfde vier auditvelden. Database-triggers
 * vullen ze automatisch, zodat ook bestaande formulieren en API-acties gevolgd worden.
 */
function migrateAuditTracking(PDO $db): void
{
    $tables = $db->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $tableName) {
        $tableName = (string) $tableName;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            continue;
        }

        $columns = $db->prepare(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name"
        );
        $columns->execute(['table_name' => $tableName]);
        $existing = array_fill_keys($columns->fetchAll(PDO::FETCH_COLUMN), true);

        $quotedTable = '`' . $tableName . '`';
        $definitions = [
            'created_at' => 'DATETIME NULL',
            'created_by' => 'INT UNSIGNED NULL',
            'updated_at' => 'DATETIME NULL',
            'updated_by' => 'INT UNSIGNED NULL',
        ];

        foreach ($definitions as $columnName => $definition) {
            if (!isset($existing[$columnName])) {
                $db->exec(
                    'ALTER TABLE ' . $quotedTable .
                    ' ADD COLUMN `' . $columnName . '` ' . $definition
                );
            }
        }

        $suffix = substr(hash('sha256', $tableName), 0, 12);
        $insertTrigger = 'db_audit_bi_' . $suffix;
        $updateTrigger = 'db_audit_bu_' . $suffix;

        // Verwijder eerst onze eigen triggers, zodat het eenmalig aanvullen
        // van oude datums niet als een gebruikerswijziging wordt geregistreerd.
        $db->exec('DROP TRIGGER IF EXISTS `' . $insertTrigger . '`');
        $db->exec('DROP TRIGGER IF EXISTS `' . $updateTrigger . '`');

        $db->exec(
            'UPDATE ' . $quotedTable .
            ' SET `created_at` = COALESCE(`created_at`, NOW()),' .
            ' `updated_at` = COALESCE(`updated_at`, `created_at`, NOW())' .
            ' WHERE `created_at` IS NULL OR `updated_at` IS NULL'
        );

        $db->exec(
            'CREATE TRIGGER `' . $insertTrigger . '` BEFORE INSERT ON ' . $quotedTable .
            ' FOR EACH ROW SET' .
            ' NEW.created_at = COALESCE(NEW.created_at, NOW()),' .
            ' NEW.created_by = COALESCE(NEW.created_by, @dondebailar_user_id),' .
            ' NEW.updated_at = COALESCE(NEW.updated_at, NOW()),' .
            ' NEW.updated_by = COALESCE(NEW.updated_by, @dondebailar_user_id)'
        );

        $db->exec(
            'CREATE TRIGGER `' . $updateTrigger . '` BEFORE UPDATE ON ' . $quotedTable .
            ' FOR EACH ROW SET' .
            ' NEW.updated_at = NOW(),' .
            ' NEW.updated_by = COALESCE(@dondebailar_user_id, NEW.updated_by)'
        );
    }
}

/**
 * Voorkom dat twee beheerders gelijktijdig een update starten.
 */
function acquireUpdateLock()
{
    $lockPath = sys_get_temp_dir() . '/dondebailar-update.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Er loopt al een DONDEBAILAR-update. Probeer het straks opnieuw.');
    }

    return $handle;
}

/**
 * Maak vóór codewijzigingen en database-migraties een afgeschermde SQL-back-up.
 * Databasewachtwoorden worden alleen in een tijdelijk bestand met rechten 0600 gezet.
 */
function createUpdateDatabaseBackup(array $config, string $projectRoot, string $stamp): string
{
    $database = $config['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('Databaseconfiguratie ontbreekt; update afgebroken zonder wijzigingen.');
    }

    foreach (['host', 'port', 'name', 'username', 'password'] as $key) {
        if (!array_key_exists($key, $database)) {
            throw new RuntimeException('Databaseconfiguratie is onvolledig; update afgebroken zonder wijzigingen.');
        }
    }

    $dumpBinary = null;
    foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/bin/mysqldump'] as $candidate) {
        if (is_executable($candidate)) {
            $dumpBinary = $candidate;
            break;
        }
    }
    if ($dumpBinary === null) {
        throw new RuntimeException('mysqldump is niet beschikbaar. Installeer de MySQL-client voordat je bijwerkt.');
    }

    $backupDirectory = $projectRoot . '/storage/backups/updates';
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('De map voor databaseback-ups kon niet worden aangemaakt.');
    }
    @chmod($backupDirectory, 0700);

    $backupFile = $backupDirectory . '/dondebailar-db-' . $stamp . '.sql';
    $defaultsFile = tempnam(sys_get_temp_dir(), 'db-update-');
    if ($defaultsFile === false) {
        throw new RuntimeException('Tijdelijk database-instellingenbestand kon niet worden aangemaakt.');
    }

    $escapeOption = static function ($value): string {
        return str_replace(
            ["\\\\", '"', "\n", "\r"],
            ["\\\\\\\\", '\\"', '\\n', '\\r'],
            (string) $value
        );
    };

    $defaults = "[client]\n"
        . 'host="' . $escapeOption($database['host']) . "\"\n"
        . 'port="' . $escapeOption($database['port']) . "\"\n"
        . 'user="' . $escapeOption($database['username']) . "\"\n"
        . 'password="' . $escapeOption($database['password']) . "\"\n"
        . "default-character-set=utf8mb4\n";

    try {
        if (file_put_contents($defaultsFile, $defaults, LOCK_EX) === false) {
            throw new RuntimeException('Tijdelijke database-instellingen konden niet worden geschreven.');
        }
        if (!chmod($defaultsFile, 0600)) {
            throw new RuntimeException('Tijdelijke database-instellingen konden niet veilig worden afgeschermd.');
        }

        $command = [
            $dumpBinary,
            '--defaults-extra-file=' . $defaultsFile,
            '--single-transaction',
            '--quick',
            '--triggers',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--result-file=' . $backupFile,
            (string) $database['name'],
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $projectRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('mysqldump kon niet worden gestart.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            @unlink($backupFile);
            $detail = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
            throw new RuntimeException(
                'Database-back-up mislukt; de update is niet gestart.'
                . ($detail !== '' ? ' ' . $detail : '')
            );
        }

        clearstatcache(true, $backupFile);
        if (!is_file($backupFile) || filesize($backupFile) < 128) {
            @unlink($backupFile);
            throw new RuntimeException('Database-back-up is leeg of onvolledig; de update is niet gestart.');
        }

        @chmod($backupFile, 0600);
        return $backupFile;
    } finally {
        @unlink($defaultsFile);
    }
}

/**
 * Bewaar ook de huidige Git-toestand vóór de fast-forward-update.
 */
function createUpdateGitBackup(string $projectRoot, string $backupDirectory, string $stamp): string
{
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('De map voor Git-back-ups kon niet worden aangemaakt.');
    }
    @chmod($backupDirectory, 0700);

    $bundle = $backupDirectory . '/dondebailar-code-' . $stamp . '.bundle';
    runRequiredGit(['bundle', 'create', $bundle, '--all'], $projectRoot);
    clearstatcache(true, $bundle);
    if (!is_file($bundle) || filesize($bundle) < 128) {
        @unlink($bundle);
        throw new RuntimeException('Git-back-up kon niet worden gecontroleerd; de update is niet gestart.');
    }

    @chmod($bundle, 0600);
    return $bundle;
}

try {
    set_time_limit(600);
    $updateLock = acquireUpdateLock();

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

    // Test eerst de databaseverbinding en maak beide back-ups vóór git pull of migraties.
    $db = Database::connection();
    $backupStamp = date('Ymd-His');
    $backupDirectory = $projectRoot . '/storage/backups/updates';
    $databaseBackup = createUpdateDatabaseBackup($GLOBALS['config'], $projectRoot, $backupStamp);
    $messages[] = 'Databaseback-up gecontroleerd: ' . basename($databaseBackup);

    $gitBackup = createUpdateGitBackup($projectRoot, $backupDirectory, $backupStamp);
    $messages[] = 'Codeback-up gecontroleerd: ' . basename($gitBackup);

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

    // Ook uitvoeren wanneer de code al up-to-date is, bijvoorbeeld na een
    // eerdere update waarbij de database-aanpassing nog niet kon worden afgerond.
    removeOrganizerRegionColumn($db);
    $messages[] = 'Database: organisatoren zijn platformbreed; foutieve regio-koppeling verwijderd indien aanwezig.';

    $db->exec("CREATE TABLE IF NOT EXISTS event_guest_attendances (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT UNSIGNED NOT NULL,
        visitor_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_guest_attendance (event_id, visitor_hash),
        KEY idx_event_guest_attendance_event (event_id),
        CONSTRAINT fk_event_guest_attendance_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $messages[] = 'Database: anonieme dans-aanmeldingen zijn gecontroleerd.';

    migrateOptionalEventOrganizer($db);
    $messages[] = 'Database: organisator bij evenementen is optioneel gemaakt.';

    migrateEventHashtags($db);
    $messages[] = 'Database: apart hashtagveld voor evenementen is gecontroleerd.';

    migrateEventExtraInfo($db);
    $messages[] = 'Database: apart veld Extra info voor evenementen is gecontroleerd.';

    migrateEventMessages($db);
    $messages[] = 'Database: berichten per evenement zijn gecontroleerd.';

    migrateEventRecurrence($db);
    $messages[] = 'Database: wekelijkse en maandelijkse evenementreeksen zijn gecontroleerd.';

    migrateUserNotifications($db);
    $messages[] = 'Database: meldingen bij @vermeldingen zijn gecontroleerd.';

    migrateUserEmailVerificationTokens($db);
    $messages[] = 'Database: e-mailbevestiging bij nieuwe registraties is gecontroleerd.';

    migratePasswordResetTokens($db);
    $messages[] = 'Database: wachtwoordherstel is gecontroleerd.';

    migrateRegionAppearance($db);
    $messages[] = 'Database: kleuren en logo per regio zijn gecontroleerd.';

    migrateUserAgendaPreferences($db);
    $messages[] = 'Database: persoonlijke agenda-instellingen voor gebruikers zijn gecontroleerd.';

    migrateDuplicateUserEmails($db);
    migrateAuditTracking($db);
    $messages[] = 'Database: aanmaak- en wijzigingsregistratie is gecontroleerd voor alle tabellen.';

    $shortCommit = runRequiredGit(['rev-parse', '--short', 'HEAD'], $projectRoot);
    $messages[] = 'Geïnstalleerde versie: ' . $shortCommit;
} catch (Throwable $exception) {
    $success = false;
    http_response_code(500);
    $messages[] = 'UPDATE NIET VOLLEDIG UITGEVOERD';
    $messages[] = $exception->getMessage();
} finally {
    if (isset($updateLock) && is_resource($updateLock)) {
        flock($updateLock, LOCK_UN);
        fclose($updateLock);
    }
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
            <a class="button button--primary" href="/admin/index.php">
                Terug naar platformbeheer
            </a>
        </p>
    </section>
</section>

<?php require dirname(__DIR__, 2) . '/app/views/footer.php'; ?>
