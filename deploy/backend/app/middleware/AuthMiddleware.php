<?php

/**
 * VCMS — Village Cooperative Management System
 * app/middleware/AuthMiddleware.php — Session validation and timeout
 *
 * Covers requirements: 1.3 (session-based authentication), 2.4 (account status guard)
 *
 * Execution order in the middleware stack:
 *   AuthMiddleware::handle() → CsrfMiddleware::handle() → RbacMiddleware::handle()
 *
 * Called by routes/api.php for every protected route (non-empty $roles).
 * On any failure the method emits a JSON error response via Response::error() and
 * exits — it never returns normally on failure.
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;

class AuthMiddleware
{
    /** Session inactivity timeout in seconds (30 minutes). */
    private const SESSION_TIMEOUT = 1800;

    /**
     * Validate the current session.
     *
     * Checks performed in order:
     *  1. $_SESSION['admin_id'] is present and is a positive integer.
     *  2. $_SESSION['last_active'] is present and the session has not timed out.
     *  3. The admin's account is still Active (status = 1) in the database.
     *
     * On success the method updates $_SESSION['last_active'] to the current
     * timestamp and returns normally.
     *
     * On failure the method calls Response::error() which emits JSON and exits,
     * so this method has no meaningful return value.
     */
    public static function handle(): void
    {
        // ── 1. Session existence check ────────────────────────────────────────
        $adminId = $_SESSION['admin_id'] ?? null;

        if (!is_int($adminId) || $adminId <= 0) {
            Response::error(
                'UNAUTHENTICATED',
                'Authentication required.',
                [],
                401
            );
            // Response::error() calls exit(); the line below is unreachable but
            // kept for static-analysis tools that do not infer noreturn.
            exit;
        }

        // ── 2. Session timeout check ──────────────────────────────────────────
        $lastActive = $_SESSION['last_active'] ?? null;

        if (!is_int($lastActive) || (time() - $lastActive) > self::SESSION_TIMEOUT) {
            session_destroy();
            Response::error(
                'SESSION_EXPIRED',
                'Your session has expired. Please log in again.',
                [],
                401
            );
            exit;
        }

        // ── 3. Refresh the activity timestamp ─────────────────────────────────
        $_SESSION['last_active'] = time();

        // ── 4. Account status check (database) ───────────────────────────────
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT status FROM admins WHERE id = ?');
        $stmt->execute([$adminId]);
        $row = $stmt->fetch();

        // Row missing or status = 0 (Inactive) → reject
        if ($row === false || (int) $row['status'] === 0) {
            session_destroy();
            Response::error(
                'ACCOUNT_INACTIVE',
                'Your account has been deactivated.',
                [],
                401
            );
            exit;
        }
    }
}
