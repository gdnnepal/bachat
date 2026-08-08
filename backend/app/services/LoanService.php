<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/LoanService.php — Loan disbursement and repayment logic
 *
 * Every money-moving method wraps the loan/repayment write and its matching
 * cash_bank_transactions row in one DB transaction, so the derived
 * Cash_In_Hand can never disagree with the loan ledger.
 *
 * Requirements covered: 7.1–7.11
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Models\AccountingPeriodModel;
use App\Models\CashBankTransactionModel;
use App\Models\LoanModel;
use App\Models\MemberModel;
use App\Models\RepaymentModel;
use App\Helpers\Validator;
use Throwable;

class LoanService
{
    /** Req 7.2: loan amount bounds. */
    private const MIN_LOAN_AMOUNT = 1.0;
    private const MAX_LOAN_AMOUNT = 999999999.0;

    /** Req 7.3: interest rate bounds (percent). */
    private const MIN_INTEREST_RATE = 0.01;
    private const MAX_INTEREST_RATE = 100.0;

    private const RULES_DISBURSE = [
        'member_id'     => 'required|type:int|min:1',
        'loan_amount'   => 'required|type:decimal|decimal_places:2',
        'interest_rate' => 'required|type:decimal|decimal_places:2',
        'remarks'       => 'type:string|maxLength:1000',
    ];

    private const RULES_REPAYMENT = [
        'repayment_type' => 'required|type:enum:PrincipalOnly,InterestOnly,Both',
        'remarks'        => 'type:string|maxLength:255',
    ];

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * @return array{rows: array, balances: array}
     */
    public static function list(array $filters): array
    {
        return [
            'rows'     => LoanModel::listByFilters($filters),
            'balances' => CashBankTransactionModel::getBalances(),
        ];
    }

    /**
     * One loan plus its repayment history.
     *
     * @return array{success: bool, loan?: array, repayments?: array, error?: string}
     */
    public static function find(int $id): array
    {
        $loan = LoanModel::findById($id);
        if ($loan === null) {
            return ['success' => false, 'error' => 'Loan not found.'];
        }

        return [
            'success'    => true,
            'loan'       => $loan,
            'repayments' => RepaymentModel::listByLoan($id),
        ];
    }

    // =========================================================================
    // DISBURSE (Req 7.1, 7.2, 7.3)
    // =========================================================================

    /**
     * @param array $data {member_id, loan_amount, interest_rate, remarks?,
     *                     loan_date_bs_year?, loan_date_bs_month?, loan_date_ad?}
     * @return array{success: bool, loan?: array, error?: string, fields?: array}
     */
    public static function disburse(array $data, int $adminId): array
    {
        $validation = Validator::validate($data, self::RULES_DISBURSE);
        if (!$validation['valid']) {
            return self::validationFailure($validation['errors']);
        }

        $amount = round((float) $data['loan_amount'], 2);
        $rate   = round((float) $data['interest_rate'], 2);

        if ($amount < self::MIN_LOAN_AMOUNT || $amount > self::MAX_LOAN_AMOUNT) {
            return self::validationFailure([
                'loan_amount' => 'Loan amount must be between 1 and 999,999,999.',
            ]);
        }

        if ($rate < self::MIN_INTEREST_RATE || $rate > self::MAX_INTEREST_RATE) {
            return self::validationFailure([
                'interest_rate' => 'Interest rate must be between 0.01% and 100%.',
            ]);
        }

        $member = MemberModel::findById((int) $data['member_id']);
        if ($member === null) {
            return ['success' => false, 'error' => 'Member not found.'];
        }
        if ((int) $member['status'] !== 1) {
            return ['success' => false, 'error' => 'Loans can only be disbursed to active members.'];
        }

        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['success' => false, 'error' => 'No open accounting period found.'];
        }

        // Req 7.3: cooperative cannot lend more cash than it holds.
        $cash = CashBankTransactionModel::getCashBalance();
        if ($amount > $cash) {
            return [
                'success' => false,
                'error'   => "Insufficient cash in hand. Available: NPR {$cash}, requested: NPR {$amount}.",
            ];
        }

        $periodId = (int) $period['id'];
        $cycleId  = (int) $period['cycle_id'];
        $dates    = self::resolveDates($data, 'loan_date');

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $loanId = LoanModel::create([
                'cycle_id'             => $cycleId,
                'accounting_period_id' => $periodId,
                'member_id'            => (int) $member['id'],
                'loan_amount'          => $amount,
                'interest_rate'        => $rate,
                'loan_date_bs_year'    => $dates['bs_year'],
                'loan_date_bs_month'   => $dates['bs_month'],
                'loan_date_ad'         => $dates['ad'],
                'remarks'              => $data['remarks'] ?? null,
                'created_by'           => $adminId,
            ]);

