<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/MemberController.php — Member roster endpoints
 *
 * Routes handled:
 *   GET    /members                 (q, page, per_page, status filters)
 *   POST   /members
 *   GET    /members/{id}
 *   PUT    /members/{id}
 *   DELETE /members/{id}
 *   GET    /members/{id}/statement  (BS year/month range)
 *
 * Requirements covered: 3.1, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 11.2
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\MemberService;
use App\Services\ReportService;

class MemberController
{
    private const DEFAULT_PER_PAGE = 25;

    // =========================================================================
    // GET /members
    // =========================================================================

    public static function index(array $params, array $body): never
    {
        $query   = trim((string) ($params['q'] ?? ''));
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE);
        $status  = isset($params['status']) && $params['status'] !== ''
            ? (int) $params['status']
            : null;

        $result = MemberService::search($query, $page, $perPage, $status);

        Response::paginated(
            $result['rows'],
            $result['total'],
            $result['page'],
            $result['per_page'],
            'Members retrieved.'
        );
    }

    // =========================================================================
    // POST /members
    // =========================================================================

    public static function store(array $params, array $body): never
    {
        $result = MemberService::create($body, self::currentAdminId());

        if (!$result['success']) {
            self::fail($result);
        }

        Response::success($result['member'], 'Member created successfully.', 201);
    }

    // =========================================================================
    // GET /members/{id}
    // =========================================================================

    public static function show(array $params, array $body): never
    {
        $result = MemberService::find(self::routeId($params));

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'], [], 404);
        }

        Response::success($result['member'], 'Member retrieved.');
    }

    // =========================================================================
    // PUT /members/{id}
    // =========================================================================

    public static function update(array $params, array $body): never
    {
        $result = MemberService::update(self::routeId($params), $body, self::currentAdminId());

        if (!$result['success']) {
            self::fail($result);
        }

        Response::success($result['member'], 'Member updated successfully.');
    }

    // =========================================================================
    // DELETE /members/{id}
    // =========================================================================

    public static function destroy(array $params, array $body): never
    {
        $result = MemberService::delete(self::routeId($params), self::currentAdminId());

        if (!$result['success']) {
            // "Member not found" is a 404; a blocking financial history is a
            // domain-rule violation and returns 409 (see design error taxonomy).
            $isMissing = str_contains($result['error'], 'not found');
            Response::error(
                $isMissing ? 'NOT_FOUND' : 'BUSINESS_RULE',
                $result['error'],
                [],
                $isMissing ? 404 : 409
            );
        }

        Response::success(null, 'Member deleted successfully.');
    }

    // =========================================================================
    // GET /members/{id}/statement
    // =========================================================================

    public static function statement(array $params, array $body): never
    {
        $result = ReportService::memberStatement(
            self::routeId($params),
            self::intOrNull($params['bs_year_from']  ?? null),
            self::intOrNull($params['bs_month_from'] ?? null),
            self::intOrNull($params['bs_year_to']    ?? null),
            self::intOrNull($params['bs_month_to']   ?? null)
        );

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'], [], 404);
        }

        Response::success($result['data'], 'Member statement generated.');
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
            Response::error('VALIDATION_ERROR', 'A valid member ID is required.', ['id' => 'Invalid ID.'], 422);
        }

        return $id;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Translate a failed service result into the right HTTP response.
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
