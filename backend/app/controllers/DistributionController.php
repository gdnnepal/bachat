<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/DistributionController.php — End-of-cycle distribution endpoints
 *
 * Two-phase flow matching the cooperative's physical meeting:
 *   Phase 1  POST /distribution/generate-pdf   → signature-ready ledger
 *   Phase 2  POST /distribution/confirm        → atomic payout + new cycle
 *
 * Routes handled:
 *   GET  /distribution/current
 *   POST /distribution/generate-pdf
 *   GET  /distribution/pdf/{cycle_id}
 *   POST /distribution/confirm
 *   GET  /distribution/history
 *   GET  /distribution/history/{cycle_id}
 *
 * Requirements covered: 10.1–10.7
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Models\DistributionModel;
use App\Services\DistributionService;

class DistributionController
{
    // =========================================================================
    // GET /distribution/current
    // =========================================================================

    /**
     * Live ledger preview for the active cycle: per-member savings, interest,
     * outstanding loan, final payable and shortfall flag (Req 10.1).
     */
    public static function current(array $params, array $body): never
    {
        $result = DistributionService::current();

        if (!$result['success']) {
            Response::error('BUSINESS_RULE', $result['error'], [], 409);
        }

        Response::success($result['data'], 'Distribution ledger loaded.');
    }

    // =========================================================================
    // POST /distribution/generate-pdf
    // =========================================================================

    public static function generatePdf(array $params, array $body): never
    {
        $result = DistributionService::generatePdf(self::currentAdminId());

        if (!$result['success']) {
            Response::error('BUSINESS_RULE', $result['error'], [], (int) ($result['http'] ?? 409));
        }

        Response::success($result['data'], 'Distribution ledger PDF generated.', 201);
    }

    // =========================================================================
    // GET /distribution/pdf/{cycle_id}
    // =========================================================================

    /**
     * Stream the stored ledger PDF. The file lives under uploads/backups/,
     * which .htaccess blocks from direct web access, so it is served here
     * behind the authentication middleware.
     */
    public static function downloadPdf(array $params, array $body): never
    {
        $cycleId = self::routeCycleId($params);
        $path    = DistributionService::pdfPath($cycleId);

        if ($path === null || !is_file($path)) {
            Response::error(
                'NOT_FOUND',
                'No distribution PDF has been generated for this cycle yet.',
                [],
                404
            );
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($path);
        exit;
    }

    // =========================================================================
    // POST /distribution/confirm
    // =========================================================================

    /**
     * Phase 2 — a single transaction that pays members out, completes the
     * loans and cycle, and opens the next cycle with its first OPEN period
     * (Req 10.4, 10.5, 10.7). Rejected with 409 when no PDF exists yet.
     */
    public static function confirm(array $params, array $body): never
    {
        $result = DistributionService::confirm(self::currentAdminId());

        if (!$result['success']) {
            $status = (int) ($result['http'] ?? 409);
            Response::error(
                $status >= 500 ? 'INTERNAL_ERROR' : 'BUSINESS_RULE',
                $result['error'],
                [],
                $status
            );
        }

        Response::success($result['data'], 'Distribution confirmed. A new cycle has been opened.');
    }

    // =========================================================================
    // GET /distribution/history
    // =========================================================================

    public static function history(array $params, array $body): never
    {
        Response::success(DistributionModel::history(), 'Distribution history retrieved.');
    }

    // =========================================================================
    // GET /distribution/history/{cycle_id}
    // =========================================================================

    public static function historyDetail(array $params, array $body): never
    {
        $result = DistributionService::historyDetail(self::routeCycleId($params));

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'], [], 404);
        }

        Response::success($result['data'], 'Distribution detail retrieved.');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function currentAdminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    private static function routeCycleId(array $params): int
    {
        $id = (int) ($params['cycle_id'] ?? 0);

        if ($id <= 0) {
            Response::error(
                'VALIDATION_ERROR',
                'A valid cycle ID is required.',
                ['cycle_id' => 'Invalid cycle ID.'],
                422
            );
        }

        return $id;
    }
}