            CashBankTransactionModel::create([
                'cycle_id'                  => $cycleId,
                'accounting_period_id'      => $periodId,
                'transaction_type'          => 'CashOut',
                'amount'                   => $amount,
                'reference_type'            => 'LoanDisbursement',
                'reference_id'              => $loanId,
                'description'               => "Loan disbursed to {$member['member_id']} — {$member['full_name']}",
                'transaction_date_bs_year'  => $dates['bs_year'],
                'transaction_date_bs_month' => $dates['bs_month'],
                'transaction_date_ad'       => $dates['ad'],
                'created_by'                => $adminId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'error' => 'Loan disbursement failed: ' . $e->getMessage()];
        }

        AuditLogger::log(
            AuditLogger::LOAN_DISBURSEMENT,
            "Loan #{$loanId}: NPR {$amount} to {$member['member_id']} ({$member['full_name']}) at {$rate}%.",
            $adminId
        );

        return ['success' => true, 'loan' => LoanModel::findById($loanId)];
    }

    // =========================================================================
    // REPAYMENT (Req 7.4–7.9)
    // =========================================================================

    /**
     * @param array $data {repayment_type, principal?, interest?, remarks?,
     *                     repayment_date_bs_year?, repayment_date_bs_month?, repayment_date_ad?}
     * @return array{success: bool, repayment_id?: int, loan?: array,
     *               loan_completed?: bool, error?: string, fields?: array}
     */
    public static function recordRepayment(int $loanId, array $data, int $adminId): array
    {
        $validation = Validator::validate($data, self::RULES_REPAYMENT);
        if (!$validation['valid']) {
            return self::validationFailure($validation['errors']);
        }

        $loan = LoanModel::findById($loanId);
        if ($loan === null) {
            return ['success' => false, 'error' => 'Loan not found.'];
        }
        if ($loan['loan_status'] !== 'Outstanding') {
            return [
                'success' => false,
                'error'   => "Repayments cannot be recorded against a {$loan['loan_status']} loan.",
            ];
        }

        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['success' => false, 'error' => 'No open accounting period found.'];
        }

        $type        = (string) $data['repayment_type'];
        $outstanding = round((float) $loan['outstanding_principal'], 2);
        $annualRate  = round((float) $loan['interest_rate'], 2);

        // Only the principal leg is taken from the request (Req 7.4). Interest
        // is derived below rather than accepted from the client, so a tampered
        // payload cannot understate what the member owes.
        $principalPaid = 0.0;

        if ($type === 'PrincipalOnly' || $type === 'Both') {
            $principalPaid = round((float) ($data['principal'] ?? 0), 2);
        }

        $fields = [];

        if ($principalPaid < 0) {
            $fields['principal'] = 'Principal cannot be negative.';
        }

        // Req 7.8: over-payment on the principal leg is rejected outright.
        if ($principalPaid > $outstanding) {
            $fields['principal'] = "Principal exceeds outstanding principal of NPR {$outstanding}.";
        }

        // One month's interest on the principal the member is currently holding.
        // It does not depend on how much they choose to repay today: a 7,000
        // balance at 12% owes 70 whether they pay 500 or 5,000. The figure only
        // moves once the principal itself has moved — the next repayment against
        // a reduced 5,000 balance owes 50.
        $interestPaid = $fields === [] ? self::monthlyInterestDue($outstanding, $annualRate) : 0.0;

        if ($principalPaid + $interestPaid <= 0) {
            $fields['amount'] = 'Repayment amount must be greater than zero.';
        }

        if ($fields !== []) {
            return [
                'success' => false,
                'error'   => 'Repayment rejected: the submitted amounts are not valid for this loan.',
                'fields'  => $fields,
            ];
        }

        $total    = round($principalPaid + $interestPaid, 2);
        $periodId = (int) $period['id'];
        $cycleId  = (int) $period['cycle_id'];
        $dates    = self::resolveDates($data, 'repayment_date');

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        $loanCompleted = false;

        try {
            $repaymentId = RepaymentModel::create([
                'loan_id'                 => $loanId,
                'cycle_id'                => $cycleId,
                'accounting_period_id'    => $periodId,
                'repayment_type'          => $type,
                'amount'                  => $total,
                'principal_paid'          => $principalPaid,
                'interest_paid'           => $interestPaid,
                'repayment_date_bs_year'  => $dates['bs_year'],
                'repayment_date_bs_month' => $dates['bs_month'],
                'repayment_date_ad'       => $dates['ad'],
                'remarks'                 => $data['remarks'] ?? null,
                'created_by'              => $adminId,
            ]);

            LoanModel::applyRepayment($loanId, $principalPaid, $interestPaid, $adminId);

            CashBankTransactionModel::create([
                'cycle_id'                  => $cycleId,
                'accounting_period_id'      => $periodId,
                'transaction_type'          => 'CashIn',
                'amount'                    => $total,
                'reference_type'            => 'LoanRepayment',
                'reference_id'              => $repaymentId,
                'description'               => "Repayment on loan #{$loanId} — {$loan['member_code']} ({$type})",
                'transaction_date_bs_year'  => $dates['bs_year'],
                'transaction_date_bs_month' => $dates['bs_month'],
                'transaction_date_ad'       => $dates['ad'],
                'created_by'                => $adminId,
            ]);

            // Req 7.7: a loan with nothing left owing closes itself.
            $after = LoanModel::findById($loanId);
            if ($after !== null
                && round((float) $after['outstanding_principal'], 2) <= 0.0
                && round((float) $after['accrued_interest'], 2) <= 0.0) {
                LoanModel::complete($loanId, $adminId);
                $loanCompleted = true;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'error' => 'Repayment failed: ' . $e->getMessage()];
        }

        AuditLogger::log(
            AuditLogger::LOAN_REPAYMENT,
            "Loan #{$loanId} repayment ({$type}): principal NPR {$principalPaid}, interest NPR {$interestPaid}.",
            $adminId
        );

        if ($loanCompleted) {
            AuditLogger::log(
                AuditLogger::LOAN_COMPLETED,
                "Loan #{$loanId} fully repaid and marked Completed.",
                $adminId
            );
        }

        return [
            'success'        => true,
            'repayment_id'   => $repaymentId,
            'loan'           => LoanModel::findById($loanId),
            'loan_completed' => $loanCompleted,
        ];
    }

    // =========================================================================
    // EDIT / CANCEL (Req 7.10, 7.11)
    // =========================================================================

    /**
     * @param array $data {interest_rate?, remarks?}
     * @return array{success: bool, loan?: array, error?: string, fields?: array}
     */
    public static function edit(int $loanId, array $data, int $adminId): array
    {
        $loan = LoanModel::findById($loanId);
        if ($loan === null) {
            return ['success' => false, 'error' => 'Loan not found.'];
        }

        $changes = [];

        if (array_key_exists('interest_rate', $data) && $data['interest_rate'] !== '') {
            $rate = round((float) $data['interest_rate'], 2);
            if ($rate < self::MIN_INTEREST_RATE || $rate > self::MAX_INTEREST_RATE) {
                return self::validationFailure([
                    'interest_rate' => 'Interest rate must be between 0.01% and 100%.',
                ]);
            }
            if ($rate !== round((float) $loan['interest_rate'], 2)) {
                $changes['interest_rate'] = $rate;
            }
        }

        if (array_key_exists('remarks', $data)
            && (string) $data['remarks'] !== (string) ($loan['remarks'] ?? '')) {
            $changes['remarks'] = $data['remarks'];
        }

        if ($changes === []) {
            return ['success' => true, 'loan' => $loan];
        }

        LoanModel::update($loanId, $changes, $adminId);

        $detail = implode(', ', array_map(
            static fn (string $k, $v): string => "{$k} → {$v}",
            array_keys($changes),
            array_values($changes)
        ));

        AuditLogger::log(
            AuditLogger::LOAN_EDIT,
            "Loan #{$loanId} edited: {$detail}.",
            $adminId
        );

        return ['success' => true, 'loan' => LoanModel::findById($loanId)];
    }

    /**
     * Cancel a loan. Only an untouched loan can be cancelled — once money has
     * come back the history must stay auditable (Req 7.10).
     *
     * @return array{success: bool, loan?: array, error?: string}
     */
    public static function cancel(int $loanId, array $data, int $adminId): array
    {
        $loan = LoanModel::findById($loanId);
        if ($loan === null) {
            return ['success' => false, 'error' => 'Loan not found.'];
        }
        if ($loan['loan_status'] !== 'Outstanding') {
            return [
                'success' => false,
                'error'   => "Only an Outstanding loan can be cancelled; this loan is {$loan['loan_status']}.",
            ];
        }
        if (RepaymentModel::listByLoan($loanId) !== []) {
            return [
                'success' => false,
                'error'   => 'This loan already has repayments recorded and cannot be cancelled.',
            ];
        }

        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['success' => false, 'error' => 'No open accounting period found.'];
        }

        $amount = round((float) $loan['outstanding_principal'], 2);
        $dates  = self::resolveDates([], 'cancel_date');
        $reason = trim((string) ($data['reason'] ?? ''));

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            LoanModel::cancel($loanId, $adminId);

            // Return the disbursed cash to the box so balances stay truthful.
            if ($amount > 0) {
                CashBankTransactionModel::create([
                    'cycle_id'                  => (int) $period['cycle_id'],
                    'accounting_period_id'      => (int) $period['id'],
                    'transaction_type'          => 'CashIn',
                    'amount'                    => $amount,
                    'reference_type'            => 'LoanDisbursement',
                    'reference_id'              => $loanId,
                    'description'               => "Loan #{$loanId} cancelled — principal returned",
                    'transaction_date_bs_year'  => $dates['bs_year'],
                    'transaction_date_bs_month' => $dates['bs_month'],
                    'transaction_date_ad'       => $dates['ad'],
                    'created_by'                => $adminId,
                ]);

                LoanModel::applyRepayment($loanId, $amount, 0.0, $adminId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'error' => 'Loan cancellation failed: ' . $e->getMessage()];
        }

        AuditLogger::log(
            AuditLogger::LOAN_CANCELLED,
            "Loan #{$loanId} cancelled; NPR {$amount} returned to cash." .
            ($reason === '' ? '' : " Reason: {$reason}"),
            $adminId
        );

        return ['success' => true, 'loan' => LoanModel::findById($loanId)];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * One month's interest on the principal a member currently holds.
     *
     * The cooperative quotes an annual rate and meets monthly, so the charge is
     * rate/12 applied to the outstanding balance as it stands before the
     * repayment is applied:
     *
     *     7,000 outstanding at 12%  →  7,000 × 1%  =  70.00
     *     5,000 outstanding at 12%  →  5,000 × 1%  =  50.00
     *
     * The amount repaid today does not change it — interest is owed on the
     * money that was actually held over the month. It falls to 50 only on the
     * next repayment, once the principal has genuinely dropped to 5,000.
     *
     * NOTE: this is charged at repayment time, not accrued at Month_Close.
     * LoanModel::addAccruedInterest() has no callers anywhere in the codebase,
     * so loans.accrued_interest is always 0 and cannot be the basis for what is
     * collectable — gating on it rejected every interest payment.
     */
    private static function monthlyInterestDue(float $outstanding, float $annualRate): float
    {
        if ($outstanding <= 0 || $annualRate <= 0) {
            return 0.0;
        }

        return round($outstanding * $annualRate / 12 / 100, 2);
    }

    /**
     * Resolve the BS/AD date triple for a transaction. Falls back to today when
     * the client did not send an explicit date.
     *
     * @return array{bs_year: int, bs_month: int, ad: string}
     */
    private static function resolveDates(array $data, string $prefix): array
    {
        $bsYear  = isset($data["{$prefix}_bs_year"])  ? (int) $data["{$prefix}_bs_year"]  : 0;
        $bsMonth = isset($data["{$prefix}_bs_month"]) ? (int) $data["{$prefix}_bs_month"] : 0;
        $bsDay   = isset($data["{$prefix}_bs_day"])   ? (int) $data["{$prefix}_bs_day"]   : 1;

        if ($bsYear > 0 && $bsMonth >= 1 && $bsMonth <= 12) {
            return [
                'bs_year'  => $bsYear,
                'bs_month' => $bsMonth,
                'ad'       => BsCalendar::bsToAd($bsYear, $bsMonth, max(1, $bsDay)),
            ];
        }

        $todayAd = date('Y-m-d');
        $todayBs = BsCalendar::adToBs($todayAd);

        return [
            'bs_year'  => (int) $todayBs['year'],
            'bs_month' => (int) $todayBs['month'],
            'ad'       => $todayAd,
        ];
    }

    /**
     * @param array<string, string> $errors
     * @return array{success: false, error: string, fields: array}
     */
    private static function validationFailure(array $errors): array
    {
        return [
            'success' => false,
            'error'   => 'Validation failed.',
            'fields'  => $errors,
        ];
    }
}
