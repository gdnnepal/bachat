<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/DistributionService.php — Two-phase cycle distribution
 *
 * Phase 1 — generatePdf(): compute the ledger, persist it, render the A4 PDF
 *           the secretary prints and members sign. Repeatable.
 * Phase 2 — confirm(): after the physical hand-out, settle everything inside
 *           one transaction — cash out, loans closed, cycle archived, next
 *           cycle and its first OPEN period created.
 *
 * final_payable = MAX(0, savings + interest − outstanding loan); a member whose
 * loan exceeds their savings is flagged is_shortfall and paid nothing (Req 10.3).
 *
 * Requirements covered: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Helpers\PdfGenerator;
use App\Models\AccountingPeriodModel;
use App\Models\CashBankTransactionModel;
use App\Models\CycleModel;
use App\Models\DistributionModel;
use App\Models\InterestTransactionModel;
use App\Models\LoanModel;
use App\Models\MemberModel;
use App\Models\SavingTransactionModel;
use App\Models\SettingModel;
use Throwable;

class DistributionService
{
    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Current cycle's ledger — the freshly computed figures plus whatever has
     * already been persisted, so the UI can tell Draft from PdfGenerated.
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public static function current(): array
    {
        $cycle = CycleModel::getActiveCycle();
        if ($cycle === null) {
            return ['success' => false, 'error' => 'No active cycle found.'];
        }

        $cycleId  = (int) $cycle['id'];
        $header   = DistributionModel::getHeaderByCycle($cycleId);
        $computed = self::computeLedger($cycleId);

        return [
            'success' => true,
            'data'    => [
                'cycle'        => $cycle,
                'status'       => $header['distribution_status'] ?? 'Draft',
                'header'       => $header,
                'items'        => $computed['items'],
                'totals'       => $computed['totals'],
                'pdf_ready'    => $header !== null && $header['pdf_path'] !== null,
                'balances'     => CashBankTransactionModel::getBalances(),
            ],
        ];
    }

    /**
     * @return array{success: bool, data?: array, error?: string}
     */
    public static function historyDetail(int $cycleId): array
    {
        $header = DistributionModel::getHeaderByCycle($cycleId);
        if ($header === null) {
            return ['success' => false, 'error' => 'No distribution found for this cycle.'];
        }

        return [
            'success' => true,
            'data'    => [
                'cycle'  => CycleModel::findById($cycleId),
                'header' => $header,
                'items'  => DistributionModel::getItemsByDistribution((int) $header['id']),
            ],
        ];
    }

    // =========================================================================
    // PHASE 1 — generate PDF (Req 10.1, 10.2, 10.3)
    // =========================================================================

