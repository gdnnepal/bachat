<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/AdminController.php — Admin account management endpoints
 *
 * Every route below is Super_Admin-only; RbacMiddleware enforces this from the
 * route table before the controller is reached (Req 2.6, 15.7).
 *
 * Routes handled:
 *   GET    /admins
 *   POST   /admins
 *   GET    /admins/{id}
 *   PUT    /admins/{id}
 *   PATCH  /admins/{id}/status
 *
 * Requirements covered: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\AdminService;

class AdminController
{
    // =========================================================================
    // GET /admins
    // =========================================================================

    public static function index(array $params, array $body): never
    {
        Response::success(AdminService::list(), 'Admins retrieved.');
    }

    // =========================================================================
    // POST /admins
    // =========================================================================

    public static function store(array $params, array $body): never
    {
        $validation = Validator::validate($body, [
            'name'     => 'required|type:string|maxLength:100',
            'username' => 'required|type:string|minLength:3|maxLength:50',
            'password' => 'required|type:string|minLength:6|maxLength:255',
            'phone'    => 'digits:7,15',
            'role'     => 'type:enum:Admin,Super_Admin',
            'status'   => 'type:enum:0,1',
        ]);

        if (!$validation['valid']) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', $validation['errors'], 422);
        }

        $result = AdminService::create($body, self::currentAdminId());

        if (!$result['success']) {
            Response::error(
                'CONFLICT',
                $result['error'],
                isset($result['field']) ? [$result['field'] => $result['error']] : [],
                409
            );
        }

        Response::success(['id' => $result['id']], 'Admin created successfully.', 201);
    }

    // =========================================================================
    // GET /admins/{id}
    // =========================================================================

    public static function show(array $params, array $body): never
    {
        $result = AdminService::find(self::routeId($params));

        if (!$result['success']) {
            Response::error('NOT_FOUND', $result['error'], [], 404);
        }

        Response::success($result['admin'], 'Admin retrieved.');
    }

    // =========================================================================
    // PUT /admins/{id}
    // =========================================================================

    public static function update(array $params, array $body): never
    {
        $validation = Validator::validate($body, [
            'name'     => 'type:string|maxLength:100',
            'username' => 'type:string|minLength:3|maxLength:50',
            'phone'    => 'digits:7,15',
            'role'     => 'type:enum:Admin,Super_Admin',
            'status'   => 'type:enum:0,1',
        ]);

        if (!$validation['valid']) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', $validation['errors'], 422);
        }

        $result = AdminService::update(self::routeId($params), $body, self::currentAdminId());

        if (!$result['success']) {
            $isConflict = isset($result['field']);
            Response::error(
                $isConflict ? 'CONFLICT' : 'NOT_FOUND',
                $result['error'],
                $isConflict ? [$result['field'] => $result['error']] : [],
                $isConflict ? 409 : 404
            );
        }

        Response::success(null, 'Admin updated successfully.');
    }

    // =========================================================================
    // PATCH /admins/{id}/status
    // =========================================================================

    public static function setStatus(array $params, array $body): never
    {
        $validation = Validator::validate($body, [
            'status' => 'required|type:enum:0,1',
        ]);

        if (!$validation['valid']) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', $validation['errors'], 422);
        }

        $result = AdminService::setStatus(
            self::routeId($params),
            (int) $body['status'],
            self::currentAdminId()
        );

        if (!$result['success']) {
            Response::error('BUSINESS_RULE', $result['error'], [], 409);
        }

        Response::success(null, 'Admin status updated.');
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
            Response::error('VALIDATION_ERROR', 'A valid admin ID is required.', ['id' => 'Invalid ID.'], 422);
        }

        return $id;
    }
}
