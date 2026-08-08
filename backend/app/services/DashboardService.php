<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/DashboardService.php — Home screen aggregate
 *
 * The whole payload is assembled from four fixed queries (one aggregate over
 * members/cash/loans, the open period, the active cycle, recent audit rows) so
 * the response stays inside the 1-second budget of Req 9.4 regardless of how
 * many members exist — nothing here is per-member.
 *
 * Requirements covered: 9.1, 9.3, 9.4, 9.5
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Models\AccountingPeriodModel;
use App\Models\AuditLogModel;
use App\Models\CycleModel;
use PDO;

class DashboardService
{
    /** Number of audit rows shown in the Recent Activities panel (Req 9.3). */
    private const RECENT_LIMIT = 10;

    /**
     * @param string $locale 'en' or 'ne' — controls the BS month name.
     *
     * @return array{
     *   cards: array<string, mixed>,
     *   period: array|null,
     *   cycle: array|null,
     *   recent_activities: array
     * }
     */
    public static function summary(string $locale = 'en'): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT
                (SELECT COUNT(*) FROM members WHERE status = 1) AS total_members,
                (SELECT COALESCE(SUM(
                    CASE transaction_type
                        WHEN 'CashIn'     THEN  amount
                        WHEN 'CashOut'    THEN -amount
                        WHEN 'BankToCash' THEN  amount
                        WHEN 'CashToBank' THEN -amount
                        ELSE 0
                    END), 0)
                   FROM cash_bank_transactions WHERE status = 1) AS cash_in_hand,
                (SELECT COALESCE(SUM(
                    CASE transaction_type
                        WHEN 'BankIn'     THEN  amount
                        WHEN 'BankOut'    THEN -amount
                        WHEN 'CashToBank' THEN  amount
                        WHEN 'BankToCash' THEN -amount
                        ELSE 0
                    END), 0)
                   FROM cash_bank_transactions WHERE status = 1) AS bank_balance,
                (SELECT COALESCE(SUM(outstanding_principal + accrued_interest), 0)
                   FROM loans
                  WHERE loan_status = 'Outstanding' AND status = 1) AS outstanding_loan,
                (SELECT COUNT(*)
                   FROM loans
                  WHERE loan_status = 'Outstanding' AND status = 1) AS outstanding_loan_count"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $period = AccountingPeriodModel::getOpenPeriod();
        $cycle  = CycleModel::getActiveCycle();

        $cards = [
            'total_members'            => (int) ($row['total_members'] ?? 0),
            'cash_in_hand'             => round((float) ($row['cash_in_hand'] ?? 0), 2),
            'bank_balance'             => round((float) ($row['bank_balance'] ?? 0), 2),
            'outstanding_loan'         => round((float) ($row['outstanding_loan'] ?? 0), 2),
            'outstanding_loan_count'   => (int) ($row['outstanding_loan_count'] ?? 0),
            'current_bs_year'          => $period === null ? null : (int) $period['bs_year'],
            'current_bs_month'         => $period === null ? null : (int) $period['bs_month'],
            'current_bs_month_name'    => $period === null
                ? null
                : BsCalendar::bsMonthName((int) $period['bs_month'], $locale),
            'current_cycle_number'     => $cycle === null ? null : (int) $cycle['cycle_number'],
            'current_cycle_name'       => $cycle === null ? null : $cycle['name'],
        ];

        return [
            'cards'             => $cards,
            'period'            => $period,
            'cycle'             => $cycle,
            'recent_activities' => AuditLogModel::recent(self::RECENT_LIMIT),
        ];
    }
}
