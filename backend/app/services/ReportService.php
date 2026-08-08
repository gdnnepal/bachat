<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/ReportService.php — All report queries
 *
 * Report shape contract — every report returns:
 *   { title, columns: [{key, label, type}], rows: [...], totals: {...}, meta: {...} }
 * so ReportController, PdfGenerator and ExcelExporter can render any report
 * without knowing which one it is (Req 11.4).
 *
 * Column `type` is one of: text | money | int | date — it drives right-alignment
 * and number formatting in the exporters and in the React table.
 *
 * Requirements covered: 11.1, 11.2, 11.3, 11.5, 11.6, 12.4
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Models\AuditLogModel;
use App\Models\CashBankTransactionModel;
use App\Models\InterestTransactionModel;
use App\Models\LoanModel;
use App\Models\MemberModel;
use App\Models\RepaymentModel;
use App\Models\SavingTransactionModel;
use PDO;

class ReportService
{
    /** Report identifiers accepted by the /reports/{type}/export route. */
    public const TYPES = [
        'member-statement',
        'monthly',
        'loans',
        'cash-book',
        'bank-book',
        'savings',
        'interest',
        'distribution',
        'audit',
    ];

    // =========================================================================
    // Dispatcher
    // =========================================================================

    /**
     * Build any report by its identifier.
     *
     * @param string $type    One of self::TYPES.
     * @param array  $filters Free-form filter bag from the query string.
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public static function build(string $type, array $filters): array
    {
        return match ($type) {
            'member-statement' => self::memberStatement(
                (int) ($filters['member_id'] ?? 0),
                self::intOrNull($filters['bs_year_from']  ?? null),
                self::intOrNull($filters['bs_month_from'] ?? null),
                self::intOrNull($filters['bs_year_to']    ?? null),
                self::intOrNull($filters['bs_month_to']   ?? null)
            ),
            'monthly'      => ['success' => true, 'data' => self::monthlyReport($filters)],
            'loans'        => ['success' => true, 'data' => self::loanReport($filters)],
            'cash-book'    => ['success' => true, 'data' => self::cashBook($filters)],
            'bank-book'    => ['success' => true, 'data' => self::bankBook($filters)],
            'savings'      => ['success' => true, 'data' => self::savingsReport($filters)],
            'interest'     => ['success' => true, 'data' => self::interestReport($filters)],
            'distribution' => ['success' => true, 'data' => self::distributionReport($filters)],
            'audit'        => ['success' => true, 'data' => self::auditReport($filters)],
            default        => ['success' => false, 'error' => "Unknown report type: {$type}."],
        };
    }

    // =========================================================================
    // 1. Member statement (Req 11.2)
    // =========================================================================

    /**
     * Every transaction touching one member, in date order, with a running
     * balance that is the algebraic sum of all signed amounts up to that row.
     *
     * Sign convention — the member's net position with the cooperative:
     *   saving          +amount
     *   interest credit +amount
     *   loan disbursed  −amount
     *   repayment       +amount
     *   distribution    −final_payable
     *
     * The final running balance therefore equals savings + interest −
     * outstanding loan, matching the distribution formula of Req 10.1.
     *
     * @return array{success: bool, data?: array, error?: string}
     */
    public static function memberStatement(
        int $memberId,
        ?int $bsYearFrom = null,
        ?int $bsMonthFrom = null,
        ?int $bsYearTo = null,
        ?int $bsMonthTo = null
    ): array {
        $member = MemberModel::findById($memberId);
        if ($member === null) {
            return ['success' => false, 'error' => 'Member not found.'];
        }

        $range = [
            'bs_year_from'  => $bsYearFrom,
            'bs_month_from' => $bsMonthFrom,
            'bs_year_to'    => $bsYearTo,
            'bs_month_to'   => $bsMonthTo,
        ];
        $filters = array_merge($range, ['member_id' => $memberId]);

        $entries = [];

        foreach (SavingTransactionModel::listByFilters($filters) as $row) {
            $entries[] = self::entry(
                $row['transaction_date_ad'],
                (int) $row['transaction_date_bs_year'],
                (int) $row['transaction_date_bs_month'],
                'Saving',
                $row['remarks'] ?? 'Monthly saving',
                round((float) $row['amount'], 2),
                (int) $row['id']
            );
        }

        foreach (InterestTransactionModel::listByFilters($filters) as $row) {
            $entries[] = self::entry(
                $row['transaction_date_ad'] ?? $row['created_at'],
                (int) ($row['bs_year'] ?? 0),
                (int) ($row['bs_month'] ?? 0),
                'Interest',
                'Savings interest credit',
                round((float) $row['amount'], 2),
                (int) $row['id']
            );
        }

        foreach (LoanModel::listByFilters($filters) as $row) {
            $entries[] = self::entry(
                $row['loan_date_ad'],
                (int) $row['loan_date_bs_year'],
                (int) $row['loan_date_bs_month'],
                'Loan',
                'Loan disbursed at ' . $row['interest_rate'] . '%',
                -round((float) $row['loan_amount'], 2),
                (int) $row['id']
            );
        }

        foreach (RepaymentModel::listByFilters($filters) as $row) {
            $entries[] = self::entry(
                $row['repayment_date_ad'],
                (int) $row['repayment_date_bs_year'],
                (int) $row['repayment_date_bs_month'],
                'Repayment',
                "Repayment ({$row['repayment_type']}): principal {$row['principal_paid']}, interest {$row['interest_paid']}",
                round((float) $row['amount'], 2),
                (int) $row['id']
            );
        }

        foreach (self::memberDistributions($memberId) as $row) {
            $entries[] = self::entry(
                $row['confirmed_at'] ?? $row['created_at'],
                0,
                0,
                'Distribution',
                'Cycle ' . $row['cycle_number'] . ' distribution paid out',
                -round((float) $row['final_payable'], 2),
                (int) $row['id']
            );
        }

        // Chronological order, ties broken by kind then id so the running
        // balance is deterministic for two rows sharing a date.
        usort($entries, static function (array $a, array $b): int {
            return [$a['date_ad'], $a['kind'], $a['ref_id']]
               <=> [$b['date_ad'], $b['kind'], $b['ref_id']];
        });

        $running = 0.0;
        foreach ($entries as &$entry) {
            $running += $entry['amount'];
            $entry['running_balance'] = round($running, 2);
        }
        unset($entry);

        $totals = self::signedTotals($entries);

        return [
            'success' => true,
            'data'    => [
                'title'   => 'Member Statement',
                'columns' => [
                    ['key' => 'date_bs',         'label' => 'Date (BS)',       'type' => 'text'],
                    ['key' => 'date_ad',         'label' => 'Date (AD)',       'type' => 'date'],
                    ['key' => 'kind',            'label' => 'Type',           'type' => 'text'],
                    ['key' => 'description',     'label' => 'Description',    'type' => 'text'],
                    ['key' => 'amount',          'label' => 'Amount',         'type' => 'money'],
                    ['key' => 'running_balance', 'label' => 'Running Balance', 'type' => 'money'],
                ],
                'rows'   => $entries,
                'totals' => $totals,
                'meta'   => [
                    'member'        => $member,
                    'range'         => $range,
                    'final_balance' => round($running, 2),
                ],
            ],
        ];
    }

