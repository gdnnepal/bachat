<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/CashBankService.php — Cash ⇄ Bank transfers and ledger reads
 *
 * A transfer is recorded as a single signed row: 'CashToBank' subtracts from
 * cash and adds to bank, 'BankToCash' does the reverse (see the DELTA
 * expressions in CashBankTransactionModel). One row per transfer keeps the
 * conservation law (Property 11) impossible to break by half-writing a pair.
 *
 * Requirements covered: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Helpers\Validator;
use App\Models\AccountingPeriodModel;
use App\Models\CashBankTransactionModel;
use Throwable;

class CashBankService
{
    private const RULES_TRANSFER = [
        'direction' => 'required|type:enum:CashToBank,BankToCash',
        'amount'    => 'required|type:decimal|positive|decimal_places:2',
    ];

    /** Ledger view presets used by the Cash Book / Bank Book screens. */
    private const CASH_TYPES = ['CashIn', 'CashOut', 'CashToBank', 'BankToCash'];
    private const BANK_TYPES = ['BankIn', 'BankOut', 'CashToBank', 'BankToCash'];

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * @return array{cash_in_hand: float, bank_balance: float, total: float}
     */
    public static function balances(): array
    {
        $balances = CashBankTransactionModel::getBalances();
        $balances['total'] = round($balances['cash_in_hand'] + $balances['bank_balance'], 2);

        return $balances;
    }

    /**
     * Ledger listing with a running balance.
     *
     * @param array  $filters {period_id?, cycle_id?, bs_year_from?, bs_month_from?,
     *                         bs_year_to?, bs_month_to?}
     * @param string $view    'cash' (default), 'bank' or 'all'.
     *
     * @return array{rows: array, balances: array, view: string}
     */
    public static function transactions(array $filters, string $view = 'cash'): array
    {
        $view = in_array($view, ['cash', 'bank', 'all'], true) ? $view : 'cash';

        if ($view === 'cash') {
            $filters['types'] = self::CASH_TYPES;
        } elseif ($view === 'bank') {
            $filters['types'] = self::BANK_TYPES;
        }

        $runningFor = $view === 'all' ? '' : $view;

        return [
            'rows'     => CashBankTransactionModel::listByFilters($filters, $runningFor),
            'balances' => self::balances(),
            'view'     => $view,
        ];
    }

    // =========================================================================
    // TRANSFER (Req 8.2–8.5)
    // =========================================================================

