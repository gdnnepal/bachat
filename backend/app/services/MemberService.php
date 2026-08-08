<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/MemberService.php — Member roster business logic
 *
 * Requirements covered: 3.1, 3.2, 3.3, 3.5, 3.6, 3.7, 3.8, 3.9
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Helpers\Validator;
use App\Models\MemberModel;
use InvalidArgumentException;
use Throwable;

class MemberService
{
    /** Validation rules for a member payload (Req 3.2, 3.9). */
    private const RULES_CREATE = [
        'full_name'          => 'required|type:string|maxLength:100',
        'phone'              => 'required|digits:7,15',
        'address'            => 'type:string|maxLength:255',
        'join_date_bs_year'  => 'required|type:int|min:2000|max:2090',
        'join_date_bs_month' => 'required|type:int|min:1|max:12',
        'join_date_bs_day'   => 'required|type:int|min:1|max:32',
        'notes'              => 'type:string|maxLength:500',
        'status'             => 'type:enum:0,1',
    ];

    /** Fields whose changes are recorded in the Member_Edit audit entry. */
    private const TRACKED_FIELDS = [
        'full_name',
        'phone',
        'address',
        'join_date_bs_year',
        'join_date_bs_month',
        'join_date_bs_day',
        'notes',
        'status',
    ];

    // =========================================================================
    // Read
    // =========================================================================

