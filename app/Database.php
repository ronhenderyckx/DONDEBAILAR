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

        return self::$connection;
    }
}
