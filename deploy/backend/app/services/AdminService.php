<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/AdminService.php — Admin account management business logic
 *
 * Requirements covered: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminModel;

class AdminService
{
    /** Fields whose changes are recorded in the User_Modification audit entry. */
    private const TRACKED_FIELDS = ['name', 'username', 'phone', 'role', 'status'];

    // =========================================================================
    // Read
    // =========================================================================

    /**
     * List every admin account (password hashes are never selected).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list(): array
    {
        return array_map(self::present(...), AdminModel::listAll());
    }

    /**
     * Fetch a single admin account.
     *
     * @return array{success: bool, admin?: array, error?: string}
     */
    public static function find(int $id): array
    {
        $admin = AdminModel::findById($id);

        if ($admin === null) {
            return ['success' => false, 'error' => 'Admin not found.'];
        }

        return ['success' => true, 'admin' => self::present($admin)];
    }

    // =========================================================================
    // Create
    // =========================================================================

    /**
     * Create a new admin account.
     *
     * @param array $data      {name, username, password, phone?, role?, status?}
     * @param int   $createdBy Super_Admin performing the action.
     *
     * @return array{success: bool, id?: int, error?: string, field?: string}
     */
    public static function create(array $data, int $createdBy): array
    {
        $username = trim((string) $data['username']);

        if (AdminModel::usernameExists($username)) {
            return [
                'success' => false,
                'error'   => "The username '{$username}' is already taken.",
                'field'   => 'username',
            ];
        }

        $newId = AdminModel::create([
            'name'          => trim((string) $data['name']),
            'username'      => $username,
            'password_hash' => password_hash((string) $data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'phone'         => $data['phone'] ?? null,
            'role'          => $data['role']   ?? 'Admin',
            'status'        => isset($data['status']) ? (int) $data['status'] : 1,
            'created_by'    => $createdBy,
        ]);

        AuditLogger::log(
            AuditLogger::USER_CREATION,
            "Created admin '{$username}' (ID {$newId}) with role " . ($data['role'] ?? 'Admin') . '.',
            $createdBy
        );

        return ['success' => true, 'id' => $newId];
    }

    // =========================================================================
    // Update
    // =========================================================================

    /**
     * Update an existing admin account.
     *
     * @param array $data      Any of: name, username, phone, role, status.
     * @param int   $updatedBy Super_Admin performing the action.
     *
     * @return array{success: bool, error?: string, field?: string}
     */
    public static function update(int $id, array $data, int $updatedBy): array
    {
        $existing = AdminModel::findById($id);

        if ($existing === null) {
            return ['success' => false, 'error' => 'Admin not found.'];
        }

        if (isset($data['username'])) {
            $data['username'] = trim((string) $data['username']);

            if (AdminModel::usernameExists($data['username'], $id)) {
                return [
                    'success' => false,
                    'error'   => "The username '{$data['username']}' is already taken.",
                    'field'   => 'username',
                ];
            }
        }

        $changes = self::diff($existing, $data);

        $data['updated_by'] = $updatedBy;
        AdminModel::update($id, $data);

        AuditLogger::log(
            AuditLogger::USER_MODIFICATION,
            "Updated admin '{$existing['username']}' (ID {$id}). Changes: "
                . ($changes === [] ? 'none' : implode('; ', $changes)) . '.',
            $updatedBy
        );

        return ['success' => true];
    }

    // =========================================================================
    // Activate / deactivate
    // =========================================================================

    /**
     * Activate (status 1) or deactivate (status 0) an admin account.
     *
     * Deactivation takes effect on the target's next request: AuthMiddleware
     * re-checks admins.status on every authenticated call (Req 2.4).
     *
     * @return array{success: bool, error?: string}
     */
    public static function setStatus(int $id, int $status, int $updatedBy): array
    {
        $admin = AdminModel::findById($id);

        if ($admin === null) {
            return ['success' => false, 'error' => 'Admin not found.'];
        }

        if ($id === $updatedBy && $status === 0) {
            return ['success' => false, 'error' => 'You cannot deactivate your own account.'];
        }

        AdminModel::update($id, ['status' => $status, 'updated_by' => $updatedBy]);

        if ($status === 0) {
            AdminModel::updateRememberToken($id, null, null);
        }

        AuditLogger::log(
            AuditLogger::USER_MODIFICATION,
            sprintf(
                "Admin '%s' (ID %d) %s.",
                $admin['username'],
                $id,
                $status === 1 ? 'activated' : 'deactivated'
            ),
            $updatedBy
        );

        return ['success' => true];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build a list of "field: 'old' → 'new'" strings for the audit description.
     *
     * @return array<int, string>
     */
    private static function diff(array $existing, array $incoming): array
    {
        $changes = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if (!isset($incoming[$field])) {
                continue;
            }

            $old = (string) ($existing[$field] ?? '');
            $new = (string) $incoming[$field];

            if ($old !== $new) {
                $changes[] = "{$field}: '{$old}' → '{$new}'";
            }
        }

        return $changes;
    }

    /**
     * Shape a DB row for API output — password_hash and remember_token are
     * never exposed.
     */
    private static function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'name'       => $row['name'],
            'username'   => $row['username'],
            'phone'      => $row['phone'] ?? null,
            'role'       => $row['role'],
            'status'     => (int) $row['status'],
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
