<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/AccountingPeriodModel.php — BS-month accounting period records
 *
 * The system maintains exactly one OPEN period at all times (Req 4.1); this
 * model never opens a period without the caller first closing the incumbent.
 *
 * Requirements covered: 4.1, 4.2, 4.3, 4.5, 4.6, 4.7
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class AccountingPeriodModel
{
    // =========================================================================
    // READ
    // =========================================================================

    /**
     * The single OPEN period, with its cycle name attached, or null when the
     * system has not yet been initialised.
     */
    public static function getOpenPeriod(): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT ap.*, c.name AS cycle_name, c.cycle_number
               FROM accounting_periods ap
               JOIN cycles c ON c.id = ap.cycle_id
              WHERE ap.period_status = 'OPEN'
              ORDER BY ap.bs_year DESC, ap.bs_month DESC
              LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Count of OPEN periods — the invariant of Req 4.1 is that this is always 1
     * once the system is initialised.
     */
    public static function countOpen(): int
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT COUNT(*) FROM accounting_periods WHERE period_status = 'OPEN'"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT ap.*, c.name AS cycle_name, c.cycle_number
               FROM accounting_periods ap
               JOIN cycles c ON c.id = ap.cycle_id
              WHERE ap.id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Find a period by its BS coordinates within a cycle.
     */
    public static function findByBsMonth(int $cycleId, int $bsYear, int $bsMonth): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM accounting_periods
              WHERE cycle_id = ? AND bs_year = ? AND bs_month = ?
              LIMIT 1'
        );
        $stmt->execute([$cycleId, $bsYear, $bsMonth]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List periods, newest first, optionally scoped to one cycle.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listByCycle(?int $cycleId = null): array
    {
        $pdo = Database::getInstance();

        if ($cycleId === null) {
            $stmt = $pdo->prepare(
                'SELECT ap.*, c.name AS cycle_name
                   FROM accounting_periods ap
                   JOIN cycles c ON c.id = ap.cycle_id
                  ORDER BY ap.bs_year DESC, ap.bs_month DESC'
            );
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                'SELECT ap.*, c.name AS cycle_name
                   FROM accounting_periods ap
                   JOIN cycles c ON c.id = ap.cycle_id
                  WHERE ap.cycle_id = ?
                  ORDER BY ap.bs_year DESC, ap.bs_month DESC'
            );
            $stmt->execute([$cycleId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count records in this period whose status is 0 (incomplete) — the
     * Month_Close validation gate of Req 4.3(a).
     *
     * @return array<string, int> Table label => count, only for non-zero counts.
     */
    public static function incompleteRecords(int $periodId): array
    {
        $pdo = Database::getInstance();

        $queries = [
            'saving transactions' => 'SELECT COUNT(*) FROM saving_transactions WHERE accounting_period_id = ? AND status = 0',
            'loans'               => 'SELECT COUNT(*) FROM loans WHERE accounting_period_id = ? AND status = 0',
            'repayments'          => 'SELECT COUNT(*) FROM repayments WHERE accounting_period_id = ? AND status = 0',
        ];

        $blocking = [];

        foreach ($queries as $label => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$periodId]);
            $count = (int) $stmt->fetchColumn();

            if ($count > 0) {
                $blocking[$label] = $count;
            }
        }

        return $blocking;
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Open a new period.
     *
     * @param array $data {cycle_id, bs_year, bs_month, created_by?}
     * @return int New period ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO accounting_periods
                (cycle_id, bs_year, bs_month, period_status, created_by)
             VALUES (:cycle_id, :bs_year, :bs_month, 'OPEN', :created_by)"
        );
        $stmt->execute([
            ':cycle_id'   => $data['cycle_id'],
            ':bs_year'    => $data['bs_year'],
            ':bs_month'   => $data['bs_month'],
            ':created_by' => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Mark a period CLOSED and attach its month summary.
     *
     * @param array|null $summary Summary payload stored as JSON (Req 4.3(e)).
     */
    public static function close(int $id, int $adminId, ?array $summary = null): void
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE accounting_periods
                SET period_status = 'CLOSED',
                    closed_at     = UTC_TIMESTAMP(),
                    closed_by     = :admin_id,
                    summary_json  = :summary,
                    updated_by    = :updated_by
              WHERE id = :id"
        );
        $stmt->execute([
            ':admin_id'   => $adminId,
            ':summary'    => $summary === null
                ? null
                : json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_by' => $adminId,
            ':id'         => $id,
        ]);
    }

    /**
     * Re-open a CLOSED period (Super_Admin only — Req 4.7).
     * The caller is responsible for closing whichever period is currently OPEN.
     */
    public static function reopen(int $id, int $adminId): void
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE accounting_periods
                SET period_status = 'OPEN',
                    closed_at     = NULL,
                    closed_by     = NULL,
                    updated_by    = :updated_by
              WHERE id = :id"
        );
        $stmt->execute([':updated_by' => $adminId, ':id' => $id]);
    }

    /**
     * Close every OPEN period other than $exceptId. Used by the reopen flow to
     * guarantee the single-OPEN-period invariant (Req 4.7).
     *
     * @return int Number of periods closed.
     */
    public static function closeAllOpenExcept(int $exceptId, int $adminId): int
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE accounting_periods
                SET period_status = 'CLOSED',
                    closed_at     = UTC_TIMESTAMP(),
                    closed_by     = :admin_id,
                    updated_by    = :updated_by
              WHERE period_status = 'OPEN' AND id != :except_id"
        );
        $stmt->execute([
            ':admin_id'   => $adminId,
            ':updated_by' => $adminId,
            ':except_id'  => $exceptId,
        ]);

        return $stmt->rowCount();
    }
}
