<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/MonthCloseService.php — Atomic Month_Close operation
 *
 * Requirements covered: 4.3, 4.4, 4.5, 4.6, 4.9, 6.1, 6.2, 6.3, 6.4, 6.5, 6.6
 *
 * The entire Month_Close is wrapped in a single MySQL transaction.
 * On ANY failure after the transaction begins, the whole thing is rolled back
 * and the system is left in its pre-attempt state (Req 4.9).
 *
 * Interest formula: interest = round(balance × 0.01, 2) using PHP_ROUND_HALF_UP
 * where balance = SUM(saving_transactions) + SUM(interest_transactions) for
 * the member in the current cycle (Req 6.1 — compound because prior interest
 * credits are included in the balance).
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Models\AccountingPeriodModel;
use App\Models\CashBankTransactionModel;
use App\Models\CycleModel;
use App\Models\InterestTransactionModel;
use App\Models\MemberModel;
use App\Models\SavingTransactionModel;

class MonthCloseService
{
    /**
     * Execute Month_Close for the current OPEN accounting period.
     *
     * @param int $adminId  ID of the admin triggering the close.
     * @return array{success: bool, message: string, summary?: array}
     */
    public static function close(int $adminId): array
    {
        $pdo = Database::getInstance();

        // ── Pre-transaction checks ────────────────────────────────────────────
        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['success' => false, 'message' => 'No open accounting period found.'];
        }

        $cycle = CycleModel::findById((int) $period['cycle_id']);
        if ($cycle === null) {
            return ['success' => false, 'message' => 'Active cycle not found.'];
        }

        $periodId = (int) $period['id'];
        $cycleId  = (int) $cycle['id'];

        // ── Validation gate (Req 4.3a) ────────────────────────────────────────
        $incomplete = AccountingPeriodModel::incompleteRecords($periodId);
        if (!empty($incomplete)) {
            $details = implode(', ', array_map(
                fn ($label, $count) => "{$count} incomplete {$label}",
                array_keys($incomplete),
                $incomplete
            ));
            return [
                'success' => false,
                'message' => "Month close blocked: {$details}.",
            ];
        }

        // ── BEGIN TRANSACTION ─────────────────────────────────────────────────
        $pdo->beginTransaction();

        try {
            // ── Determine current BS date for new transactions ─────────────────
            $todayAd = date('Y-m-d');
            $todayBs = BsCalendar::adToBs($todayAd);

            // ── Step 4: Interest calculation (Req 6.1, 6.2, 6.3, 6.4, 6.5) ───
            $activeMembers = MemberModel::listActive();

            $interestCount = 0;
            $interestTotal = 0.0;

            foreach ($activeMembers as $member) {
                $memberId = (int) $member['id'];

                // Savings balance = all saving_transactions + all interest_transactions in this cycle
                $savingsTotal  = SavingTransactionModel::totalForMemberInCycle($memberId, $cycleId);
                $interestSoFar = InterestTransactionModel::totalForMemberInCycle($memberId, $cycleId);
                $balance       = $savingsTotal + $interestSoFar;

                if ($balance <= 0) {
                    continue; // Req 6.5: skip zero/negative balance
                }

                $interest = round($balance * 0.01, 2, PHP_ROUND_HALF_UP);

                if ($interest <= 0) {
                    continue;
                }

                InterestTransactionModel::create([
                    'cycle_id'             => $cycleId,
                    'accounting_period_id' => $periodId,
                    'member_id'            => $memberId,
                    'amount'               => $interest,
                    'balance_before'       => $balance,
                    'created_by'           => $adminId,
                ]);

                $interestCount++;
                $interestTotal = round($interestTotal + $interest, 2);
            }

            // ── Step 5c: Close the current period ─────────────────────────────
            // Build summary snapshot (Req 4.3e)
            $savingsSummary  = SavingTransactionModel::periodSummary($periodId);
            $interestSummary = InterestTransactionModel::periodSummary($periodId);
            $balances        = CashBankTransactionModel::balancesAsOfPeriod($periodId);

            // Loan + repayment summary (direct queries since models don't have period-level summaries yet)
            $loanSummaryStmt = $pdo->prepare(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(loan_amount), 0) AS total
                   FROM loans WHERE accounting_period_id = ? AND status = 1"
            );
            $loanSummaryStmt->execute([$periodId]);
            $loanRow = $loanSummaryStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $repayStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) AS total
                   FROM repayments WHERE accounting_period_id = ? AND status = 1"
            );
            $repayStmt->execute([$periodId]);
            $repayRow = $repayStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $summary = [
                'bs_year'                  => (int) $period['bs_year'],
                'bs_month'                 => (int) $period['bs_month'],
                'saving_count'             => $savingsSummary['count'],
                'total_savings'            => $savingsSummary['total'],
                'interest_count'           => $interestCount,
                'total_interest'           => $interestTotal,
                'loan_count'               => (int) ($loanRow['cnt'] ?? 0),
                'total_loans_disbursed'    => round((float) ($loanRow['total'] ?? 0), 2),
                'total_repayments'         => round((float) ($repayRow['total'] ?? 0), 2),
                'closing_cash_in_hand'     => $balances['cash_in_hand'],
                'closing_bank_balance'     => $balances['bank_balance'],
            ];

            AccountingPeriodModel::close($periodId, $adminId, $summary);

            // ── Step 5d: Open next BS month (Req 4.3d, 4.5) ──────────────────
            $next = BsCalendar::nextBsMonth((int) $period['bs_year'], (int) $period['bs_month']);

            AccountingPeriodModel::create([
                'cycle_id'   => $cycleId,
                'bs_year'    => $next['year'],
                'bs_month'   => $next['month'],
                'created_by' => $adminId,
            ]);

            // ── Step 5f: Audit log entries (Req 4.3f, 6.6) ───────────────────
            AuditLogger::log(
                AuditLogger::MONTH_CLOSE,
                "Closed {$period['bs_year']}/{$period['bs_month']}. " .
                "Savings: {$savingsSummary['count']} records, NPR {$savingsSummary['total']}. " .
                "Next period: {$next['year']}/{$next['month']}.",
                $adminId
            );

            AuditLogger::log(
                AuditLogger::INTEREST_CALCULATION,
                "Interest credited: {$interestCount} members, total NPR {$interestTotal}.",
                $adminId
            );

            // ── COMMIT ────────────────────────────────────────────────────────
            $pdo->commit();

            return ['success' => true, 'message' => 'Month closed successfully.', 'summary' => $summary];

        } catch (\Throwable $e) {
            // ── ROLLBACK (Req 4.9) ────────────────────────────────────────────
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'success' => false,
                'message' => 'Month close failed and was rolled back: ' . $e->getMessage(),
            ];
        }
    }
}
