<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/AuthService.php — Authentication business logic
 *
 * Requirements covered: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Middleware\CsrfMiddleware;
use App\Models\AdminModel;

class AuthService
{
    /** Maximum consecutive failed logins before account lock. */
    private const MAX_FAILED_ATTEMPTS = 5;

    /** Account lock duration in seconds (15 minutes). */
    private const LOCK_DURATION_SECONDS = 900;

    /** Remember-me cookie name. */
    private const REMEMBER_COOKIE = 'vcms_remember';

    /** Remember-me token validity in days. */
    private const REMEMBER_DAYS = 14;

    // =========================================================================
    // Login
    // =========================================================================

    /**
     * Attempt to log in with username and password.
     *
     * On success: populates $_SESSION, rotates the CSRF token, returns ['success' => true, 'admin' => ...].
     * On failure: increments fail counter (and locks if threshold reached), returns ['success' => false, 'error' => '...'].
     *
     * @param string $username       Submitted username.
     * @param string $password       Submitted plaintext password.
     * @param bool   $rememberMe     Whether to set a persistent remember-me cookie.
     * @param string $ipAddress      Client IP for audit log.
     * @param string $userAgent      Client UA for audit log.
     * @return array{success: bool, admin?: array, error?: string}
     */
    public static function login(
        string $username,
        string $password,
        bool   $rememberMe,
        string $ipAddress,
        string $userAgent
    ): array {
        // ── 1. Find admin by username ─────────────────────────────────────────
        $admin = AdminModel::findByUsername($username);

        if ($admin === null) {
            self::writeAuditLog(
                $username,
                'Failed_Login',
                "Login failed: username not found. IP: {$ipAddress}",
                $ipAddress,
                $userAgent,
                null
            );
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        // ── 2. Check account is active ────────────────────────────────────────
        if ((int) $admin['status'] === 0) {
            self::writeAuditLog(
                $username,
                'Failed_Login',
                "Login failed: account inactive. IP: {$ipAddress}",
                $ipAddress,
                $userAgent,
                null
            );
            return ['success' => false, 'error' => 'Your account has been deactivated.'];
        }

        // ── 3. Check account lock ─────────────────────────────────────────────
        if ($admin['locked_until'] !== null) {
            $lockedUntil = strtotime($admin['locked_until']);
            if ($lockedUntil !== false && time() < $lockedUntil) {
                $minutesLeft = (int) ceil(($lockedUntil - time()) / 60);
                self::writeAuditLog(
                    $username,
                    'Failed_Login',
                    "Login rejected: account locked for {$minutesLeft} more minute(s). IP: {$ipAddress}",
                    $ipAddress,
                    $userAgent,
                    null
                );
                return [
                    'success' => false,
                    'error'   => "Account is locked. Try again in {$minutesLeft} minute(s).",
                ];
            }
            // Lock expired — clear it
            AdminModel::resetFailedLogin((int) $admin['id']);
            $admin['failed_login_count'] = 0;
            $admin['locked_until']       = null;
        }

        // ── 4. Verify password ────────────────────────────────────────────────
        if (!password_verify($password, $admin['password_hash'])) {
            $newCount = (int) $admin['failed_login_count'] + 1;

            if ($newCount >= self::MAX_FAILED_ATTEMPTS) {
                $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCK_DURATION_SECONDS);
                AdminModel::lockAccount((int) $admin['id'], $lockedUntil);
                self::writeAuditLog(
                    $username,
                    'Account_Locked',
                    "Account locked after {$newCount} failed attempts. IP: {$ipAddress}",
                    $ipAddress,
                    $userAgent,
                    null
                );
                return [
                    'success' => false,
                    'error'   => 'Too many failed attempts. Account locked for 15 minutes.',
                ];
            }

            AdminModel::updateFailedLogin((int) $admin['id'], $newCount);
            self::writeAuditLog(
                $username,
                'Failed_Login',
                "Login failed: wrong password (attempt {$newCount}). IP: {$ipAddress}",
                $ipAddress,
                $userAgent,
                null
            );
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        // ── 5. Success: populate session ──────────────────────────────────────
        AdminModel::resetFailedLogin((int) $admin['id']);

        // Regenerate session ID to prevent session fixation (Req 15.5)
        session_regenerate_id(true);

        $_SESSION['admin_id']       = (int) $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_name']     = $admin['name'];
        $_SESSION['admin_role']     = $admin['role'];
        $_SESSION['last_active']    = time();

        // Rotate CSRF token on login
        $csrfToken = CsrfMiddleware::generateToken();

        // ── 6. Remember-me cookie ─────────────────────────────────────────────
        if ($rememberMe) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + self::REMEMBER_DAYS * 86400);
            AdminModel::updateRememberToken((int) $admin['id'], $token, $expires);

            setcookie(
                self::REMEMBER_COOKIE,
                $token,
                [
                    'expires'  => time() + self::REMEMBER_DAYS * 86400,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                    'secure'   => (defined('APP_ENV') && APP_ENV === 'production'),
                ]
            );
        }

        // ── 7. Audit log ──────────────────────────────────────────────────────
        self::writeAuditLog(
            $admin['username'],
            'Login',
            "Successful login. IP: {$ipAddress}",
            $ipAddress,
            $userAgent,
            (int) $admin['id']
        );

