<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $db = $GLOBALS['config']['database'] ?? null;
        if (!is_array($db)) {
            throw new RuntimeException('Database configuration is missing.');
        }

        foreach (['host', 'port', 'name', 'username', 'password', 'charset'] as $key) {
            if (!array_key_exists($key, $db)) {
                throw new RuntimeException('Database configuration is incomplete.');
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            (int) $db['port'],
            $db['name'],
            $db['charset']
        );

        self::$connection = new PDO($dsn, (string) $db['username'], (string) $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // De triggers gebruiken deze sessiewaarde om de ingelogde gebruiker
        // automatisch als maker/wijziger te registreren.
        $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
        $auditUser = ($userId !== false && $userId !== null && $userId > 0)
            ? (int) $userId
            : null;
        $auditStatement = self::$connection->prepare(
            'SET @dondebailar_user_id = :user_id'
        );
        $auditStatement->bindValue(':user_id', $auditUser, $auditUser === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $auditStatement->execute();

        return self::$connection;
    }
}
