<?php

/**
 * VCMS — Village Cooperative Management System
 * app/middleware/CsrfMiddleware.php — CSRF token validation
 *
 * Covers requirements: 1.11 (CSRF protection on state-changing requests),
 *                       15.8 (audit log on CSRF violation),
 *                       15.9 (hash_equals constant-time comparison)
 *
 * Execution order in the middleware stack:
 *   AuthMiddleware::handle() → CsrfMiddleware::handle() → RbacMiddleware::handle()
 *
 * The token lifecycle:
 *  - GET /auth/csrf-token  → AuthController calls getToken() and returns it to the SPA.
 *  - POST /auth/login      → AuthService calls generateToken() to rotate on login.
 *  - All other state-changing requests → handle() validates the X-CSRF-Token header.
 *  - No automatic rotation on every request (rotation happens only in AuthService on login).
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Database;
use App\Helpers\Response;

class CsrfMiddleware
{
    /**
     * HTTP methods that do NOT mutate state — CSRF validation is skipped.
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    // ──────────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate the CSRF token for state-changing requests.
     *
     * Safe methods (GET, HEAD, OPTIONS) — including GET /auth/csrf-token —
     * are passed through immediately without any token check.
     *
     * For POST, PUT, PATCH, DELETE the method reads the X-CSRF-Token header
     * and compares it against the session token with hash_equals() to prevent
     * timing-attack leakage.
     *
     * On failure:
     *  1. An audit log entry is written to the audit_logs table (silently on
     *     DB error — audit failure must not suppress the 403 response).
     *  2. Response::error('CSRF_ERROR', ..., 403) is called, which exits.
     *
     * On success the method returns normally; the next middleware or controller
     * takes over.
     */
    public static function handle(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Skip validation entirely for safe HTTP methods.
        // This also covers GET /auth/csrf-token, which is already a GET request.
        if (in_array($method, self::SAFE_METHODS, true)) {
            return;
        }

        // ── Read the token from the request header ────────────────────────────
        // PHP translates the header X-CSRF-Token → HTTP_X_CSRF_TOKEN in $_SERVER.
        $headerToken   = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $sessionToken  = $_SESSION['csrf_token']       ?? null;

        // ── Constant-time comparison ──────────────────────────────────────────
        // hash_equals() requires both arguments to be non-empty strings.
        $valid = is_string($headerToken)
              && is_string($sessionToken)
              && hash_equals($sessionToken, $headerToken);

        if (!$valid) {
            self::writeViolationLog();

            Response::error(
                'CSRF_ERROR',
                'Invalid or missing CSRF token.',
                [],
                403
            );
            // Response::error() calls exit(); the line below is unreachable but
            // satisfies static-analysis tools that do not infer noreturn.
            exit;
        }
    }

    /**
     * Generate a new CSRF token, persist it in the session, and return it.
     *
     * Produces a 64-character lowercase hex string from 32 cryptographically
     * secure random bytes.  Overwrites any previously stored token.
     *
     * @return string The newly generated 64-char hex token.
     */
    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * Return the current CSRF token, generating one if none exists in the session.
     *
     * @return string The current (or newly generated) 64-char hex token.
     */
    public static function getToken(): string
    {
        if (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token'])) {
            return $_SESSION['csrf_token'];
        }

        return self::generateToken();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Write a CSRF_Violation entry to the audit_logs table.
     *
     * Uses a direct PDO INSERT rather than AuditLogger (which may not exist yet
     * at this stage of the build).  Any database exception is silently swallowed
     * so that an audit failure never blocks the 403 response to the client.
     */
    private static function writeViolationLog(): void
    {
        $ip        = self::resolveIpAddress();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unavailable', 0, 255);
        $username  = isset($_SESSION['admin_username']) && is_string($_SESSION['admin_username'])
                     ? $_SESSION['admin_username']
                     : 'anonymous';

        $description = sprintf(
            'CSRF token mismatch from IP: %s, UA: %s',
            $ip,
            $userAgent
        );

        try {
            $pdo = Database::getInstance();

            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs
                    (logged_at, admin_username, action_type, description,
                     ip_address, user_agent, status, created_by)
                 VALUES
                    (NOW(), :username, :action_type, :description,
                     :ip_address, :user_agent, 1, NULL)'
            );

            $stmt->execute([
                ':username'    => $username,
                ':action_type' => 'CSRF_Violation',
                ':description' => $description,
                ':ip_address'  => $ip,
                ':user_agent'  => $userAgent,
            ]);
        } catch (\Throwable) {
            // Silently continue — audit failure must not block the 403 response.
        }
    }

    /**
     * Resolve the client IP address from the request.
     *
     * Checks X-Forwarded-For first (for proxied environments) then falls back
     * to REMOTE_ADDR.  Returns 'unavailable' if neither is present.
     *
     * @return string Resolved IP address string (max 45 chars for IPv6).
     */
    private static function resolveIpAddress(): string
    {
        // X-Forwarded-For may be a comma-separated list; take the first entry.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip        = trim($forwarded[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return substr($ip, 0, 45);
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? 'unavailable';
        return substr($remote, 0, 45);
    }
}
