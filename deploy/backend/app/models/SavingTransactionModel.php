<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/SavingTransactionModel.php — Monthly member savings records
 *
 * A UNIQUE(member_id, accounting_period_id) constraint enforces the
 * one-saving-per-member-per-month rule at the database level; the application
 * check in existsForMemberPeriod() complements it (Req 5.4).
 *
 * Requirements covered: 5.1, 5.2, 5.4, 10.1, 11.2
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class SavingTransactionModel
{
    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Has this member already saved in this period?
     */
    public static function existsForMemberPeriod(int $memberId, int $periodId): bool
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM saving_transactions
              WHERE member_id = ? AND accounting_period_id = ?'
        );
        $stmt->execute([$memberId, $periodId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Member IDs that already have a saving recorded for the period — used to
     * pre-check the bulk collection screen (Req 5.1).
     *
     * @return array<int, int>
     */
    public static function memberIdsWithSavingInPeriod(int $periodId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT member_id FROM saving_transactions WHERE accounting_period_id = ?'
        );
        $stmt->execute([$periodId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Total savings deposited by one member within a cycle.
     */
    public static function totalForMemberInCycle(int $memberId, int $cycleId): float
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM saving_transactions
              WHERE member_id = ? AND cycle_id = ? AND status = 1'
        );
        $stmt->execute([$memberId, $cycleId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Savings totals for every member in a cycle, keyed by member_id.
     *
     * One query instead of one-per-member keeps Month_Close and the
     * distribution ledger free of N+1 round-trips.
     *
     * @return array<int, float>
     */
    public static function totalsByMemberInCycle(int $cycleId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT member_id, COALESCE(SUM(amount), 0) AS total
               FROM saving_transactions
              WHERE cycle_id = ? AND status = 1
              GROUP BY member_id'
        );
        $stmt->execute([$cycleId]);

        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(int) $row['member_id']] = round((float) $row['total'], 2);
        }

        return $totals;
    }

    /**
     * Period-level aggregate for the month summary.
     *
     * @return array{count: int, total: float}
     */
    public static function periodSummary(int $periodId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
               FROM saving_transactions
              WHERE accounting_period_id = ? AND status = 1'
        );
        $stmt->execute([$periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'total' => round((float) ($row['total'] ?? 0), 2),
        ];
    }

    /**
     * Filtered listing joined to member identity, for reports and statements.
     *
     * @param array $filters {member_id?, period_id?, cycle_id?,
     *                        bs_year_from?, bs_month_from?, bs_year_to?, bs_month_to?}
     * @return array<int, array<string, mixed>>
     */
    public static function listByFilters(array $filters): array
    {
        $where  = ['st.status = 1'];
        $params = [];

        foreach (['member_id' => 'st.member_id', 'period_id' => 'st.accounting_period_id', 'cycle_id' => 'st.cycle_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = :{$key}";
                $params[":{$key}"] = (int) $filters[$key];
            }
        }

        if (!empty($filters['bs_year_from']) && !empty($filters['bs_month_from'])) {
            $where[] = '(st.transaction_date_bs_year * 12 + st.transaction_date_bs_month) >= :from_ord';
            $params[':from_ord'] = (int) $filters['bs_year_from'] * 12 + (int) $filters['bs_month_from'];
        }

        if (!empty($filters['bs_year_to']) && !empty($filters['bs_month_to'])) {
            $where[] = '(st.transaction_date_bs_year * 12 + st.transaction_date_bs_month) <= :to_ord';
            $params[':to_ord'] = (int) $filters['bs_year_to'] * 12 + (int) $filters['bs_month_to'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = Database::getInstance()->prepare(
            "SELECT st.id, st.member_id, m.member_id AS member_code, m.full_name,
                    st.amount, st.remarks,
                    st.transaction_date_bs_year, st.transaction_date_bs_month,
                    st.transaction_date_ad, st.accounting_period_id, st.cycle_id,
                    st.created_at
               FROM saving_transactions st
               JOIN members m ON m.id = st.member_id
               {$whereSql}
              ORDER BY st.transaction_date_ad ASC, st.id ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Insert one saving transaction.
     *
     * @param array $data {cycle_id, accounting_period_id, member_id, amount,
     *                     transaction_date_bs_year, transaction_date_bs_month,
     *                     transaction_date_ad, remarks?, created_by?}
     * @return int New transaction ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO saving_transactions
                (cycle_id, accounting_period_id, member_id, amount,
                 transaction_date_bs_year, transaction_date_bs_month, transaction_date_ad,
                 remarks, created_by)
             VALUES
                (:cycle_id, :period_id, :member_id, :amount,
                 :bs_year, :bs_month, :date_ad,
                 :remarks, :created_by)'
        );
        $stmt->execute([
            ':cycle_id'   => $data['cycle_id'],
            ':period_id'  => $data['accounting_period_id'],
            ':member_id'  => $data['member_id'],
            ':amount'     => number_format((float) $data['amount'], 2, '.', ''),
            ':bs_year'    => $data['transaction_date_bs_year'],
            ':bs_month'   => $data['transaction_date_bs_month'],
            ':date_ad'    => $data['transaction_date_ad'],
            ':remarks'    => $data['remarks'] ?? null,
            ':created_by' => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
