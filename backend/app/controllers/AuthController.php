<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/AuthController.php — Authentication endpoints
 *
 * Routes handled:
 *   POST /auth/login
 *   POST /auth/logout
 *   GET  /auth/csrf-token
 *   GET  /auth/me
 *   PUT  /auth/change-password
 *
 * Requirements covered: 1.1, 1.6, 1.7
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Middleware\CsrfMiddleware;
use App\Models\AdminModel;
use App\Services\AuthService;

class AuthController
{
    // =========================================================================
    // POST /auth/login
    // =========================================================================

    /**
     * Authenticate an admin and start a session.
     *
     * @param array $params Route path params (unused here).
     * @param array $body   Parsed JSON body: {username, password, remember_me?}
     */
    public static function login(array $params, array $body): never
    {
        // Validate input
        $rules = [
            'username' => 'required|type:string|maxLength:50',
            'password' => 'required|type:string|maxLength:255',
        ];
        $validation = Validator::validate($body, $rules);
        if (!$validation['valid']) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', $validation['errors'], 422);
        }

        $username   = trim((string) $body['username']);
        $password   = (string) $body['password'];
        $rememberMe = isset($body['remember_me']) && (bool) $body['remember_me'];

        $ipAddress = self::resolveIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unavailable', 0, 255);

        $result = AuthService::login($username, $password, $rememberMe, $ipAddress, $userAgent);

        if (!$result['success']) {
            Response::error('AUTH_FAILED', $result['error'], [], 401);
        }

        Response::success(
            [
                'admin'      => $result['admin'],
                'csrf_token' => $result['csrf_token'],
            ],
            'Login successful.',
            200
        );
    }

    // =========================================================================
    // POST /auth/logout
    // =========================================================================

    /**
     * Log out the current admin.
     */
    public static function logout(array $params, array $body): never
    {
        $ipAddress = self::resolveIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unavailable', 0, 255);

        AuthService::logout($ipAddress, $userAgent);

        Response::success(null, 'Logged out successfully.');
    }

    // =========================================================================
    // GET /auth/csrf-token
    // =========================================================================

    /**
     * Return a CSRF token for the frontend.
     * Public endpoint — no authentication required.
     */
    public static function csrfToken(array $params, array $body): never
    {
        $token = CsrfMiddleware::getToken();
        Response::success(['csrf_token' => $token], 'CSRF token generated.');
    }

    // =========================================================================
    // GET /auth/me
    // =========================================================================

    /**
     * Return the currently authenticated admin's profile.
     */
    public static function me(array $params, array $body): never
    {
        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $admin   = AdminModel::findById($adminId);

        if ($admin === null) {
            Response::error('NOT_FOUND', 'Admin not found.', [], 404);
        }

        Response::success([
            'id'         => (int) $admin['id'],
            'name'       => $admin['name'],
            'username'   => $admin['username'],
            'phone'      => $admin['phone'],
            'role'       => $admin['role'],
            'status'     => (int) $admin['status'],
            'created_at' => $admin['created_at'],
        ]);
    }

    // =========================================================================
    // PUT /auth/change-password
    // =========================================================================

    /**
     * Change the password for the currently authenticated admin.
     *
     * Body: {current_password, new_password}
     */
    public static function changePassword(array $params, array $body): never
    {
        $rules = [
            'current_password' => 'required|type:string',
            'new_password'     => 'required|type:string|minLength:6|maxLength:255',
        ];
        $validation = Validator::validate($body, $rules);
        if (!$validation['valid']) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', $validation['errors'], 422);
        }

        $adminId         = (int) ($_SESSION['admin_id'] ?? 0);
        $currentPassword = (string) $body['current_password'];
        $newPassword     = (string) $body['new_password'];

        $ipAddress = self::resolveIp();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unavailable', 0, 255);

        $result = AuthService::changePassword(
            $adminId,
            $currentPassword,
            $newPassword,
            $ipAddress,
            $userAgent
        );

        if (!$result['success']) {
            Response::error('AUTH_FAILED', $result['error'], [], 400);
        }

        Response::success(null, 'Password changed successfully.');
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private static function resolveIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return substr($ip, 0, 45);
            }
        }
        return substr($_SERVER['REMOTE_ADDR'] ?? 'unavailable', 0, 45);
    }
}
