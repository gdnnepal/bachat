<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/LoanController.php — Loan disbursement and repayment endpoints
 *
 * Routes handled:
 *   GET    /loans                  (member_id, status, cycle_id filters)
 *   POST   /loans
 *   GET    /loans/{id}
 *   PUT    /loans/{id}
 *   PATCH  /loans/{id}/cancel
 *   POST   /loans/{id}/repayments
 *   GET    /loans/{id}/repayments
 *
 * Requirements covered: 7.1–7.11
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\LoanService;

class LoanController
{
    // =========================================================================
    // GET /loans
    // =========================================================================

    public static function index(array $params, array $body): never
    {
        $filters = array_filter(
            [
                'member_id'     => self::intOrNull($params['member_id'] ?? null),
                'loan_status'   => self::stringOrNull($params['status'] ?? $params['loan_status'] ?? null),
                'cycle_id'      => self::intOrNull($params['cycle_id'] ?? null),
                'bs_year_from'  => self::intOrNull($params['bs_year_from'] ?? null),
                'bs_month_from' => self::intOrNull($params['bs_month_from'] ?? null),
                'bs_year_to'    => self::intOrNull($params['bs_year_to'] ?? null),
                'bs_month_to'   => self::intOrNull($params['bs_month_to'] ?? null),
            ],
            static fn ($v): bool => $v !== null
        );

        $result = LoanService::list($filters);

        Response::success($result, 'Loans retrieved.');
    }

    // =========================================================================
    // POST /loans
    // =========================================================================

    public static function store(array $params, array $body): never
    {
        $result = LoanService::disburse($body, self::currentAdminId());

        if (!$result['success']) {
            self::fail($result);
        }

        Response::success($result['loan'], 'Loan disbursed successfully.', 201);
    }

    // =========================================================================
    // GET /loans/{id}
    // =========================================================================

    public static function show(array $params, array $body): never
    {
        $result = LoanService::find(self::routeId($params));

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'], [], 404);
        }

        Response::success(
            ['loan' => $result['loan'], 'repayments' => $result['repayments']],
            'Loan retrieved.'
        );
    }

    // =========================================================================
    // PUT /loans/{id}
    // =========================================================================

    public static function update(array $params, array $body): never
    {
        $result = LoanService::edit(self::routeId($params), $body, self::currentAdminId());

        if (!$result['success']) {
            self::fail($result);
        }

        Response::success($result['loan'], 'Loan updated successfully.');
    }

    // =========================================================================
    // PATCH /loans/{id}/cancel
    // =========================================================================

    public static function cancel(array $params, array $body): never
    {
        $result = LoanService::cancel(self::routeId($params), $body, self::currentAdminId());

        if (!$result['success']) {
            self::fail($result);
        }

        Response::success($result['loan'], 'Loan cancelled successfully.');
    }

    // =========================================================================
    // POST /loans/{id}/repayments
    // =========================================================================

    public static function addRepayment(array $params, array $body): never
    {
        $result = LoanService::recordRepayment(self::routeId($params), $body, self::currentAdminId());

        if (!$result['success']) {
            self::fail($result);
        }

        // Req 7.7 — the client shows an auto-completion notice when the loan closed.
        $message = !empty($result['loan_completed'])
            ? 'Repayment recorded. The loan is now fully repaid and marked Completed.'
            : 'Repayment recorded successfully.';

        Response::success($result, $message, 201);
    }

    // =========================================================================
    // GET /loans/{id}/repayments
    // =========================================================================

    public static function repayments(array $params, array $body): never
    {
        $result = LoanService::find(self::routeId($params));

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'], [], 404);
        }

        Response::success($result['repayments'], 'Repayments retrieved.');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function currentAdminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    private static function routeId(array $params): int
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            Response::error('VALIDATION_ERROR', 'A valid loan ID is required.', ['id' => 'Invalid ID.'], 422);
        }

        return $id;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Map a failed service result onto the right HTTP status.
     */
    private static function fail(array $result): never
    {
        if (isset($result['fields'])) {
            Response::error('VALIDATION_ERROR', $result['error'], $result['fields'], 422);
        }

        $isMissing = str_contains($result['error'], 'not found');

        Response::error(
            $isMissing ? 'NOT_FOUND' : 'BUSINESS_RULE',
            $result['error'],
            [],
            $isMissing ? 404 : 409
        );
    }
}