    /**
     * Paginated roster search by Member_ID, name substring or phone (Req 3.4).
     *
     * @return array{rows: array<int, array>, total: int, page: int, per_page: int}
     */
    public static function search(string $query, int $page, int $perPage, ?int $status = null): array
    {
        $result = MemberModel::search($query, $page, $perPage, $status);

        return [
            'rows'     => array_map(self::present(...), $result['rows']),
            'total'    => $result['total'],
            'page'     => max(1, $page),
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array{success: bool, member?: array, error?: string}
     */
    public static function find(int $id): array
    {
        $member = MemberModel::findById($id);

        if ($member === null) {
            return ['success' => false, 'error' => 'Member not found.'];
        }

        return ['success' => true, 'member' => self::present($member)];
    }

    // =========================================================================
    // Create
    // =========================================================================

    /**
     * Create a member, generating a permanent Member_ID.
     *
     * The sequence reservation and the INSERT share one transaction so two
     * concurrent creates can never receive the same Member_ID (Req 3.1).
     *
     * @return array{success: bool, member?: array, error?: string, fields?: array}
     */
    public static function create(array $data, int $adminId): array
    {
        $validation = Validator::validate($data, self::RULES_CREATE);

        if (!$validation['valid']) {
            return ['success' => false, 'error' => 'Validation failed.', 'fields' => $validation['errors']];
        }

        $bsYear  = (int) $data['join_date_bs_year'];
        $bsMonth = (int) $data['join_date_bs_month'];
        $bsDay   = (int) $data['join_date_bs_day'];

        try {
            $joinDateAd = BsCalendar::bsToAd($bsYear, $bsMonth, $bsDay);
        } catch (InvalidArgumentException $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
                'fields'  => ['join_date_bs_day' => $e->getMessage()],
            ];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $sequence = MemberModel::nextSequence();
            $memberId = MemberModel::formatMemberId($sequence);

            $newId = MemberModel::create([
                'member_id'          => $memberId,
                'member_seq'         => $sequence,
                'full_name'          => trim((string) $data['full_name']),
                'phone'              => (string) $data['phone'],
                'address'            => self::nullIfBlank($data['address'] ?? null),
                'join_date_bs_year'  => $bsYear,
                'join_date_bs_month' => $bsMonth,
                'join_date_bs_day'   => $bsDay,
                'join_date_ad'       => $joinDateAd,
                'notes'              => self::nullIfBlank($data['notes'] ?? null),
                'status'             => isset($data['status']) ? (int) $data['status'] : 1,
                'created_by'         => $adminId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'error' => 'Failed to create member: ' . $e->getMessage()];
        }

        AuditLogger::log(
            AuditLogger::MEMBER_ADD,
            "Added member {$memberId} — {$data['full_name']} (phone {$data['phone']}).",
            $adminId
        );

        return ['success' => true, 'member' => self::present(MemberModel::findById($newId) ?? [])];
    }

    // =========================================================================
    // Update
    // =========================================================================

    /**
     * Update a member. Member_ID is permanent and is never accepted here.
     *
     * @return array{success: bool, member?: array, error?: string, fields?: array}
     */
    public static function update(int $id, array $data, int $adminId): array
    {
        $existing = MemberModel::findById($id);

        if ($existing === null) {
            return ['success' => false, 'error' => 'Member not found.'];
        }

        // Only validate the fields actually supplied — this is a partial update.
        $rules = array_intersect_key(self::RULES_CREATE, $data);
        $validation = Validator::validate($data, $rules);

        if (!$validation['valid']) {
            return ['success' => false, 'error' => 'Validation failed.', 'fields' => $validation['errors']];
        }

        $update = [];

        foreach (['full_name', 'phone', 'address', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = self::nullIfBlank($data[$field]);
            }
        }

        if (isset($update['full_name'])) {
            $update['full_name'] = trim((string) $update['full_name']);
        }

        if (isset($data['status'])) {
            $update['status'] = (int) $data['status'];
        }

        // A BS join date change must keep the stored AD equivalent in step.
        $dateKeys = ['join_date_bs_year', 'join_date_bs_month', 'join_date_bs_day'];
        if (array_intersect($dateKeys, array_keys($data)) !== []) {
            $bsYear  = (int) ($data['join_date_bs_year']  ?? $existing['join_date_bs_year']);
            $bsMonth = (int) ($data['join_date_bs_month'] ?? $existing['join_date_bs_month']);
            $bsDay   = (int) ($data['join_date_bs_day']   ?? $existing['join_date_bs_day']);

            try {
                $update['join_date_ad'] = BsCalendar::bsToAd($bsYear, $bsMonth, $bsDay);
            } catch (InvalidArgumentException $e) {
                return [
                    'success' => false,
                    'error'   => $e->getMessage(),
                    'fields'  => ['join_date_bs_day' => $e->getMessage()],
                ];
            }

            $update['join_date_bs_year']  = $bsYear;
            $update['join_date_bs_month'] = $bsMonth;
            $update['join_date_bs_day']   = $bsDay;
        }

        if ($update === []) {
            return ['success' => true, 'member' => self::present($existing)];
        }

        $changes = self::diff($existing, $update);

        $update['updated_by'] = $adminId;
        MemberModel::update($id, $update);

        AuditLogger::log(
            AuditLogger::MEMBER_EDIT,
            "Edited member {$existing['member_id']}. Changes: "
                . ($changes === [] ? 'none' : implode('; ', $changes)) . '.',
            $adminId
        );

        return ['success' => true, 'member' => self::present(MemberModel::findById($id) ?? [])];
    }

    // =========================================================================
    // Status change
    // =========================================================================

    /**
     * Activate or deactivate a member (Req 3.8).
     *
     * @return array{success: bool, error?: string}
     */
    public static function setStatus(int $id, int $status, int $adminId): array
    {
        $member = MemberModel::findById($id);

        if ($member === null) {
            return ['success' => false, 'error' => 'Member not found.'];
        }

        $old = (int) $member['status'];

        if ($old === $status) {
            return ['success' => true];
        }

        MemberModel::update($id, ['status' => $status, 'updated_by' => $adminId]);

        AuditLogger::log(
            AuditLogger::MEMBER_STATUS_CHANGE,
            sprintf(
                'Member %s status changed from %s to %s.',
                $member['member_id'],
                self::statusLabel($old),
                self::statusLabel($status)
            ),
            $adminId
        );

        return ['success' => true];
    }

    // =========================================================================
    // Delete
    // =========================================================================

    /**
     * Permanently remove a member who has no financial history.
     *
     * Members with transactions are never deleted — the caller is told exactly
     * which records block the operation (Req 3.6, 3.7).
     *
     * @return array{success: bool, error?: string}
     */
    public static function delete(int $id, int $adminId): array
    {
        $member = MemberModel::findById($id);

        if ($member === null) {
            return ['success' => false, 'error' => 'Member not found.'];
        }

        $blocking = MemberModel::financialHistory($id);

        if ($blocking !== []) {
            $parts = [];
            foreach ($blocking as $label => $count) {
                $parts[] = "{$count} {$label}";
            }

            return [
                'success' => false,
                'error'   => sprintf(
                    'Member %s cannot be deleted because they have %s. Deactivate the member instead.',
                    $member['member_id'],
                    implode(', ', $parts)
                ),
            ];
        }

        MemberModel::delete($id);

        AuditLogger::log(
            AuditLogger::MEMBER_DELETE,
            "Deleted member {$member['member_id']} — {$member['full_name']} (no financial history).",
            $adminId
        );

        return ['success' => true];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Shape a DB row for API output, adding the display-ready BS join date.
     */
    private static function present(array $row): array
    {
        if ($row === []) {
            return [];
        }

        return [
            'id'                 => (int) $row['id'],
            'member_id'          => $row['member_id'],
            'full_name'          => $row['full_name'],
            'phone'              => $row['phone'],
            'address'            => $row['address'] ?? null,
            'join_date_bs_year'  => (int) $row['join_date_bs_year'],
            'join_date_bs_month' => (int) $row['join_date_bs_month'],
            'join_date_bs_day'   => (int) $row['join_date_bs_day'],
            'join_date_ad'       => $row['join_date_ad'],
            'notes'              => $row['notes'] ?? null,
            'status'             => (int) $row['status'],
            'created_at'         => $row['created_at'] ?? null,
        ];
    }

    /**
     * @return array<int, string> "field: 'old' → 'new'" strings for the audit log.
     */
    private static function diff(array $existing, array $incoming): array
    {
        $changes = [];

        foreach (self::TRACKED_FIELDS as $field) {
            if (!array_key_exists($field, $incoming)) {
                continue;
            }

            $old = (string) ($existing[$field] ?? '');
            $new = (string) ($incoming[$field] ?? '');

            if ($old !== $new) {
                $changes[] = "{$field}: '{$old}' → '{$new}'";
            }
        }

        return $changes;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function statusLabel(int $status): string
    {
        return $status === 1 ? 'Active' : 'Inactive';
    }
}
