<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/ReportController.php — All report and export endpoints
 *
 * Routes handled:
 *   GET /reports/monthly
 *   GET /reports/loans
 *   GET /reports/cash-book
 *   GET /reports/bank-book
 *   GET /reports/savings
 *   GET /reports/interest
 *   GET /reports/distribution
 *   GET /reports/audit                       (Req 12.4 — AND-filtered, paginated)
 *   GET /reports/{type}/export?format=pdf|xlsx
 *
 * Every report accepts BS range filters: bs_year_from, bs_month_from,
 * bs_year_to, bs_month_to, plus cycle_id and member_id where meaningful.
 *
 * Requirements covered: 11.1–11.6, 12.4
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ExcelExporter;
use App\Helpers\PdfGenerator;
use App\Helpers\Response;
use App\Services\AuditLogger;
use App\Services\ReportService;

class ReportController
{
    /** Report types that may be requested through the generic export route. */
    private const EXPORTABLE = [
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
    // Named report endpoints
    // =========================================================================

    public static function monthly(array $params, array $body): never
    {
        self::respond('monthly', $params);
    }

    public static function loans(array $params, array $body): never
    {
        self::respond('loans', $params);
    }

    public static function cashBook(array $params, array $body): never
    {
        self::respond('cash-book', $params);
    }

    public static function bankBook(array $params, array $body): never
    {
        self::respond('bank-book', $params);
    }

    public static function savings(array $params, array $body): never
    {
        self::respond('savings', $params);
    }

    public static function interest(array $params, array $body): never
    {
        self::respond('interest', $params);
    }

    public static function distribution(array $params, array $body): never
    {
        self::respond('distribution', $params);
    }

    /**
     * GET /reports/audit — reverse-chronological, 200 rows per page, with the
     * pagination block carried inside report.meta.pagination.
     */
    public static function audit(array $params, array $body): never
    {
        self::respond('audit', $params);
    }

    // =========================================================================
    // GET /reports/{type}/export?format=pdf|xlsx
    // =========================================================================

    /**
     * Build the requested report, write a Report_Export audit entry (Req 11.6),
     * then stream it as a PDF or XLSX download (Req 11.4).
     */
    public static function export(array $params, array $body): never
    {
        $type   = (string) ($params['type'] ?? '');
        $format = strtolower((string) ($params['format'] ?? 'pdf'));

        if (!in_array($type, self::EXPORTABLE, true)) {
            Response::error('VALIDATION_ERROR', "Unknown report type: {$type}.", ['type' => 'Unknown report type.'], 422);
        }

        if (!in_array($format, ['pdf', 'xlsx'], true)) {
            Response::error('VALIDATION_ERROR', 'Format must be pdf or xlsx.', ['format' => 'Use pdf or xlsx.'], 422);
        }

        $result = ReportService::build($type, self::filters($params));

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'] ?? 'Report could not be built.', [], 404);
        }

        $report   = $result['data'];
        $filename = $type . '_' . gmdate('Ymd_His');

        AuditLogger::log(
            AuditLogger::REPORT_EXPORT,
            "Exported '{$type}' report as " . strtoupper($format) . '.',
            (int) ($_SESSION['admin_id'] ?? 0)
        );

        if ($format === 'xlsx') {
            ExcelExporter::streamReport($report, $filename);
        }

        // Member statements read better upright; wide ledgers stay landscape.
        PdfGenerator::streamReport($report, $filename);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build a report and emit it as JSON.
     */
    private static function respond(string $type, array $params): never
    {
        $result = ReportService::build($type, self::filters($params));

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'] ?? 'Report could not be built.', [], 404);
        }

        Response::success($result['data'], 'Report generated.');
    }

    /**
     * Normalise the query string into the filter bag the service expects.
     * Absent filters are dropped so the service applies its own defaults.
     *
     * @return array<string, mixed>
     */
    private static function filters(array $params): array
    {
        $filters = [
            'member_id'     => self::intOrNull($params['member_id'] ?? null),
            'cycle_id'      => self::intOrNull($params['cycle_id'] ?? null),
            'period_id'     => self::intOrNull($params['period_id'] ?? null),
            'bs_year_from'  => self::intOrNull($params['bs_year_from'] ?? null),
            'bs_month_from' => self::intOrNull($params['bs_month_from'] ?? null),
            'bs_year_to'    => self::intOrNull($params['bs_year_to'] ?? null),
            'bs_month_to'   => self::intOrNull($params['bs_month_to'] ?? null),
            'page'          => self::intOrNull($params['page'] ?? null),
            // Audit-report-specific filters (Req 12.4)
            'date_from'      => self::stringOrNull($params['date_from'] ?? null),
            'date_to'        => self::stringOrNull($params['date_to'] ?? null),
            'admin_username' => self::stringOrNull($params['admin_username'] ?? $params['username'] ?? null),
            'action_type'    => self::stringOrNull($params['action_type'] ?? null),
            // Loan-report-specific filter
            'loan_status'    => self::stringOrNull($params['loan_status'] ?? $params['status'] ?? null),
        ];

        return array_filter($filters, static fn ($v): bool => $v !== null);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : trim((string) $value);
    }
}
