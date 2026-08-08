<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/CashBankTransactionModel.php — Unified cash + bank ledger
 *
 * Every movement of money is a row here, so Cash_In_Hand and Bank_Balance are
 * always derived, never stored. Balances are cumulative across all cycles and
 * are therefore never filtered by cycle_id (Req 8.1, 10.7).
 *
 * Sign conventions:
 *   Cash_In_Hand  = CashIn  − CashOut  + BankToCash − CashToBank
 *   Bank_Balance  = BankIn  − BankOut  + CashToBank − BankToCash
 *
 * Requirements covered: 8.1, 8.2, 8.3, 8.6
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class CashBankTransactionModel
{
    /** SQL expression yielding the signed cash effect of each row. */
    private const CASH_DELTA = "
        CASE transaction_type
            WHEN 'CashIn'     THEN  amount
            WHEN 'CashOut'    THEN -amount
            WHEN 'BankToCash' THEN  amount
            WHEN 'CashToBank' THEN -amount
            ELSE 0
        END";

    /** SQL expression yielding the signed bank effect of each row. */
    private const BANK_DELTA = "
        CASE transaction_type
            WHEN 'BankIn'     THEN  amount
            WHEN 'BankOut'    THEN -amount
            WHEN 'CashToBank' THEN  amount
            WHEN 'BankToCash' THEN -amount
            ELSE 0
        END";

    // =========================================================================
    // BALANCES
    // =========================================================================

    /**
     * Current Cash_In_Hand across all cycles.
     */
    public static function getCashBalance(): float
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(' . self::CASH_DELTA . '), 0) FROM cash_bank_transactions WHERE status = 1'
        );
        $stmt->execute();

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Current Bank_Balance across all cycles.
     */
    public static function getBankBalance(): float
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(' . self::BANK_DELTA . '), 0) FROM cash_bank_transactions WHERE status = 1'
        );
        $stmt->execute();

        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Both balances in a single round-trip.
     *
     * @return array{cash_in_hand: float, bank_balance: float}
     */
    public static function getBalances(): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(' . self::CASH_DELTA . '), 0) AS cash_in_hand,
                    COALESCE(SUM(' . self::BANK_DELTA . '), 0) AS bank_balance
               FROM cash_bank_transactions
              WHERE status = 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'cash_in_hand' => round((float) ($row['cash_in_hand'] ?? 0), 2),
            'bank_balance' => round((float) ($row['bank_balance'] ?? 0), 2),
        ];
    }

    /**
     * Cash movement totals for one period — feeds the Month_Close cash
     * consistency gate of Req 4.3(a).
     *
     * @return array{cash_in: float, cash_out: float, net: float}
     */
    public static function periodCashTotals(int $periodId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN transaction_type IN ('CashIn','BankToCash')  THEN amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN transaction_type IN ('CashOut','CashToBank') THEN amount ELSE 0 END), 0) AS cash_out
               FROM cash_bank_transactions
              WHERE accounting_period_id = ? AND status = 1"
        );
        $stmt->execute([$periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $cashIn  = round((float) ($row['cash_in'] ?? 0), 2);
        $cashOut = round((float) ($row['cash_out'] ?? 0), 2);

        return [
            'cash_in'  => $cashIn,
            'cash_out' => $cashOut,
            'net'      => round($cashIn - $cashOut, 2),
        ];
    }

    /**
     * Cash_In_Hand as at the end of a period, computed from every row created
     * on or before that period. Used for month-summary snapshots.
     */
    public static function balancesAsOfPeriod(int $periodId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(SUM(' . self::CASH_DELTA . '), 0) AS cash_in_hand,
                    COALESCE(SUM(' . self::BANK_DELTA . '), 0) AS bank_balance
               FROM cash_bank_transactions
              WHERE status = 1 AND id <= (
                    SELECT COALESCE(MAX(id), 0)
                      FROM cash_bank_transactions
                     WHERE accounting_period_id = ?
              )'
        );
        $stmt->execute([$periodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'cash_in_hand' => round((float) ($row['cash_in_hand'] ?? 0), 2),
            'bank_balance' => round((float) ($row['bank_balance'] ?? 0), 2),
        ];
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Record one money movement.
     *
     * @param array $data {cycle_id, accounting_period_id, transaction_type,
     *                     amount, reference_type, reference_id?, description?,
     *                     transaction_date_bs_year, transaction_date_bs_month,
     *                     transaction_date_ad, created_by?}
     * @return int New transaction ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO cash_bank_transactions
                (cycle_id, accounting_period_id, transaction_type, amount,
                 reference_type, reference_id, description,
                 transaction_date_bs_year, transaction_date_bs_month, transaction_date_ad,
                 created_by)
             VALUES
                (:cycle_id, :period_id, :type, :amount,
                 :ref_type, :ref_id, :description,
                 :bs_year, :bs_month, :date_ad,
                 :created_by)'
        );
        $stmt->execute([
            ':cycle_id'    => $data['cycle_id'],
            ':period_id'   => $data['accounting_period_id'],
            ':type'        => $data['transaction_type'],
            ':amount'      => number_format((float) $data['amount'], 2, '.', ''),
            ':ref_type'    => $data['reference_type'],
            ':ref_id'      => $data['reference_id'] ?? null,
            ':description' => $data['description'] ?? null,
            ':bs_year'     => $data['transaction_date_bs_year'],
            ':bs_month'    => $data['transaction_date_bs_month'],
            ':date_ad'     => $data['transaction_date_ad'],
            ':created_by'  => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    // =========================================================================
    // LISTING
    // =========================================================================

    /**
     * Filtered ledger listing with a running balance column.
     *
     * @param array $filters {types?: string[], period_id?, cycle_id?,
     *                        bs_year_from?, bs_month_from?, bs_year_to?, bs_month_to?}
     * @param string $runningFor 'cash', 'bank' or '' for no running balance.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listByFilters(array $filters, string $runningFor = ''): array
    {
        $where  = ['cbt.status = 1'];
        $params = [];

        if (!empty($filters['types'])) {
            $placeholders = [];
            foreach (array_values($filters['types']) as $i => $type) {
                $key = ":type{$i}";
                $placeholders[] = $key;
                $params[$key] = $type;
            }
            $where[] = 'cbt.transaction_type IN (' . implode(', ', $placeholders) . ')';
        }

        if (!empty($filters['period_id'])) {
            $where[] = 'cbt.accounting_period_id = :period_id';
            $params[':period_id'] = (int) $filters['period_id'];
        }

        if (!empty($filters['cycle_id'])) {
            $where[] = 'cbt.cycle_id = :cycle_id';
            $params[':cycle_id'] = (int) $filters['cycle_id'];
        }

        if (!empty($filters['reference_type'])) {
            $where[] = 'cbt.reference_type = :ref_type';
            $params[':ref_type'] = $filters['reference_type'];
        }

        // BS range filters compare on the composite year*12+month ordinal so a
        // range spanning a year boundary works correctly.
        if (!empty($filters['bs_year_from']) && !empty($filters['bs_month_from'])) {
            $where[] = '(cbt.transaction_date_bs_year * 12 + cbt.transaction_date_bs_month) >= :from_ord';
            $params[':from_ord'] = (int) $filters['bs_year_from'] * 12 + (int) $filters['bs_month_from'];
        }

        if (!empty($filters['bs_year_to']) && !empty($filters['bs_month_to'])) {
            $where[] = '(cbt.transaction_date_bs_year * 12 + cbt.transaction_date_bs_month) <= :to_ord';
            $params[':to_ord'] = (int) $filters['bs_year_to'] * 12 + (int) $filters['bs_month_to'];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $stmt = Database::getInstance()->prepare(
            "SELECT cbt.id, cbt.transaction_type, cbt.amount, cbt.reference_type,
                    cbt.reference_id, cbt.description,
                    cbt.transaction_date_bs_year, cbt.transaction_date_bs_month,
                    cbt.transaction_date_ad, cbt.created_at,
                    cbt.accounting_period_id, cbt.cycle_id,
                    (" . self::CASH_DELTA . ") AS cash_delta,
                    (" . self::BANK_DELTA . ") AS bank_delta
               FROM cash_bank_transactions cbt
               {$whereSql}
              ORDER BY cbt.transaction_date_ad ASC, cbt.id ASC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($runningFor === '') {
            return $rows;
        }

        $deltaKey = $runningFor === 'bank' ? 'bank_delta' : 'cash_delta';
        $running  = 0.0;

        foreach ($rows as &$row) {
            $running += (float) $row[$deltaKey];
            $row['running_balance'] = round($running, 2);
        }
        unset($row);

        return $rows;
    }
}
