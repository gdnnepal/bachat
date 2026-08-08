<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/SavingsController.php — Bulk monthly savings endpoints
 *
 * Routes handled:
 *   GET  /savings/bulk-screen
 *   POST /savings/bulk-collect
 *   GET  /savings
 *
 * Requirements covered: 5.1, 5.2, 5.3, 5.4, 5.5
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Models\SavingTransactionModel;
use App\Services\SavingsService;

class SavingsController
{
    // =========================================================================
    // GET /savings/bulk-screen
    // =========================================================================

    /**
     * Every active member with an `already_paid` flag for the current OPEN
     * period, plus the configured fixed monthly saving amount (Req 5.1).
     */
    public static function bulkScreen(array $params, array $body): never
    {
        $data = SavingsService::bulkScreenData();

        if (isset($data['error'])) {
            Response::error('BUSINESS_RULE', $data['error'], [], 409);
        }

        Response::success($data, 'Bulk collection screen loaded.');
    }

    // =========================================================================
    // POST /savings/bulk-collect
    // =========================================================================

    public static function bulkCollect(array $params, array $body): never
    {
        $memberIds = $body['member_ids'] ?? [];

        if (!is_array($memberIds)) {
            Response::error(
                'VALIDATION_ERROR',
                'member_ids must be an array of member IDs.',
                ['member_ids' => 'Expected an array of member IDs.'],
                422
            );
        }

        // Normalise to positive integers and drop anything unusable.
        $memberIds = array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, $memberIds),
            static fn (int $id): bool => $id > 0
        ));

        if ($memberIds === []) {
            // Req 5.3 — an empty selection is rejected with a descriptive error.
            Response::error(
                'VALIDATION_ERROR',
                'At least one member must be selected.',
                ['member_ids' => 'Select at least one member.'],
                422
            );
        }

        $result = SavingsService::bulkCollect($memberIds, (int) ($_SESSION['admin_id'] ?? 0));

        if (!$result['success']) {
            Response::error('BUSINESS_RULE', $result['error'] ?? 'Bulk collection failed.', [], 409);
        }

        $message = "{$result['saved']} saving(s) recorded.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} duplicate(s) skipped.";
        }

        Response::success(
            [
                'saved'      => $result['saved'],
                'skipped'    => $result['skipped'],
                'duplicates' => $result['duplicates'],
            ],
            $message,
            201
        );
    }

    // =========================================================================
    // GET /savings
    // =========================================================================

    /**
     * Saving transaction history, filterable by member, period and cycle.
     */
    public static function index(array $params, array $body): never
    {
        $filters = [
            'member_id'     => self::intOrNull($params['member_id'] ?? null),
            'period_id'     => self::intOrNull($params['period_id'] ?? null),
            'cycle_id'      => self::intOrNull($params['cycle_id'] ?? null),
            'bs_year_from'  => self::intOrNull($params['bs_year_from'] ?? null),
            'bs_month_from' => self::intOrNull($params['bs_month_from'] ?? null),
            'bs_year_to'    => self::intOrNull($params['bs_year_to'] ?? null),
            'bs_month_to'   => self::intOrNull($params['bs_month_to'] ?? null),
        ];

        $rows = SavingTransactionModel::listByFilters(array_filter(
            $filters,
            static fn ($v): bool => $v !== null
        ));

        Response::success($rows, 'Saving transactions retrieved.');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
