<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/AuditLogger.php — Central audit trail writer
 *
 * Every service calls AuditLogger::log(...) — never a controller (see design:
 * "Audit logging is written by AuditLogger inside each Service method").
 *
 * A failed audit write must never block or roll back the primary action
 * (Req 12.6); failures are appended to a filesystem log instead.
 *
 * Requirements covered: 12.1, 12.2, 12.5, 12.6
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminModel;
use App\Models\AuditLogModel;
use Throwable;

class AuditLogger
{
    /** Canonical action types (Req 12.1). */
    public const LOGIN                = 'Login';
    public const LOGOUT               = 'Logout';
    public const FAILED_LOGIN         = 'Failed_Login';
    public const ACCOUNT_LOCKOUT      = 'Account_Lockout';
    public const AUTO_LOGIN           = 'Auto_Login';
    public const PASSWORD_CHANGE      = 'Password_Change';
    public const USER_CREATION        = 'User_Creation';
    public const USER_MODIFICATION    = 'User_Modification';
    public const CSRF_REJECTION       = 'CSRF_Rejection';
    public const MEMBER_ADD           = 'Member_Add';
    public const MEMBER_EDIT          = 'Member_Edit';
    public const MEMBER_DELETE        = 'Member_Delete';
    public const MEMBER_STATUS_CHANGE = 'Member_Status_Change';
    public const MONTHLY_SAVING       = 'Monthly_Saving';
    public const LOAN_DISBURSEMENT    = 'Loan_Disbursement';
    public const LOAN_REPAYMENT       = 'Loan_Repayment';
    public const LOAN_EDIT            = 'Loan_Edit';
    public const LOAN_COMPLETED       = 'Loan_Completed';
    public const LOAN_CANCELLED       = 'Loan_Cancelled';
    public const INTEREST_CALCULATION = 'Interest_Calculation';
    public const MONTH_CLOSE          = 'Month_Close';
    public const MONTH_REOPEN         = 'Month_Reopen';
    public const DISTRIBUTION_PDF     = 'Distribution_Pdf_Generated';
    public const DISTRIBUTION_DONE    = 'Distribution_Completed';
    public const CASH_TRANSACTION     = 'Cash_Transaction';
    public const BANK_TRANSFER        = 'Bank_Transfer';
    public const BACKUP               = 'Backup';
    public const RESTORE              = 'Restore';
    public const SETTINGS_CHANGE      = 'Settings_Change';
    public const REPORT_EXPORT        = 'Report_Export';

    /** Cache of admin id → username so a burst of writes hits the DB once. */
    private static array $usernameCache = [];

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Write one audit entry.
     *
     * Never throws: an audit failure must not abort the caller's work.
     *
     * @param string      $actionType  One of the class constants above.
     * @param string      $description Human-readable detail (truncated to 500).
     * @param int|null    $adminId     Acting admin; null for anonymous events.
     * @param string|null $username    Overrides the looked-up username — used
     *                                 for Failed Login, where the submitted
     *                                 string is recorded rather than a real user.
     */
    public static function log(
        string $actionType,
        string $description,
        ?int $adminId = null,
        ?string $username = null
    ): void {
        try {
            AuditLogModel::insert([
                'admin_username' => $username ?? self::resolveUsername($adminId),
                'action_type'    => $actionType,
                'description'    => $description,
                'ip_address'     => self::resolveIp(),
                'user_agent'     => self::resolveUserAgent(),
                'created_by'     => $adminId,
            ]);
        } catch (Throwable $e) {
            self::logFailureToFile($actionType, $description, $e);
        }
    }

    /**
     * Resolve the client IP, honouring a single X-Forwarded-For hop.
     * Returns 'unavailable' when nothing valid can be determined (Req 12.2).
     */
    public static function resolveIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return substr($first, 0, 45);
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP) !== false) {
            return substr($remote, 0, 45);
        }

        return 'unavailable';
    }

    /**
     * Resolve the User-Agent header, or 'unavailable' when absent (Req 12.2).
     */
    public static function resolveUserAgent(): string
    {
        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $ua === '' ? 'unavailable' : substr($ua, 0, 255);
    }

    /**
     * Clear the username cache. Intended for tests.
     */
    public static function resetCache(): void
    {
        self::$usernameCache = [];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Look up an admin's username, preferring the active session so that a
     * logged-in request never pays for an extra query.
     */
    private static function resolveUsername(?int $adminId): string
    {
        if ($adminId === null) {
            return 'system';
        }

        if (isset($_SESSION['admin_id'], $_SESSION['admin_username'])
            && (int) $_SESSION['admin_id'] === $adminId) {
            return (string) $_SESSION['admin_username'];
        }

        if (isset(self::$usernameCache[$adminId])) {
            return self::$usernameCache[$adminId];
        }

        $admin = AdminModel::findById($adminId);
        $name  = $admin['username'] ?? 'unknown';

        self::$usernameCache[$adminId] = $name;

        return $name;
    }

    /**
     * Append the failed entry to backend/public/uploads/logs/audit_fail.log
     * so the trail survives even when the DB write fails (Req 12.6).
     */
    private static function logFailureToFile(
        string $actionType,
        string $description,
        Throwable $e
    ): void {
        try {
            $dir = defined('PUBLIC_PATH')
                ? PUBLIC_PATH . '/uploads/logs'
                : dirname(__DIR__, 2) . '/public/uploads/logs';

            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $line = sprintf(
                "[%s] AUDIT_WRITE_FAILED action=%s reason=%s description=%s%s",
                gmdate('Y-m-d H:i:s'),
                $actionType,
                str_replace(["\r", "\n"], ' ', $e->getMessage()),
                str_replace(["\r", "\n"], ' ', $description),
                PHP_EOL
            );

            @file_put_contents($dir . '/audit_fail.log', $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Nothing further can be done — never propagate.
        }
    }
}
