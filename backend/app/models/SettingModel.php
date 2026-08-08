<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/SettingModel.php — Key/value configuration store
 *
 * Requirements covered: 5.1, 12.1, 14.4
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class SettingModel
{
    /** Keys the API is allowed to write. Anything else is rejected. */
    public const WRITABLE_KEYS = [
        'cooperative_name',
        'fixed_monthly_saving',
        'default_language',
    ];

    /** Per-request memo so repeated get() calls hit the DB once. */
    private static array $cache = [];

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Fetch a single setting value, or $default when the key is absent.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        self::$cache[$key] = $value === false ? $default : (string) $value;

        return self::$cache[$key];
    }

    /**
     * Fetch a setting coerced to a float — used for monetary settings.
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (float) $value;
    }

    /**
     * Fetch a setting coerced to an int.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    /**
     * Return every setting as an ordered list of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT setting_key, setting_value, description
               FROM settings
              ORDER BY setting_key ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return every setting as a key => value map.
     *
     * @return array<string, string>
     */
    public static function map(): array
    {
        $map = [];

        foreach (self::all() as $row) {
            $map[$row['setting_key']] = $row['setting_value'];
        }

        return $map;
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Upsert a setting value.
     */
    public static function set(string $key, string $value, ?int $updatedBy = null): void
    {
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_by)
             VALUES (:key, :value, :updated_by)
             ON DUPLICATE KEY UPDATE setting_value = :value2, updated_by = :updated_by2'
        );
        $stmt->execute([
            ':key'          => $key,
            ':value'        => $value,
            ':updated_by'   => $updatedBy,
            ':value2'       => $value,
            ':updated_by2'  => $updatedBy,
        ]);

        self::$cache[$key] = $value;
    }

    /**
     * Atomically increment an integer counter setting and return the new value.
     *
     * The row is locked with FOR UPDATE, so concurrent callers are serialised
     * and each receives a distinct number. The caller must already be inside a
     * transaction for the lock to be held meaningfully.
     */
    public static function incrementCounter(string $key): int
    {
        $pdo = Database::getInstance();

        $lock = $pdo->prepare(
            'SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE'
        );
        $lock->execute([$key]);
        $current = $lock->fetchColumn();

        if ($current === false) {
            // First use — create the counter row at 0 before incrementing.
            $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)'
            )->execute([$key, '0']);
            $current = '0';
        }

        $next = (int) $current + 1;

        $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')
            ->execute([(string) $next, $key]);

        self::$cache[$key] = (string) $next;

        return $next;
    }

    /**
     * Drop the in-memory cache. Intended for tests and long-running scripts.
     */
    public static function resetCache(): void
    {
        self::$cache = [];
    }
}
