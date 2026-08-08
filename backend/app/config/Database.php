<?php

/**
 * VCMS — Village Cooperative Management System
 * app/config/Database.php — PDO singleton factory
 */

declare(strict_types=1);

namespace App\Config;

use PDO;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Private constructor — use getInstance().
     */
    private function __construct() {}

    /**
     * Returns the single shared PDO connection.
     * Creates it on first call; subsequent calls return the cached instance.
     *
     * @throws RuntimeException if required environment variables are missing.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    /**
     * Resets the singleton instance.
     * Intended for use in tests only.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Reads credentials from environment variables and builds a PDO instance.
     *
     * @throws RuntimeException if DB_NAME, DB_USER, or DB_PASS are not set.
     */
    private static function createConnection(): PDO
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';

        $dbName = getenv('DB_NAME');
        $dbUser = getenv('DB_USER');
        $dbPass = getenv('DB_PASS');

        if ($dbName === false || $dbName === '') {
            throw new RuntimeException(
                'Database configuration error: DB_NAME environment variable is not set.'
            );
        }

        if ($dbUser === false || $dbUser === '') {
            throw new RuntimeException(
                'Database configuration error: DB_USER environment variable is not set.'
            );
        }

        // DB_PASS is allowed to be empty (e.g. local dev with no password),
        // but getenv() must return a value (even an empty string), not false.
        if ($dbPass === false) {
            throw new RuntimeException(
                'Database configuration error: DB_PASS environment variable is not set.'
            );
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        return new PDO($dsn, $dbUser, $dbPass, $options);
    }
}
