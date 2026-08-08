<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/AdminModel.php — DB queries for admin records
 *
 * Requirements covered: 1.1, 1.4, 1.9, 1.10, 15.1
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class AdminModel
{
    // -------------------------------------------------------------------------
    // Whitelisted fields for update() to prevent mass-assignment
    // -------------------------------------------------------------------------
    private const UPDATE_WHITELIST = ['name', 'username', 'phone', 'role', 'status', 'updated_by'];

    // =========================================================================
    // READ methods
    // =========================================================================

    /**
     * Find an admin by username.
     *
     * @param  string     $username
     * @return array|null Full row array, or null if not found.
     */
    public static function findByUsername(string $username): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT * FROM admins WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find an admin by primary key.
     *
     * @param  int        $id
     * @return array|null Full row array, or null if not found.
     */
    public static function findById(int $id): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT * FROM admins WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find an admin by a valid (non-expired) remember token.
     * Expired tokens are treated as not found.
     *
     * @param  string     $token
     * @return array|null Full row array, or null if not found / expired.
     */
    public static function findByRememberToken(string $token): ?array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT * FROM admins
              WHERE remember_token = ?
                AND remember_token_expires > NOW()
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Return all admins ordered by name, never exposing password_hash.
     *
     * @return array Array of row arrays.
     */
    public static function listAll(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'SELECT id, name, username, phone, role, status, created_at
               FROM admins
              ORDER BY name ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check whether a username is already taken.
     * Optionally exclude a specific admin ID (useful when editing).
     *
     * @param  string   $username
     * @param  int|null $excludeId Admin ID to exclude from the check.
     * @return bool     True if the username exists.
     */
    public static function usernameExists(string $username, ?int $excludeId = null): bool
    {
        $pdo = Database::getInstance();

        if ($excludeId === null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM admins WHERE username = ?'
            );
            $stmt->execute([$username]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM admins WHERE username = ? AND id != ?'
            );
            $stmt->execute([$username, $excludeId]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    // =========================================================================
    // WRITE methods
    // =========================================================================

    /**
     * Insert a new admin record.
     *
     * @param  array $data Must contain: name, username, password_hash, phone,
     *                     role, status, created_by
     * @return int         The new record's auto-increment ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO admins
                (name, username, password_hash, phone, role, status, created_by)
             VALUES
                (:name, :username, :password_hash, :phone, :role, :status, :created_by)'
        );
        $stmt->execute([
            ':name'          => $data['name'],
            ':username'      => $data['username'],
            ':password_hash' => $data['password_hash'],
            ':phone'         => $data['phone'] ?? null,
            ':role'          => $data['role'],
            ':status'        => $data['status'],
            ':created_by'    => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update whitelisted fields for an admin.
     * Only fields present in $data and in the whitelist are updated.
     * Does nothing if $data is empty or contains no whitelisted fields.
     *
     * Whitelisted fields: name, username, phone, role, status, updated_by
     *
     * @param  int   $id
     * @param  array $data Key-value pairs to update.
     * @return void
     */
    public static function update(int $id, array $data): void
    {
        // Filter to only whitelisted keys that exist in $data
        $allowed = array_intersect_key($data, array_flip(self::UPDATE_WHITELIST));

        if (empty($allowed)) {
            return;
        }

        $setClauses = [];
        $params     = [];

        foreach ($allowed as $field => $value) {
            $setClauses[] = "{$field} = :{$field}";
            $params[":{$field}"] = $value;
        }

        $params[':id'] = $id;

        $sql  = 'UPDATE admins SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = Database::getInstance()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Update the password hash for an admin.
     *
     * @param  int    $id
     * @param  string $hash      New bcrypt hash.
     * @param  int    $updatedBy Admin ID performing the change.
     * @return void
     */
    public static function updatePasswordHash(int $id, string $hash, int $updatedBy): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE admins
                SET password_hash = ?,
                    updated_by    = ?,
                    updated_at    = NOW()
              WHERE id = ?'
        );
        $stmt->execute([$hash, $updatedBy, $id]);
    }

    /**
     * Update the consecutive failed-login counter for an admin.
     *
     * @param  int $id
     * @param  int $count New failed login count.
     * @return void
     */
    public static function updateFailedLogin(int $id, int $count): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE admins SET failed_login_count = ? WHERE id = ?'
        );
        $stmt->execute([$count, $id]);
    }

    /**
     * Lock an admin account until the given datetime and reset the failed
     * login counter.
     *
     * @param  int    $id
     * @param  string $lockedUntil Datetime string, e.g. '2024-01-01 12:15:00'.
     * @return void
     */
    public static function lockAccount(int $id, string $lockedUntil): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE admins
                SET locked_until        = ?,
                    failed_login_count  = 0
              WHERE id = ?'
        );
        $stmt->execute([$lockedUntil, $id]);
    }

    /**
     * Clear the failed-login counter and remove any active account lock.
     *
     * @param  int $id
     * @return void
     */
    public static function resetFailedLogin(int $id): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE admins
                SET failed_login_count = 0,
                    locked_until       = NULL
              WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /**
     * Store or clear a remember-me token and its expiry.
     * Pass null for both $token and $expires to clear the token on logout.
     *
     * @param  int         $id
     * @param  string|null $token   64-char token, or null to clear.
     * @param  string|null $expires Datetime string, or null to clear.
     * @return void
     */
    public static function updateRememberToken(int $id, ?string $token, ?string $expires): void
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'UPDATE admins
                SET remember_token         = ?,
                    remember_token_expires = ?
              WHERE id = ?'
        );
        $stmt->execute([$token, $expires, $id]);
    }
}
