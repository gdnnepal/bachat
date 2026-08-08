<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/LoanModel.php — Loan records
 *
 * outstanding_principal and accrued_interest are stored (not derived) because
 * repayment splitting needs a single authoritative balance per loan; every
 * mutation happens inside the LoanService transaction that also writes the
 * matching repayment row.
 *
 * Requirements covered: 7.1, 7.7, 7.9, 7.10, 7.11
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class LoanModel
{
    /** Columns an admin may edit after disbursement (Req 7.11). */
    private const UPDATE_WHITELIST = ['interest_rate', 'remarks'];

    // =========================================================================
    // READ
    // =========================================================================

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT l.*, m.member_id AS member_code, m.full_name
               FROM loans l
               JOIN members m ON m.id = l.member_id
              WHERE l.id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Filtered loan listing joined to member identity.
     *
     * @param array $filters {member_id?, loan_status?, cycle_id?, period_id?,
     *                        bs_year_from?, bs_month_from?, bs_year_to?, bs_month_to?}
     * @return array<int, array<string, mixed>>
     */
    public static function listByFilters(array $filters): array
    {
        $where  = ['l.status = 1'];
        $params = [];

        if (!empty($filters['member_id'])) {
            $where[] = 'l.member_id = :member_id';
            $params[':member_id'] = (int) $filters['member_id'];
        }

        if (!empty($filters['loan_status'])) {
            $where[] = 'l.loan_status = :loan_status';
            $params[':loan_status'] = (string) $filters['loan_status'];
        }

        if (!empty($filters['cycle_id'])) {
            $where[] = 'l.cycle_id = :cycle_id';
            $params[':cycle_id'] = (int) $filters['cycle_id'];
        }

        if (!empty($filters['period_id'])) {
            $where[] = 'l.accounting_period_id = :period_id';
            $params[':period_id'] = (int) $filters['period_id'];
        }

        if (!empty($filters['bs_year_from']) && !empty($filters['bs_month_from'])) {
            $where[] = '(l.loan_date_bs_year * 12 + l.loan_date_bs_month) >= :from_ord';
            $params[':from_ord'] = (int) $filters['bs_year_from'] * 12 + (int) $filters['bs_month_from'];
        }

        if (!empty($filters['bs_year_to']) && !empty($filters['bs_month_to'])) {
            $where[] = '(l.loan_date_bs_year * 12 + l.loan_date_bs_month) <= :to_ord';
            $params[':to_ord'] = (int) $filters['bs_year_to'] * 12 + (int) $filters['bs_month_to'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = Database::getInstance()->prepare(
            "SELECT l.id, l.member_id, m.member_id AS member_code, m.full_name,
                    l.loan_amount, l.outstanding_principal, l.accrued_interest,
                    l.interest_rate, l.loan_status, l.remarks,
                    l.loan_date_bs_year, l.loan_date_bs_month, l.loan_date_ad,
                    l.accounting_period_id, l.cycle_id, l.created_at
               FROM loans l
               JOIN members m ON m.id = l.member_id
               {$whereSql}
              ORDER BY l.loan_date_ad DESC, l.id DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Total principal still owed across every Outstanding loan — Dashboard card
     * and the Month_Close cash gate.
     */
    public static function totalOutstandingPrincipal(): float
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COALESCE(SUM(outstanding_principal), 0)
               FROM loans
              WHERE loan_status = 'Outstanding' AND status = 1"
        );
        $stmt->execute();

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Outstanding principal + accrued interest per member for one cycle, keyed
     * by member_id. One query keeps the distribution ledger free of N+1.
     *
     * @return array<int, float>
     */
    public static function outstandingByMemberInCycle(int $cycleId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT member_id,
                    COALESCE(SUM(outstanding_principal + accrued_interest), 0) AS total
               FROM loans
              WHERE cycle_id = ? AND loan_status = 'Outstanding' AND status = 1
              GROUP BY member_id"
        );
        $stmt->execute([$cycleId]);

        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $totals[(int) $row['member_id']] = round((float) $row['total'], 2);
        }

        return $totals;
    }

    /**
     * Every Outstanding loan of a cycle — used by DistributionService::confirm()
     * to mark loans Completed after settlement.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function outstandingInCycle(int $cycleId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT id, member_id, outstanding_principal, accrued_interest
               FROM loans
              WHERE cycle_id = ? AND loan_status = 'Outstanding' AND status = 1
              ORDER BY id ASC"
        );
        $stmt->execute([$cycleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{count: int, total: float}
     */
    public static function periodSummary(int $periodId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(loan_amount), 0) AS total
               FROM loans
              WHERE accounting_period_id = ? AND status = 1'
        );
        $stmt->execute([$periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'total' => round((float) ($row['total'] ?? 0), 2),
        ];
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Insert one loan. outstanding_principal starts equal to loan_amount.
     *
     * @param array $data {cycle_id, accounting_period_id, member_id, loan_amount,
     *                     interest_rate, loan_date_bs_year, loan_date_bs_month,
     *                     loan_date_ad, remarks?, created_by?}
     * @return int New loan ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO loans
                (cycle_id, accounting_period_id, member_id, loan_amount,
                 outstanding_principal, accrued_interest, interest_rate,
                 loan_date_bs_year, loan_date_bs_month, loan_date_ad,
                 loan_status, remarks, created_by)
             VALUES
                (:cycle_id, :period_id, :member_id, :amount,
                 :amount2, 0.00, :rate,
                 :bs_year, :bs_month, :date_ad,
                 :loan_status, :remarks, :created_by)'
        );

        $amount = number_format((float) $data['loan_amount'], 2, '.', '');

        $stmt->execute([
            ':cycle_id'    => $data['cycle_id'],
            ':period_id'   => $data['accounting_period_id'],
            ':member_id'   => $data['member_id'],
            ':amount'      => $amount,
            ':amount2'     => $amount,
            ':rate'        => number_format((float) $data['interest_rate'], 2, '.', ''),
            ':bs_year'     => $data['loan_date_bs_year'],
            ':bs_month'    => $data['loan_date_bs_month'],
            ':date_ad'     => $data['loan_date_ad'],
            ':loan_status' => 'Outstanding',
            ':remarks'     => $data['remarks'] ?? null,
            ':created_by'  => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Update the editable subset of a loan (Req 7.11).
     *
     * @param array $data Keys outside UPDATE_WHITELIST are ignored.
     */
    public static function update(int $id, array $data, ?int $adminId = null): void
    {
        $sets   = [];
        $params = [':id' => $id];

        foreach (self::UPDATE_WHITELIST as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $sets[] = "{$column} = :{$column}";
            $params[":{$column}"] = $column === 'interest_rate'
                ? number_format((float) $data[$column], 2, '.', '')
                : $data[$column];
        }

        if ($sets === []) {
            return;
        }

        $sets[] = 'updated_by = :updated_by';
        $params[':updated_by'] = $adminId;

        $stmt = Database::getInstance()->prepare(
            'UPDATE loans SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);
    }

    /**
     * Apply a repayment to the stored balances.
     */
    public static function applyRepayment(
        int $id,
        float $principalPaid,
        float $interestPaid,
        ?int $adminId = null
    ): void {
        $stmt = Database::getInstance()->prepare(
            'UPDATE loans
                SET outstanding_principal = GREATEST(outstanding_principal - :principal, 0),
                    accrued_interest      = GREATEST(accrued_interest - :interest, 0),
                    updated_by            = :updated_by
              WHERE id = :id'
        );
        $stmt->execute([
            ':principal'  => number_format($principalPaid, 2, '.', ''),
            ':interest'   => number_format($interestPaid, 2, '.', ''),
            ':updated_by' => $adminId,
            ':id'         => $id,
        ]);
    }

    /**
     * Add newly accrued interest to a loan (charged at Month_Close or manually).
     */
    public static function addAccruedInterest(int $id, float $amount, ?int $adminId = null): void
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE loans
                SET accrued_interest = accrued_interest + :amount,
                    updated_by       = :updated_by
              WHERE id = :id'
        );
        $stmt->execute([
            ':amount'     => number_format($amount, 2, '.', ''),
            ':updated_by' => $adminId,
            ':id'         => $id,
        ]);
    }

    public static function setStatus(int $id, string $loanStatus, ?int $adminId = null): void
    {
        $stmt = Database::getInstance()->prepare(
            'UPDATE loans SET loan_status = :loan_status, updated_by = :updated_by WHERE id = :id'
        );
        $stmt->execute([
            ':loan_status' => $loanStatus,
            ':updated_by'  => $adminId,
            ':id'          => $id,
        ]);
    }

    public static function complete(int $id, ?int $adminId = null): void
    {
        self::setStatus($id, 'Completed', $adminId);
    }

    public static function cancel(int $id, ?int $adminId = null): void
    {
        self::setStatus($id, 'Cancelled', $adminId);
    }
}
