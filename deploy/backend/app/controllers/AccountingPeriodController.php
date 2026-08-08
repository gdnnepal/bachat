<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/AccountingPeriodController.php — Accounting period endpoints
 *
 * Routes handled:
 *   GET  /accounting-periods
 *   GET  /accounting-periods/current
 *   POST /accounting-periods/month-close
 *   POST /accounting-periods/{id}/reopen   (Super_Admin only)
 *
 * Requirements covered: 4.1, 4.7, 4.8
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Models\AccountingPeriodModel;
use App\Services\AuditLogger;
use App\Services\MonthCloseService;

class AccountingPeriodController
{
    // =========================================================================
    // GET /accounting-periods
    // =========================================================================

    public static function index(array $params, array $body): never
    {
        $cycleId = isset($params['cycle_id']) ? (int) $params['cycle_id'] : null;
        $periods = AccountingPeriodModel::listByCycle($cycleId);
        Response::success($periods);
    }

    // =========================================================================
    // GET /accounting-periods/current
    // =========================================================================

    public static function current(array $params, array $body): never
    {
        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            Response::error('NOT_FOUND', 'No open accounting period found.', [], 404);
        }
        Response::success($period);
    }

    // =========================================================================
    // POST /accounting-periods/month-close
    // =========================================================================

    public static function monthClose(array $params, array $body): never
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $result  = MonthCloseService::close($adminId);

        if (!$result['success']) {
            Response::error('BUSINESS_RULE', $result['message'], [], 409);
        }

        Response::success($result['summary'] ?? null, $result['message']);
    }

    // =========================================================================
    // POST /accounting-periods/{id}/reopen   (Super_Admin only)
    // =========================================================================

    public static function reopen(array $params, array $body): never
    {
        $id      = (int) ($params['id'] ?? 0);
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);

        // Validate reason (Req 4.8)
        $validation = Validator::validate($body, ['reason' => 'required|type:string|minLength:3|maxLength:255']);
        if (!$validation['valid']) {
            Response::error('VALIDATION_ERROR', 'A reason is required to reopen a period.', $validation['errors'], 422);
        }

        $period = AccountingPeriodModel::findById($id);
        if ($period === null) {
            Response::error('NOT_FOUND', 'Accounting period not found.', [], 404);
        }

        // Reject if already OPEN (Req 4.8)
        if ($period['period_status'] === 'OPEN') {
            Response::error('BUSINESS_RULE', 'This period is already open.', [], 409);
        }

        // Close whichever period is currently OPEN (Req 4.7 — ensure single OPEN)
        AccountingPeriodModel::closeAllOpenExcept($id, $adminId);

        // Reopen the requested period
        AccountingPeriodModel::reopen($id, $adminId);

        $reason = trim((string) $body['reason']);
        AuditLogger::log(
            AuditLogger::MONTH_REOPEN,
            "Period ID {$id} ({$period['bs_year']}/{$period['bs_month']}) reopened. Reason: {$reason}",
            $adminId
        );

        Response::success(
            AccountingPeriodModel::findById($id),
            'Accounting period reopened successfully.'
        );
    }
}
