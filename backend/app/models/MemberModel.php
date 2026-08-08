<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/MemberModel.php — DB queries for member records
 *
 * Requirements covered: 3.1, 3.2, 3.3, 3.4, 3.6, 3.7, 15.1
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class MemberModel
{
    /** Settings key holding the monotonic Member_ID counter. */
    private const SEQ_COUNTER_KEY = 'member_seq_counter';

    /** Fields update() is permitted to write — guards against mass-assignment. */
    private const UPDATE_WHITELIST = [
        'full_name',
        'phone',
        'address',
        'join_date_bs_year',
        'join_date_bs_month',
        'join_date_bs_day',
        'join_date_ad',
        'notes',
        'status',
        'updated_by',
    ];

    // =========================================================================
    // READ
    // =========================================================================

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Look up by the public-facing Member_ID (e.g. "B000042").
     */
    public static function findByMemberId(string $memberId): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM members WHERE member_id = ? LIMIT 1');
        $stmt->execute([$memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Paginated search across Member_ID, full name (substring) and phone.
     *
     * A leading-wildcard LIKE cannot use an index, but the target dataset is
     * 50–500 members so the full scan stays well inside the 300ms budget of
     * Requirement 3.4.
     *
     * @param string   $query   Free-text term; empty string returns everything.
     * @param int      $page    1-based page number.
     * @param int      $perPage Rows per page.
     * @param int|null $status  Filter by status (1 active / 0 inactive), or null for all.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function search(
        string $query = '',
        int $page = 1,
        int $perPage = 25,
        ?int $status = null
    ): array {
        $where  = [];
        $params = [];

        $query = trim($query);
        if ($query !== '') {
            $where[] = '(member_id LIKE :q OR full_name LIKE :q2 OR phone LIKE :q3)';
            $like = '%' . $query . '%';
            $params[':q']  = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        if ($status !== null) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $pdo = Database::getInstance();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM members {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page    = max(1, $page);
        $perPage = max(1, min($perPage, 500));
        $offset  = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            "SELECT id, member_id, full_name, phone, address,
                    join_date_bs_year, join_date_bs_month, join_date_bs_day,
                    join_date_ad, notes, status, created_at
               FROM members
               {$whereSql}
              ORDER BY member_seq ASC
              LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'rows'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    /**
     * Every active member, ordered by Member_ID — used by the bulk collection
     * screen, Month_Close interest run and the distribution ledger.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listActive(): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, member_id, full_name, phone, status
               FROM members
              WHERE status = 1
              ORDER BY member_seq ASC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function countActive(): int
    {
        $stmt = Database::getInstance()->prepare('SELECT COUNT(*) FROM members WHERE status = 1');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Report which financial tables still reference this member.
     * An empty array means the member is safe to delete (Req 3.6, 3.7).
     *
     * @return array<string, int> Table label => row count, only for non-zero counts.
     */
    public static function financialHistory(int $memberId): array
    {
        $pdo = Database::getInstance();

        $counts = [
            'saving transactions' => 'SELECT COUNT(*) FROM saving_transactions WHERE member_id = ?',
            'interest credits'    => 'SELECT COUNT(*) FROM interest_transactions WHERE member_id = ?',
            'loans'               => 'SELECT COUNT(*) FROM loans WHERE member_id = ?',
            'repayments'          => 'SELECT COUNT(*) FROM repayments r
                                        JOIN loans l ON l.id = r.loan_id
                                       WHERE l.member_id = ?',
            'distributions'       => 'SELECT COUNT(*) FROM distribution_items WHERE member_id = ?',
        ];

        $blocking = [];

        foreach ($counts as $label => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$memberId]);
            $count = (int) $stmt->fetchColumn();

            if ($count > 0) {
                $blocking[$label] = $count;
            }
        }

        return $blocking;
    }

    public static function hasFinancialHistory(int $memberId): bool
    {
        return self::financialHistory($memberId) !== [];
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Reserve the next Member_ID sequence number.
     *
     * Backed by a settings counter rather than MAX(member_seq) so that deleting
     * a member never frees their number for reuse (Req 3.1). Call inside a
     * transaction — the counter row is locked FOR UPDATE.
     */
    public static function nextSequence(): int
    {
        return SettingModel::incrementCounter(self::SEQ_COUNTER_KEY);
    }

    /**
     * Format a raw sequence number as a Member_ID: 42 → "B000042".
     */
    public static function formatMemberId(int $sequence): string
    {
        return 'B' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Insert a member record.
     *
     * @param array $data Must contain member_id, member_seq, full_name, phone,
     *                    join_date_bs_* and join_date_ad.
     * @return int New auto-increment ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO members
                (member_id, member_seq, full_name, phone, address,
                 join_date_bs_year, join_date_bs_month, join_date_bs_day, join_date_ad,
                 notes, status, created_by)
             VALUES
                (:member_id, :member_seq, :full_name, :phone, :address,
                 :bs_year, :bs_month, :bs_day, :join_ad,
                 :notes, :status, :created_by)'
        );
        $stmt->execute([
            ':member_id'  => $data['member_id'],
            ':member_seq' => $data['member_seq'],
            ':full_name'  => $data['full_name'],
            ':phone'      => $data['phone'],
            ':address'    => $data['address'] ?? null,
            ':bs_year'    => $data['join_date_bs_year'],
            ':bs_month'   => $data['join_date_bs_month'],
            ':bs_day'     => $data['join_date_bs_day'],
            ':join_ad'    => $data['join_date_ad'],
            ':notes'      => $data['notes'] ?? null,
            ':status'     => $data['status'] ?? 1,
            ':created_by' => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update whitelisted fields. member_id and member_seq are immutable.
     */
    public static function update(int $id, array $data): void
    {
        $allowed = array_intersect_key($data, array_flip(self::UPDATE_WHITELIST));

        if ($allowed === []) {
            return;
        }

        $setClauses = [];
        $params     = [];

        foreach ($allowed as $field => $value) {
            $setClauses[] = "{$field} = :{$field}";
            $params[":{$field}"] = $value;
        }

        $params[':id'] = $id;

        $sql = 'UPDATE members SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        Database::getInstance()->prepare($sql)->execute($params);
    }

    /**
     * Hard-delete a member. Callers must verify financialHistory() is empty
     * first; the ON DELETE RESTRICT foreign keys are the last line of defence.
     */
    public static function delete(int $id): void
    {
        Database::getInstance()->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
    }
}