    /**
     * Move money between the cash box and the bank account.
     *
     * @param array $data {direction: 'CashToBank'|'BankToCash', amount, description?}
     * @return array{success: bool, transaction_id?: int, balances?: array,
     *               error?: string, fields?: array}
     */
    public static function transfer(array $data, int $adminId): array
    {
        $validation = Validator::validate($data, self::RULES_TRANSFER);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error'   => 'Validation failed.',
                'fields'  => $validation['errors'],
            ];
        }

        $direction = (string) $data['direction'];
        $amount    = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            return [
                'success' => false,
                'error'   => 'Validation failed.',
                'fields'  => ['amount' => 'Transfer amount must be greater than zero.'],
            ];
        }

        $balances = CashBankTransactionModel::getBalances();

        // Req 8.4 / 8.5: the source side must hold enough money.
        $sourceLabel   = $direction === 'CashToBank' ? 'Cash in hand' : 'Bank balance';
        $sourceBalance = $direction === 'CashToBank'
            ? $balances['cash_in_hand']
            : $balances['bank_balance'];

        if ($amount > $sourceBalance) {
            return [
                'success' => false,
                'error'   => "Transfer rejected: {$sourceLabel} is NPR {$sourceBalance}.",
                'fields'  => ['amount' => "Amount exceeds available {$sourceLabel} of NPR {$sourceBalance}."],
            ];
        }

        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['success' => false, 'error' => 'No open accounting period found.'];
        }

        $todayAd = date('Y-m-d');
        $todayBs = BsCalendar::adToBs($todayAd);

        $label = $direction === 'CashToBank' ? 'Cash → Bank' : 'Bank → Cash';

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $transactionId = CashBankTransactionModel::create([
                'cycle_id'                  => (int) $period['cycle_id'],
                'accounting_period_id'      => (int) $period['id'],
                'transaction_type'          => $direction,
                'amount'                    => $amount,
                'reference_type'            => 'Transfer',
                'reference_id'              => null,
                'description'               => trim((string) ($data['description'] ?? '')) ?: $label . ' transfer',
                'transaction_date_bs_year'  => (int) $todayBs['year'],
                'transaction_date_bs_month' => (int) $todayBs['month'],
                'transaction_date_ad'       => $todayAd,
                'created_by'                => $adminId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['success' => false, 'error' => 'Transfer failed: ' . $e->getMessage()];
        }

        AuditLogger::log(
            AuditLogger::BANK_TRANSFER,
            "{$label}: NPR {$amount} transferred.",
            $adminId
        );

        return [
            'success'        => true,
            'transaction_id' => $transactionId,
            'balances'       => self::balances(),
        ];
    }

    // =========================================================================
    // MANUAL ADJUSTMENT
    // =========================================================================

    /**
     * Record a standalone cash or bank movement that is not tied to a saving,
     * loan or distribution (e.g. an opening balance or a meeting expense).
     *
     * @param array $data {transaction_type: CashIn|CashOut|BankIn|BankOut,
     *                     amount, description?}
     * @return array{success: bool, transaction_id?: int, balances?: array,
     *               error?: string, fields?: array}
     */
    public static function manualEntry(array $data, int $adminId): array
    {
        $validation = Validator::validate($data, [
            'transaction_type' => 'required|type:enum:CashIn,CashOut,BankIn,BankOut',
            'amount'           => 'required|type:decimal|positive|decimal_places:2',
            'description'      => 'required|type:string|maxLength:255',
        ]);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'error'   => 'Validation failed.',
                'fields'  => $validation['errors'],
            ];
        }

        $type   = (string) $data['transaction_type'];
        $amount = round((float) $data['amount'], 2);

        // Outgoing money may not exceed what is held on that side.
        $balances = CashBankTransactionModel::getBalances();

        if ($type === 'CashOut' && $amount > $balances['cash_in_hand']) {
            return [
                'success' => false,
                'error'   => "Cash in hand is only NPR {$balances['cash_in_hand']}.",
                'fields'  => ['amount' => 'Amount exceeds available cash in hand.'],
            ];
        }
        if ($type === 'BankOut' && $amount > $balances['bank_balance']) {
            return [
                'success' => false,
                'error'   => "Bank balance is only NPR {$balances['bank_balance']}.",
                'fields'  => ['amount' => 'Amount exceeds available bank balance.'],
            ];
        }

        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['success' => false, 'error' => 'No open accounting period found.'];
        }

        $todayAd = date('Y-m-d');
        $todayBs = BsCalendar::adToBs($todayAd);

        $transactionId = CashBankTransactionModel::create([
            'cycle_id'                  => (int) $period['cycle_id'],
            'accounting_period_id'      => (int) $period['id'],
            'transaction_type'          => $type,
            'amount'                    => $amount,
            'reference_type'            => 'Manual',
            'reference_id'              => null,
            'description'               => (string) $data['description'],
            'transaction_date_bs_year'  => (int) $todayBs['year'],
            'transaction_date_bs_month' => (int) $todayBs['month'],
            'transaction_date_ad'       => $todayAd,
            'created_by'                => $adminId,
        ]);

        AuditLogger::log(
            AuditLogger::CASH_TRANSACTION,
            "{$type} NPR {$amount} — {$data['description']}",
            $adminId
        );

        return [
            'success'        => true,
            'transaction_id' => $transactionId,
            'balances'       => self::balances(),
        ];
    }
}
