<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/RepaymentModel.php — Loan repayment records
 *
 * Requirements covered: 7.4, 7.5, 7.6, 7.7, 11.2
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class RepaymentModel
{
    // =========================================================================
    // READ
    // =========================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listByLoan(int $loanId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, loan_id, repayment_type, amount, principal_paid, interest_paid,
                    repayment_date_bs_year, repayment_date_bs_month, repayment_date_ad,
                    remarks, accounting_period_id, cycle_id, created_at
               FROM repayments
              WHERE loan_id = ? AND status = 1
              ORDER BY repayment_date_ad ASC, id ASC'
        );
        $stmt->execute([$loanId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Filtered listing joined through the loan to member identity.
     *
     * @param array $filters {member_id?, loan_id?, period_id?, cycle_id?,
     *                        bs_year_from?, bs_month_from?, bs_year_to?, bs_month_to?}
     * @return array<int, array<string, mixed>>
     */
    public static function listByFilters(array $filters): array
    {
        $where  = ['r.status = 1'];
        $params = [];

        if (!empty($filters['member_id'])) {
            $where[] = 'l.member_id = :member_id';
            $params[':member_id'] = (int) $filters['member_id'];
        }

        if (!empty($filters['loan_id'])) {
            $where[] = 'r.loan_id = :loan_id';
            $params[':loan_id'] = (int) $filters['loan_id'];
        }

        if (!empty($filters['period_id'])) {
            $where[] = 'r.accounting_period_id = :period_id';
            $params[':period_id'] = (int) $filters['period_id'];
        }

        if (!empty($filters['cycle_id'])) {
            $where[] = 'r.cycle_id = :cycle_id';
            $params[':cycle_id'] = (int) $filters['cycle_id'];
        }

        if (!empty($filters['bs_year_from']) && !empty($filters['bs_month_from'])) {
            $where[] = '(r.repayment_date_bs_year * 12 + r.repayment_date_bs_month) >= :from_ord';
            $params[':from_ord'] = (int) $filters['bs_year_from'] * 12 + (int) $filters['bs_month_from'];
        }

        if (!empty($filters['bs_year_to']) && !empty($filters['bs_month_to'])) {
            $where[] = '(r.repayment_date_bs_year * 12 + r.repayment_date_bs_month) <= :to_ord';
            $params[':to_ord'] = (int) $filters['bs_year_to'] * 12 + (int) $filters['bs_month_to'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = Database::getInstance()->prepare(
            "SELECT r.id, r.loan_id, l.member_id, m.member_id AS member_code, m.full_name,
                    r.repayment_type, r.amount, r.principal_paid, r.interest_paid,
                    r.repayment_date_bs_year, r.repayment_date_bs_month, r.repayment_date_ad,
                    r.remarks, r.accounting_period_id, r.cycle_id, r.created_at
               FROM repayments r
               JOIN loans   l ON l.id = r.loan_id
               JOIN members m ON m.id = l.member_id
               {$whereSql}
              ORDER BY r.repayment_date_ad ASC, r.id ASC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{count: int, total: float, principal: float, interest: float}
     */
    public static function periodSummary(int $periodId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(amount), 0)         AS total,
                    COALESCE(SUM(principal_paid), 0) AS principal,
                    COALESCE(SUM(interest_paid), 0)  AS interest
               FROM repayments
              WHERE accounting_period_id = ? AND status = 1'
        );
        $stmt->execute([$periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'count'     => (int) ($row['cnt'] ?? 0),
            'total'     => round((float) ($row['total'] ?? 0), 2),
            'principal' => round((float) ($row['principal'] ?? 0), 2),
            'interest'  => round((float) ($row['interest'] ?? 0), 2),
        ];
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * @param array $data {loan_id, cycle_id, accounting_period_id, repayment_type,
     *                     amount, principal_paid, interest_paid,
     *                     repayment_date_bs_year, repayment_date_bs_month,
     *                     repayment_date_ad, remarks?, created_by?}
     * @return int New repayment ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO repayments
                (loan_id, cycle_id, accounting_period_id, repayment_type, amount,
                 principal_paid, interest_paid,
                 repayment_date_bs_year, repayment_date_bs_month, repayment_date_ad,
                 remarks, created_by)
             VALUES
                (:loan_id, :cycle_id, :period_id, :type, :amount,
                 :principal, :interest,
                 :bs_year, :bs_month, :date_ad,
                 :remarks, :created_by)'
        );
        $stmt->execute([
            ':loan_id'    => $data['loan_id'],
            ':cycle_id'   => $data['cycle_id'],
            ':period_id'  => $data['accounting_period_id'],
            ':type'       => $data['repayment_type'],
            ':amount'     => number_format((float) $data['amount'], 2, '.', ''),
            ':principal'  => number_format((float) ($data['principal_paid'] ?? 0), 2, '.', ''),
            ':interest'   => number_format((float) ($data['interest_paid'] ?? 0), 2, '.', ''),
            ':bs_year'    => $data['repayment_date_bs_year'],
            ':bs_month'   => $data['repayment_date_bs_month'],
            ':date_ad'    => $data['repayment_date_ad'],
            ':remarks'    => $data['remarks'] ?? null,
            ':created_by' => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
