<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/InterestTransactionModel.php — Savings interest credit ledger
 *
 * Interest rows are created exclusively by Month_Close (Req 6.3). Because they
 * are included in the balance that the next month's interest is computed from,
 * this table is what makes the 1%/month interest compound (design: "Compound
 * effect").
 *
 * Requirements covered: 6.1, 6.2, 6.4, 6.6, 10.1, 11.2
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class InterestTransactionModel
{
    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Total interest credited to one member within a cycle.
     */
    public static function totalForMemberInCycle(int $memberId, int $cycleId): float
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM interest_transactions
              WHERE member_id = ? AND cycle_id = ? AND status = 1'
        );
        $stmt->execute([$memberId, $cycleId]);

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Interest totals for every member in a cycle, keyed by member_id.
     *
     * @return array<int, float>
     */
    public static function totalsByMemberInCycle(int $cycleId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT member_id, COALESCE(SUM(amount), 0) AS total
               FROM interest_transactions
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
     * Has interest already been credited for this period? Guards against a
     * double run if a reopened month is closed twice.
     */
    public static function existsForPeriod(int $periodId): bool
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM interest_transactions WHERE accounting_period_id = ?'
        );
        $stmt->execute([$periodId]);

        return (int) $stmt->fetchColumn() > 0;
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
               FROM interest_transactions
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
        $where  = ['it.status = 1'];
        $params = [];

        foreach (['member_id' => 'it.member_id', 'period_id' => 'it.accounting_period_id', 'cycle_id' => 'it.cycle_id'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = :{$key}";
                $params[":{$key}"] = (int) $filters[$key];
            }
        }

        // interest_transactions carry no BS date columns of their own — the
        // owning accounting period supplies them.
        if (!empty($filters['bs_year_from']) && !empty($filters['bs_month_from'])) {
            $where[] = '(ap.bs_year * 12 + ap.bs_month) >= :from_ord';
            $params[':from_ord'] = (int) $filters['bs_year_from'] * 12 + (int) $filters['bs_month_from'];
        }

        if (!empty($filters['bs_year_to']) && !empty($filters['bs_month_to'])) {
            $where[] = '(ap.bs_year * 12 + ap.bs_month) <= :to_ord';
            $params[':to_ord'] = (int) $filters['bs_year_to'] * 12 + (int) $filters['bs_month_to'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = Database::getInstance()->prepare(
            "SELECT it.id, it.member_id, m.member_id AS member_code, m.full_name,
                    it.amount, it.balance_before,
                    ap.bs_year, ap.bs_month,
                    it.accounting_period_id, it.cycle_id, it.created_at
               FROM interest_transactions it
               JOIN members m ON m.id = it.member_id
               JOIN accounting_periods ap ON ap.id = it.accounting_period_id
               {$whereSql}
              ORDER BY ap.bs_year ASC, ap.bs_month ASC, it.id ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Insert one interest credit.
     *
     * @param array $data {cycle_id, accounting_period_id, member_id, amount,
     *                     balance_before, created_by?}
     * @return int New transaction ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO interest_transactions
                (cycle_id, accounting_period_id, member_id, amount, balance_before, created_by)
             VALUES (:cycle_id, :period_id, :member_id, :amount, :balance_before, :created_by)'
        );
        $stmt->execute([
            ':cycle_id'       => $data['cycle_id'],
            ':period_id'      => $data['accounting_period_id'],
            ':member_id'      => $data['member_id'],
            ':amount'         => number_format((float) $data['amount'], 2, '.', ''),
            ':balance_before' => number_format((float) $data['balance_before'], 2, '.', ''),
            ':created_by'     => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
