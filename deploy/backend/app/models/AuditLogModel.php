<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/AuditLogModel.php — Insert-only DB access for audit_logs
 *
 * The audit_logs table is protected by BEFORE UPDATE / BEFORE DELETE triggers,
 * so this model deliberately exposes no update or delete methods.
 *
 * Requirements covered: 12.1, 12.2, 12.3, 12.4
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class AuditLogModel
{
    /** Audit Report page size mandated by Requirement 12.4. */
    public const PAGE_SIZE = 200;

    // =========================================================================
    // WRITE (insert-only)
    // =========================================================================

    /**
     * Insert a single audit entry.
     *
     * @param array $data {admin_username, action_type, description,
     *                     ip_address, user_agent, created_by?}
     * @return int The new record's auto-increment ID.
     */
    public static function insert(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs
                (logged_at, admin_username, action_type, description,
                 ip_address, user_agent, status, created_by)
             VALUES (UTC_TIMESTAMP(), :username, :action, :description,
                 :ip, :ua, 1, :created_by)'
        );
        $stmt->execute([
            ':username'    => mb_substr((string) $data['admin_username'], 0, 100),
            ':action'      => mb_substr((string) $data['action_type'], 0, 100),
            ':description' => mb_substr((string) $data['description'], 0, 500),
            ':ip'          => mb_substr((string) ($data['ip_address'] ?? 'unavailable'), 0, 45),
            ':ua'          => mb_substr((string) ($data['user_agent'] ?? 'unavailable'), 0, 255),
            ':created_by'  => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Return the N most recent entries, newest first (Dashboard panel).
     *
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));

        $pdo  = Database::getInstance();
        // LIMIT cannot be bound as a parameter in emulated-prepares-off mode;
        // $limit is clamped to an int above so interpolation is safe here.
        $stmt = $pdo->prepare(
            "SELECT id, logged_at, admin_username, action_type, description, ip_address
               FROM audit_logs
              ORDER BY logged_at DESC, id DESC
              LIMIT {$limit}"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * AND-filtered, reverse-chronological, paginated audit search.
     *
     * @param array $filters {date_from?, date_to?, admin_username?, action_type?}
     * @param int   $page    1-based page number.
     * @param int   $perPage Records per page (default 200 per Req 12.4).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function search(array $filters, int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        $where  = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'logged_at >= :date_from';
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'logged_at <= :date_to';
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['admin_username'])) {
            $where[] = 'admin_username = :username';
            $params[':username'] = $filters['admin_username'];
        }
        if (!empty($filters['action_type'])) {
            $where[] = 'action_type = :action';
            $params[':action'] = $filters['action_type'];
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $pdo = Database::getInstance();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page    = max(1, $page);
        $perPage = max(1, min($perPage, 500));
        $offset  = ($page - 1) * $perPage;

        $stmt = $pdo->prepare(
            "SELECT id, logged_at, admin_username, action_type, description, ip_address, user_agent
               FROM audit_logs
               {$whereSql}
              ORDER BY logged_at DESC, id DESC
              LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);

        return [
            'rows'  => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    /**
     * Distinct action types present in the log — used to populate report filters.
     *
     * @return array<int, string>
     */
    public static function distinctActionTypes(): array
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare('SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type ASC');
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}
