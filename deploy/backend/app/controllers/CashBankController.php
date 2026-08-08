<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/CashBankController.php — Cash box and bank account endpoints
 *
 * Routes handled:
 *   GET  /cash-bank/balances
 *   POST /cash-bank/transfer
 *   GET  /cash-bank/transactions   (view=cash|bank|all + BS range filters)
 *
 * Requirements covered: 8.1–8.6
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\CashBankService;

class CashBankController
{
    // =========================================================================
    // GET /cash-bank/balances
    // =========================================================================

    public static function balances(array $params, array $body): never
    {
        Response::success(CashBankService::balances(), 'Balances retrieved.');
    }

    // =========================================================================
    // POST /cash-bank/transfer
    // =========================================================================

    /**
     * Cash→Bank and Bank→Cash transfers (Req 8.2, 8.3).
     * Zero, negative, over-precise and over-balance amounts are rejected
     * with field-level errors by the service (Req 8.4, 8.5).
     */
    public static function transfer(array $params, array $body): never
    {
        $result = CashBankService::transfer($body, self::currentAdminId());

        if (!$result['success']) {
            self::fail($result);
        }

        Response::success(
            [
                'transaction_id' => $result['transaction_id'] ?? null,
                'balances'       => $result['balances'] ?? null,
            ],
            'Transfer completed successfully.',
            201
        );
    }

    // =========================================================================
    // GET /cash-bank/transactions
    // =========================================================================

    public static function transactions(array $params, array $body): never
    {
        $view = (string) ($params['view'] ?? 'cash');

        $filters = array_filter(
            [
                'period_id'     => self::intOrNull($params['period_id'] ?? null),
                'cycle_id'      => self::intOrNull($params['cycle_id'] ?? null),
                'bs_year_from'  => self::intOrNull($params['bs_year_from'] ?? null),
                'bs_month_from' => self::intOrNull($params['bs_month_from'] ?? null),
                'bs_year_to'    => self::intOrNull($params['bs_year_to'] ?? null),
                'bs_month_to'   => self::intOrNull($params['bs_month_to'] ?? null),
            ],
            static fn ($v): bool => $v !== null
        );

        Response::success(
            CashBankService::transactions($filters, $view),
            'Cash/bank transactions retrieved.'
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function currentAdminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    private static function fail(array $result): never
    {
        if (isset($result['fields'])) {
            Response::error('VALIDATION_ERROR', $result['error'], $result['fields'], 422);
        }

        Response::error('BUSINESS_RULE', $result['error'], [], 409);
    }
}