    // =========================================================================
    // 2. Monthly report (Req 11.1)
    // =========================================================================

    /**
     * One row per accounting period with its aggregate movements.
     */
    public static function monthlyReport(array $filters): array
    {
        $where  = ['ap.status = 1'];
        $params = [];

        if (!empty($filters['cycle_id'])) {
            $where[] = 'ap.cycle_id = :cycle_id';
            $params[':cycle_id'] = (int) $filters['cycle_id'];
        }
        self::applyBsRange($where, $params, 'ap.bs_year', 'ap.bs_month', $filters);

        $stmt = Database::getInstance()->prepare(
            'SELECT ap.id, ap.bs_year, ap.bs_month, ap.period_status, ap.closed_at,
                    c.cycle_number,
                    (SELECT COUNT(*)                FROM saving_transactions   st WHERE st.accounting_period_id = ap.id AND st.status = 1) AS saving_count,
                    (SELECT COALESCE(SUM(amount),0) FROM saving_transactions   st WHERE st.accounting_period_id = ap.id AND st.status = 1) AS total_savings,
                    (SELECT COALESCE(SUM(amount),0) FROM interest_transactions it WHERE it.accounting_period_id = ap.id AND it.status = 1) AS total_interest,
                    (SELECT COALESCE(SUM(loan_amount),0) FROM loans            l  WHERE l.accounting_period_id  = ap.id AND l.status  = 1) AS total_loans,
                    (SELECT COALESCE(SUM(amount),0) FROM repayments            r  WHERE r.accounting_period_id  = ap.id AND r.status  = 1) AS total_repayments
               FROM accounting_periods ap
               JOIN cycles c ON c.id = ap.cycle_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY ap.bs_year ASC, ap.bs_month ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['bs_month_name'] = BsCalendar::bsMonthName((int) $row['bs_month'], 'en');
            $row['period_label']  = $row['bs_year'] . ' ' . $row['bs_month_name'];
        }
        unset($row);

        return [
            'title'   => 'Monthly Report',
            'columns' => [
                ['key' => 'period_label',     'label' => 'Period',      'type' => 'text'],
                ['key' => 'period_status',    'label' => 'Status',      'type' => 'text'],
                ['key' => 'saving_count',     'label' => 'Savers',      'type' => 'int'],
                ['key' => 'total_savings',    'label' => 'Savings',     'type' => 'money'],
                ['key' => 'total_interest',   'label' => 'Interest',    'type' => 'money'],
                ['key' => 'total_loans',      'label' => 'Loans Given', 'type' => 'money'],
                ['key' => 'total_repayments', 'label' => 'Repayments',  'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['total_savings', 'total_interest', 'total_loans', 'total_repayments']),
            'meta'   => ['filters' => $filters],
        ];
    }

    // =========================================================================
    // 3. Loan report
    // =========================================================================

    public static function loanReport(array $filters): array
    {
        $rows = LoanModel::listByFilters($filters);

        foreach ($rows as &$row) {
            $row['loan_date_label'] = $row['loan_date_bs_year'] . ' '
                . BsCalendar::bsMonthName((int) $row['loan_date_bs_month'], 'en');
            $row['total_due'] = round(
                (float) $row['outstanding_principal'] + (float) $row['accrued_interest'],
                2
            );
        }
        unset($row);

        return [
            'title'   => 'Loan Report',
            'columns' => [
                ['key' => 'member_code',           'label' => 'Member ID',   'type' => 'text'],
                ['key' => 'full_name',             'label' => 'Member',      'type' => 'text'],
                ['key' => 'loan_date_label',       'label' => 'Date (BS)',   'type' => 'text'],
                ['key' => 'loan_amount',           'label' => 'Loan',        'type' => 'money'],
                ['key' => 'interest_rate',         'label' => 'Rate %',      'type' => 'money'],
                ['key' => 'outstanding_principal', 'label' => 'Principal',   'type' => 'money'],
                ['key' => 'accrued_interest',      'label' => 'Interest',    'type' => 'money'],
                ['key' => 'total_due',             'label' => 'Total Due',   'type' => 'money'],
                ['key' => 'loan_status',           'label' => 'Status',      'type' => 'text'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['loan_amount', 'outstanding_principal', 'accrued_interest', 'total_due']),
            'meta'   => ['filters' => $filters],
        ];
    }

    // =========================================================================
    // 4 + 5. Cash book / Bank book
    // =========================================================================

    public static function cashBook(array $filters): array
    {
        return self::ledgerReport($filters, 'cash', 'Cash Book');
    }

    public static function bankBook(array $filters): array
    {
        return self::ledgerReport($filters, 'bank', 'Bank Book');
    }

    // =========================================================================
    // 6. Savings report
    // =========================================================================

    public static function savingsReport(array $filters): array
    {
        $rows = SavingTransactionModel::listByFilters($filters);

        foreach ($rows as &$row) {
            $row['date_label'] = $row['transaction_date_bs_year'] . ' '
                . BsCalendar::bsMonthName((int) $row['transaction_date_bs_month'], 'en');
        }
        unset($row);

        return [
            'title'   => 'Savings Report',
            'columns' => [
                ['key' => 'member_code',          'label' => 'Member ID', 'type' => 'text'],
                ['key' => 'full_name',            'label' => 'Member',    'type' => 'text'],
                ['key' => 'date_label',           'label' => 'Period',    'type' => 'text'],
                ['key' => 'transaction_date_ad',  'label' => 'Date (AD)', 'type' => 'date'],
                ['key' => 'amount',               'label' => 'Amount',    'type' => 'money'],
                ['key' => 'remarks',              'label' => 'Remarks',   'type' => 'text'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['amount']),
            'meta'   => ['filters' => $filters],
        ];
    }

    // =========================================================================
    // 7. Interest report
    // =========================================================================

    public static function interestReport(array $filters): array
    {
        $rows = InterestTransactionModel::listByFilters($filters);

        foreach ($rows as &$row) {
            $year  = (int) ($row['bs_year'] ?? 0);
            $month = (int) ($row['bs_month'] ?? 0);
            $row['date_label'] = $month >= 1 && $month <= 12
                ? $year . ' ' . BsCalendar::bsMonthName($month, 'en')
                : (string) $year;
        }
        unset($row);

        return [
            'title'   => 'Interest Report',
            'columns' => [
                ['key' => 'member_code',    'label' => 'Member ID',      'type' => 'text'],
                ['key' => 'full_name',      'label' => 'Member',         'type' => 'text'],
                ['key' => 'date_label',     'label' => 'Period',         'type' => 'text'],
                ['key' => 'balance_before', 'label' => 'Balance Before', 'type' => 'money'],
                ['key' => 'amount',         'label' => 'Interest',       'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['amount']),
            'meta'   => ['filters' => $filters],
        ];
    }

    // =========================================================================
    // 8. Distribution report
    // =========================================================================

    public static function distributionReport(array $filters): array
    {
        $where  = ['di.status = 1'];
        $params = [];

        if (!empty($filters['cycle_id'])) {
            $where[] = 'di.cycle_id = :cycle_id';
            $params[':cycle_id'] = (int) $filters['cycle_id'];
        }
        if (!empty($filters['member_id'])) {
            $where[] = 'di.member_id = :member_id';
            $params[':member_id'] = (int) $filters['member_id'];
        }

        $stmt = Database::getInstance()->prepare(
            'SELECT di.id, c.cycle_number, m.member_id AS member_code, m.full_name,
                    di.total_savings, di.total_interest, di.total_outstanding_loan,
                    di.final_payable, di.is_shortfall,
                    d.distribution_status, d.confirmed_at
               FROM distribution_items di
               JOIN distributions d ON d.id = di.distribution_id
               JOIN cycles        c ON c.id = di.cycle_id
               JOIN members       m ON m.id = di.member_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY c.cycle_number DESC, m.member_seq ASC'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'title'   => 'Distribution Report',
            'columns' => [
                ['key' => 'cycle_number',           'label' => 'Cycle',         'type' => 'int'],
                ['key' => 'member_code',            'label' => 'Member ID',     'type' => 'text'],
                ['key' => 'full_name',              'label' => 'Member',        'type' => 'text'],
                ['key' => 'total_savings',          'label' => 'Savings',       'type' => 'money'],
                ['key' => 'total_interest',         'label' => 'Interest',      'type' => 'money'],
                ['key' => 'total_outstanding_loan', 'label' => 'Loan',          'type' => 'money'],
                ['key' => 'final_payable',          'label' => 'Final Payable', 'type' => 'money'],
                ['key' => 'distribution_status',    'label' => 'Status',        'type' => 'text'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['total_savings', 'total_interest', 'total_outstanding_loan', 'final_payable']),
            'meta'   => ['filters' => $filters],
        ];
    }

    // =========================================================================
    // 9. Audit report (Req 12.4)
    // =========================================================================

    /**
     * AND-filtered, reverse-chronological, 200 rows per page.
     */
    public static function auditReport(array $filters): array
    {
        $page   = max(1, (int) ($filters['page'] ?? 1));
        $result = AuditLogModel::search($filters, $page, AuditLogModel::PAGE_SIZE);

        return [
            'title'   => 'Audit Report',
            'columns' => [
                ['key' => 'logged_at',      'label' => 'Date/Time (UTC)', 'type' => 'text'],
                ['key' => 'admin_username', 'label' => 'Admin',           'type' => 'text'],
                ['key' => 'action_type',    'label' => 'Action',          'type' => 'text'],
                ['key' => 'description',    'label' => 'Description',     'type' => 'text'],
                ['key' => 'ip_address',     'label' => 'IP',              'type' => 'text'],
                ['key' => 'user_agent',     'label' => 'Browser',         'type' => 'text'],
            ],
            'rows'   => $result['rows'],
            'totals' => [],
            'meta'   => [
                'filters'      => $filters,
                'action_types' => AuditLogModel::distinctActionTypes(),
                'pagination'   => [
                    'total'       => $result['total'],
                    'page'        => $page,
                    'per_page'    => AuditLogModel::PAGE_SIZE,
                    'total_pages' => (int) ceil($result['total'] / AuditLogModel::PAGE_SIZE),
                ],
            ],
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function ledgerReport(array $filters, string $view, string $title): array
    {
        $result = CashBankService::transactions($filters, $view);
        $rows   = $result['rows'];
        $deltaKey = $view === 'bank' ? 'bank_delta' : 'cash_delta';

        foreach ($rows as &$row) {
            $delta = round((float) $row[$deltaKey], 2);
            $row['date_label'] = $row['transaction_date_bs_year'] . ' '
                . BsCalendar::bsMonthName((int) $row['transaction_date_bs_month'], 'en');
            $row['debit']  = $delta > 0 ? $delta : 0.0;
            $row['credit'] = $delta < 0 ? abs($delta) : 0.0;
        }
        unset($row);

        return [
            'title'   => $title,
            'columns' => [
                ['key' => 'date_label',          'label' => 'Period',     'type' => 'text'],
                ['key' => 'transaction_date_ad', 'label' => 'Date (AD)',  'type' => 'date'],
                ['key' => 'transaction_type',    'label' => 'Type',       'type' => 'text'],
                ['key' => 'description',         'label' => 'Particulars', 'type' => 'text'],
                ['key' => 'debit',               'label' => 'In',         'type' => 'money'],
                ['key' => 'credit',              'label' => 'Out',        'type' => 'money'],
                ['key' => 'running_balance',     'label' => 'Balance',    'type' => 'money'],
            ],
            'rows'   => $rows,
            'totals' => self::sumColumns($rows, ['debit', 'credit']),
            'meta'   => [
                'filters'  => $filters,
                'balances' => $result['balances'],
                'view'     => $view,
            ],
        ];
    }

    /**
     * Distribution payouts recorded against one member.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function memberDistributions(int $memberId): array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT di.id, di.final_payable, di.created_at,
                    d.confirmed_at, c.cycle_number
               FROM distribution_items di
               JOIN distributions d ON d.id = di.distribution_id
               JOIN cycles        c ON c.id = di.cycle_id
              WHERE di.member_id = ?
                AND di.status = 1
                AND d.distribution_status = 'Completed'
              ORDER BY c.cycle_number ASC"
        );
        $stmt->execute([$memberId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entry(
        ?string $dateAd,
        int $bsYear,
        int $bsMonth,
        string $kind,
        string $description,
        float $amount,
        int $refId
    ): array {
        $dateAd = $dateAd === null ? '' : substr($dateAd, 0, 10);

        return [
            'date_ad'     => $dateAd,
            'date_bs'     => $bsMonth >= 1 && $bsMonth <= 12
                ? $bsYear . ' ' . BsCalendar::bsMonthName($bsMonth, 'en')
                : '',
            'bs_year'     => $bsYear,
            'bs_month'    => $bsMonth,
            'kind'        => $kind,
            'description' => $description,
            'amount'      => round($amount, 2),
            'ref_id'      => $refId,
        ];
    }

    /**
     * Per-kind totals for the member statement footer.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, float>
     */
    private static function signedTotals(array $entries): array
    {
        $totals = [
            'savings'      => 0.0,
            'interest'     => 0.0,
            'loans'        => 0.0,
            'repayments'   => 0.0,
            'distribution' => 0.0,
        ];

        foreach ($entries as $entry) {
            $amount = (float) $entry['amount'];
            match ($entry['kind']) {
                'Saving'       => $totals['savings']      += $amount,
                'Interest'     => $totals['interest']     += $amount,
                'Loan'         => $totals['loans']        += abs($amount),
                'Repayment'    => $totals['repayments']   += $amount,
                'Distribution' => $totals['distribution'] += abs($amount),
                default        => null,
            };
        }

        return array_map(static fn (float $v): float => round($v, 2), $totals);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string>               $columns
     * @return array<string, float>
     */
    private static function sumColumns(array $rows, array $columns): array
    {
        $totals = array_fill_keys($columns, 0.0);

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $totals[$column] += (float) ($row[$column] ?? 0);
            }
        }

        return array_map(static fn (float $v): float => round($v, 2), $totals);
    }

    /**
     * Append BS-range predicates onto a WHERE list, comparing on the
     * year*12+month ordinal so ranges crossing a year boundary work.
     *
     * @param array<int, string> $where
     * @param array<string, mixed> $params
     */
    private static function applyBsRange(
        array &$where,
        array &$params,
        string $yearColumn,
        string $monthColumn,
        array $filters
    ): void {
        if (!empty($filters['bs_year_from']) && !empty($filters['bs_month_from'])) {
            $where[] = "({$yearColumn} * 12 + {$monthColumn}) >= :from_ord";
            $params[':from_ord'] = (int) $filters['bs_year_from'] * 12 + (int) $filters['bs_month_from'];
        }

        if (!empty($filters['bs_year_to']) && !empty($filters['bs_month_to'])) {
            $where[] = "({$yearColumn} * 12 + {$monthColumn}) <= :to_ord";
            $params[':to_ord'] = (int) $filters['bs_year_to'] * 12 + (int) $filters['bs_month_to'];
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
