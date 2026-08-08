<?php

/**
 * VCMS — Village Cooperative Management System
 * app/middleware/RbacMiddleware.php — Role-based access control
 *
 * Covers requirements: 2.6, 15.7
 *
 * Execution order in the middleware stack:
 *   AuthMiddleware::handle() → CsrfMiddleware::handle() → RbacMiddleware::handle()
 *
 * Called by routes/api.php for every protected route (non-empty $roles).
 * By the time this middleware runs, AuthMiddleware has already confirmed that a
 * valid, active session exists — so $_SESSION['admin_id'] and
 * $_SESSION['admin_role'] are guaranteed to be present.
 *
 * Role values used in the route table:
 *   ['auth']        — any authenticated admin (Admin or Super_Admin)
 *   ['Super_Admin'] — Super Admin only
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Response;

class RbacMiddleware
{
    /**
     * Enforce role-based access control.
     *
     * Logic:
     *  - If $requiredRoles is empty OR contains only 'auth': any authenticated
     *    admin is allowed.  AuthMiddleware has already verified the session, so
     *    no further check is needed — return immediately.
     *  - If $requiredRoles contains 'Super_Admin': the session role must equal
     *    'Super_Admin'.  Any other role triggers a 403 Forbidden response.
     *
     * On failure the method calls Response::error() which emits JSON and exits,
     * so it never returns normally on failure.
     *
     * @param array<string> $requiredRoles Roles declared for the matched route.
     */
    public static function handle(array $requiredRoles): void
    {
        // No role restriction beyond being authenticated — allow through.
        if (empty($requiredRoles) || $requiredRoles === ['auth']) {
            return;
        }

        // Super_Admin-only route: verify the session role.
        if (in_array('Super_Admin', $requiredRoles, true)) {
            $adminRole = $_SESSION['admin_role'] ?? null;

            if ($adminRole !== 'Super_Admin') {
                Response::error(
                    'FORBIDDEN',
                    'You do not have permission to perform this action.',
                    [],
                    403
                );
                // Response::error() calls exit(); the line below is unreachable
                // but kept for static-analysis tools that do not infer noreturn.
                exit;
            }
        }
    }
}
