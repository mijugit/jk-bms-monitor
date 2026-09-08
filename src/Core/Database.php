<?php

declare(strict_types=1);

namespace JKBMS\Core;

use PDO;
use PDOException;

/**
 * Lightweight PDO singleton wrapper.
 *
 * Connection parameters are read from $_ENV, populated by the front
 * controller from .env.
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host    = $_ENV['DB_HOST']     ?? '127.0.0.1';
        $port    = $_ENV['DB_PORT']     ?? '3306';
        $name    = $_ENV['DB_NAME']     ?? 'jkbms_db';
        $user    = $_ENV['DB_USER']     ?? 'root';
        $pass    = $_ENV['DB_PASS']     ?? '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            self::$instance = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Do not expose credentials in error messages.
            throw new \RuntimeException('Database connection failed.', 0, $e);
        }

        return self::$instance;
    }

    /**
     * Force a fresh connection on the next call to connection().
     *
     * Shared hosting drops idle MySQL connections after a while; long-running
     * CLI scripts should call this before writing after an idle stretch.
     */
    public static function reconnect(): void
    {
        self::$instance = null;
    }
}