    /**
     * @return array{success: bool, data?: array, error?: string}
     */
    public static function generatePdf(int $adminId): array
    {
        $cycle = CycleModel::getActiveCycle();
        if ($cycle === null) {
            return ['success' => false, 'error' => 'No active cycle found.'];
        }

        $cycleId = (int) $cycle['id'];
        $header  = DistributionModel::getHeaderByCycle($cycleId);

        if ($header !== null && $header['distribution_status'] === 'Completed') {
            return [
                'success' => false,
                'error'   => 'This cycle has already been distributed and cannot be regenerated.',
            ];
        }

        $computed = self::computeLedger($cycleId);
        if ($computed['items'] === []) {
            return ['success' => false, 'error' => 'There are no active members to distribute to.'];
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $distributionId = DistributionModel::upsertHeader($cycleId, [
                'distribution_status' => 'PdfGenerated',
                'total_disbursed'     => $computed['totals']['final_payable'],
                'member_count'        => count($computed['items']),
                'created_by'          => $adminId,
            ]);

            DistributionModel::replaceItems($distributionId, $cycleId, $computed['items'], $adminId);

            $pdfPath = PdfGenerator::distributionLedger(
                $computed['items'],
                [
                    'cycle'             => $cycle,
                    'totals'            => $computed['totals'],
                    'cooperative_name'  => SettingModel::get('cooperative_name', 'Cooperative'),
                ]
            );

            DistributionModel::upsertHeader($cycleId, [
                'distribution_status' => 'PdfGenerated',
                'pdf_path'            => basename($pdfPath),
                'pdf_generated_at'    => gmdate('Y-m-d H:i:s'),
                'total_disbursed'     => $computed['totals']['final_payable'],
                'member_count'        => count($computed['items']),
                'created_by'          => $adminId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'error' => 'Distribution ledger generation failed: ' . $e->getMessage()];
        }

        AuditLogger::log(
            AuditLogger::DISTRIBUTION_PDF,
            "Distribution ledger generated for cycle {$cycle['cycle_number']}: " .
            count($computed['items']) . ' members, total payable NPR ' .
            $computed['totals']['final_payable'] . '.',
            $adminId
        );

        return self::current();
    }

    /**
     * Absolute path of the stored PDF for a cycle, or null when absent.
     */
    public static function pdfPath(int $cycleId): ?string
    {
        $header = DistributionModel::getHeaderByCycle($cycleId);
        if ($header === null || empty($header['pdf_path'])) {
            return null;
        }

        $path = PdfGenerator::storageDir() . '/' . basename((string) $header['pdf_path']);

        return is_file($path) ? $path : null;
    }

    // =========================================================================
    // PHASE 2 — confirm (Req 10.4, 10.5, 10.7)
    // =========================================================================

    /**
     * Settle the distribution and roll the cooperative into a fresh cycle.
     *
     * @return array{success: bool, data?: array, error?: string, http?: int}
     */
    public static function confirm(int $adminId): array
    {
        $cycle = CycleModel::getActiveCycle();
        if ($cycle === null) {
            return ['success' => false, 'error' => 'No active cycle found.'];
        }

        $cycleId = (int) $cycle['id'];
        $header  = DistributionModel::getHeaderByCycle($cycleId);

        // Req 10.4: the PDF must exist before money moves.
        if ($header === null || $header['distribution_status'] !== 'PdfGenerated') {
            return [
                'success' => false,
                'error'   => 'Generate and print the distribution ledger PDF before confirming.',
                'http'    => 409,
            ];
        }

        $distributionId = (int) $header['id'];
        $items          = DistributionModel::getItemsByDistribution($distributionId);

        if ($items === []) {
            return ['success' => false, 'error' => 'The distribution ledger is empty.', 'http' => 409];
        }

        $totalDisbursed = 0.0;
        foreach ($items as $item) {
            $totalDisbursed = round($totalDisbursed + (float) $item['final_payable'], 2);
        }

        $cash = CashBankTransactionModel::getCashBalance();
        if ($totalDisbursed > $cash) {
            return [
                'success' => false,
                'error'   => "Insufficient cash in hand. Required NPR {$totalDisbursed}, available NPR {$cash}. " .
                             'Transfer money from the bank first.',
                'http'    => 409,
            ];
        }

        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['success' => false, 'error' => 'No open accounting period found.', 'http' => 409];
        }

        $todayAd = date('Y-m-d');
        $todayBs = BsCalendar::adToBs($todayAd);

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            // 1. Cash out, one row per paid member (shortfall members get nothing).
            foreach ($items as $item) {
                $payable = round((float) $item['final_payable'], 2);
                if ($payable <= 0) {
                    continue;
                }

                CashBankTransactionModel::create([
                    'cycle_id'                  => $cycleId,
                    'accounting_period_id'      => (int) $period['id'],
                    'transaction_type'          => 'CashOut',
                    'amount'                    => $payable,
                    'reference_type'            => 'Distribution',
                    'reference_id'              => (int) $item['id'],
                    'description'               => "Distribution payout to {$item['member_code']} — {$item['full_name']}",
                    'transaction_date_bs_year'  => (int) $todayBs['year'],
                    'transaction_date_bs_month' => (int) $todayBs['month'],
                    'transaction_date_ad'       => $todayAd,
                    'created_by'                => $adminId,
                ]);
            }

            // 2. Outstanding loans are settled against the distribution.
            foreach (LoanModel::outstandingInCycle($cycleId) as $loan) {
                LoanModel::applyRepayment(
                    (int) $loan['id'],
                    round((float) $loan['outstanding_principal'], 2),
                    round((float) $loan['accrued_interest'], 2),
                    $adminId
                );
                LoanModel::complete((int) $loan['id'], $adminId);
            }

            // 3. Archive the distribution and the cycle.
            DistributionModel::markCompleted($distributionId, $totalDisbursed, count($items), $adminId);

            CycleModel::complete(
                $cycleId,
                (int) $period['bs_year'],
                (int) $period['bs_month'],
                $adminId
            );

            // 4. Close the open period so the single-OPEN invariant survives the
            //    hand-over to the new cycle (Req 4.1).
            AccountingPeriodModel::close((int) $period['id'], $adminId, [
                'closed_by_distribution' => true,
                'cycle_id'               => $cycleId,
                'total_disbursed'        => $totalDisbursed,
            ]);

            // 5. Open the next cycle, starting at the month after the closed one.
            $next = BsCalendar::nextBsMonth((int) $period['bs_year'], (int) $period['bs_month']);

            $newCycleNumber = CycleModel::nextCycleNumber();
            $newCycleId     = CycleModel::create([
                'cycle_number'        => $newCycleNumber,
                'name'                => 'Cycle ' . $newCycleNumber,
                'started_at_bs_year'  => $next['year'],
                'started_at_bs_month' => $next['month'],
                'created_by'          => $adminId,
            ]);

            AccountingPeriodModel::create([
                'cycle_id'   => $newCycleId,
                'bs_year'    => $next['year'],
                'bs_month'   => $next['month'],
                'created_by' => $adminId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                'success' => false,
                'error'   => 'Distribution confirmation failed and was rolled back: ' . $e->getMessage(),
                'http'    => 500,
            ];
        }

        AuditLogger::log(
            AuditLogger::DISTRIBUTION_DONE,
            "Cycle {$cycle['cycle_number']} distribution completed: NPR {$totalDisbursed} paid to " .
            count($items) . " members. New cycle {$newCycleNumber} opened at {$next['year']}/{$next['month']}.",
            $adminId
        );

        return [
            'success' => true,
            'data'    => [
                'total_disbursed' => $totalDisbursed,
                'member_count'    => count($items),
                'new_cycle'       => CycleModel::findById($newCycleId),
                'new_period'      => AccountingPeriodModel::getOpenPeriod(),
                'balances'        => CashBankTransactionModel::getBalances(),
            ],
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Compute the per-member ledger for a cycle using three grouped queries
     * (savings, interest, loans) — no per-member round-trips.
     *
     * @return array{items: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    private static function computeLedger(int $cycleId): array
    {
        $savings  = SavingTransactionModel::totalsByMemberInCycle($cycleId);
        $interest = InterestTransactionModel::totalsByMemberInCycle($cycleId);
        $loans    = LoanModel::outstandingByMemberInCycle($cycleId);

        $items  = [];
        $totals = [
            'total_savings'          => 0.0,
            'total_interest'         => 0.0,
            'total_outstanding_loan' => 0.0,
            'final_payable'          => 0.0,
            'shortfall_count'        => 0.0,
        ];

        foreach (MemberModel::listActive() as $member) {
            $memberId = (int) $member['id'];

            $s = round($savings[$memberId]  ?? 0.0, 2);
            $i = round($interest[$memberId] ?? 0.0, 2);
            $l = round($loans[$memberId]    ?? 0.0, 2);

            $net        = round($s + $i - $l, 2);
            $isShortfall = $net < 0;
            $payable     = $isShortfall ? 0.0 : $net;

            $items[] = [
                'member_id'              => $memberId,
                'member_code'            => $member['member_id'],
                'full_name'              => $member['full_name'],
                'total_savings'          => $s,
                'total_interest'         => $i,
                'total_outstanding_loan' => $l,
                'final_payable'          => $payable,
                'is_shortfall'           => $isShortfall,
                'shortfall_amount'       => $isShortfall ? abs($net) : 0.0,
            ];

            $totals['total_savings']          = round($totals['total_savings'] + $s, 2);
            $totals['total_interest']         = round($totals['total_interest'] + $i, 2);
            $totals['total_outstanding_loan'] = round($totals['total_outstanding_loan'] + $l, 2);
            $totals['final_payable']          = round($totals['final_payable'] + $payable, 2);
            $totals['shortfall_count']       += $isShortfall ? 1 : 0;
        }

        return ['items' => $items, 'totals' => $totals];
    }
}