        $safeAdmin = [
            'id'       => (int) $admin['id'],
            'name'     => $admin['name'],
            'username' => $admin['username'],
            'role'     => $admin['role'],
        ];

        return ['success' => true, 'admin' => $safeAdmin, 'csrf_token' => $csrfToken];
    }

    // =========================================================================
    // Remember-me auto-login
    // =========================================================================

    /**
     * Attempt to auto-login using a remember-me cookie.
     *
     * On success: populates $_SESSION, rotates cookie token, returns admin array.
     * On failure: clears the invalid cookie, returns null.
     *
     * @param string $token      Token from the remember-me cookie.
     * @param string $ipAddress
     * @param string $userAgent
     * @return array|null
     */
    public static function rememberLogin(
        string $token,
        string $ipAddress,
        string $userAgent
    ): ?array {
        $admin = AdminModel::findByRememberToken($token);

        if ($admin === null || (int) $admin['status'] === 0) {
            // Clear invalid/expired cookie
            setcookie(self::REMEMBER_COOKIE, '', time() - 3600, '/');
            return null;
        }

        // Rotate token
        $newToken = bin2hex(random_bytes(32));
        $expires  = date('Y-m-d H:i:s', time() + self::REMEMBER_DAYS * 86400);
        AdminModel::updateRememberToken((int) $admin['id'], $newToken, $expires);

        setcookie(
            self::REMEMBER_COOKIE,
            $newToken,
            [
                'expires'  => time() + self::REMEMBER_DAYS * 86400,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (defined('APP_ENV') && APP_ENV === 'production'),
            ]
        );

        // Populate session
        session_regenerate_id(true);
        $_SESSION['admin_id']       = (int) $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_name']     = $admin['name'];
        $_SESSION['admin_role']     = $admin['role'];
        $_SESSION['last_active']    = time();

        CsrfMiddleware::generateToken();

        self::writeAuditLog(
            $admin['username'],
            'Auto_Login',
            "Auto-login via remember-me token. IP: {$ipAddress}",
            $ipAddress,
            $userAgent,
            (int) $admin['id']
        );

        return [
            'id'       => (int) $admin['id'],
            'name'     => $admin['name'],
            'username' => $admin['username'],
            'role'     => $admin['role'],
        ];
    }

    // =========================================================================
    // Logout
    // =========================================================================

    /**
     * Log out the current admin: destroy session, clear cookies, audit log.
     *
     * @param string $ipAddress
     * @param string $userAgent
     * @return void
     */
    public static function logout(string $ipAddress, string $userAgent): void
    {
        $adminId   = (int) ($_SESSION['admin_id']       ?? 0);
        $username  = (string) ($_SESSION['admin_username'] ?? 'unknown');

        // Clear remember-me token from DB and cookie
        if ($adminId > 0) {
            AdminModel::updateRememberToken($adminId, null, null);
        }
        setcookie(self::REMEMBER_COOKIE, '', time() - 3600, '/');

        // Destroy session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();

        self::writeAuditLog(
            $username,
            'Logout',
            "Admin logged out. IP: {$ipAddress}",
            $ipAddress,
            $userAgent,
            $adminId > 0 ? $adminId : null
        );
    }

    // =========================================================================
    // Change password
    // =========================================================================

    /**
     * Change the password for the currently authenticated admin.
     *
     * @param int    $adminId        Current admin's ID (from session).
     * @param string $currentPassword Submitted current plaintext password.
     * @param string $newPassword     New plaintext password.
     * @param string $ipAddress
     * @param string $userAgent
     * @return array{success: bool, error?: string}
     */
    public static function changePassword(
        int    $adminId,
        string $currentPassword,
        string $newPassword,
        string $ipAddress,
        string $userAgent
    ): array {
        $admin = AdminModel::findById($adminId);

        if ($admin === null) {
            return ['success' => false, 'error' => 'Admin not found.'];
        }

        if (!password_verify($currentPassword, $admin['password_hash'])) {
            return ['success' => false, 'error' => 'Current password is incorrect.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        AdminModel::updatePasswordHash($adminId, $newHash, $adminId);

        self::writeAuditLog(
            $admin['username'],
            'Password_Change',
            "Password changed by admin. IP: {$ipAddress}",
            $ipAddress,
            $userAgent,
            $adminId
        );

        return ['success' => true];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Write an entry to the audit_logs table.
     * Exceptions are silently swallowed — audit failure must never block auth.
     */
    private static function writeAuditLog(
        string  $username,
        string  $actionType,
        string  $description,
        string  $ipAddress,
        string  $userAgent,
        ?int    $createdBy
    ): void {
        try {
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs
                    (logged_at, admin_username, action_type, description,
                     ip_address, user_agent, status, created_by)
                 VALUES
                    (NOW(), :username, :action_type, :description,
                     :ip_address, :user_agent, 1, :created_by)'
            );
            $stmt->execute([
                ':username'    => substr($username, 0, 100),
                ':action_type' => $actionType,
                ':description' => substr($description, 0, 500),
                ':ip_address'  => substr($ipAddress, 0, 45),
                ':user_agent'  => substr($userAgent, 0, 255),
                ':created_by'  => $createdBy,
            ]);
        } catch (\Throwable) {
            // Silently continue
        }
    }
}
