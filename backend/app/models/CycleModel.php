<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/CycleModel.php — Savings-and-distribution cycle records
 *
 * Requirements covered: 4.1, 10.4, 10.6
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class CycleModel
{
    // =========================================================================
    // READ
    // =========================================================================

    /**
     * The single cycle currently accepting transactions, or null before the
     * first cycle has been opened.
     */
    public static function getActiveCycle(): ?array
    {
        $stmt = Database::getInstance()->prepare(
            "SELECT * FROM cycles
              WHERE cycle_status = 'Active'
              ORDER BY cycle_number DESC
              LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM cycles WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * All cycles, newest first — completed cycles remain viewable forever
     * (Req 10.6).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM cycles ORDER BY cycle_number DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The number the next cycle should carry.
     */
    public static function nextCycleNumber(): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COALESCE(MAX(cycle_number), 0) FROM cycles'
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn() + 1;
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Open a new cycle.
     *
     * @param array $data {cycle_number, name, started_at_bs_year,
     *                     started_at_bs_month, created_by?}
     * @return int New cycle ID.
     */
    public static function create(array $data): int
    {
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare(
            "INSERT INTO cycles
                (cycle_number, name, cycle_status, started_at_bs_year, started_at_bs_month, created_by)
             VALUES
                (:number, :name, 'Active', :bs_year, :bs_month, :created_by)"
        );
        $stmt->execute([
            ':number'     => $data['cycle_number'],
            ':name'       => $data['name'],
            ':bs_year'    => $data['started_at_bs_year'],
            ':bs_month'   => $data['started_at_bs_month'],
            ':created_by' => $data['created_by'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Mark a cycle Completed and stamp its closing BS month.
     */
    public static function complete(int $id, int $endBsYear, int $endBsMonth, ?int $adminId = null): void
    {
        $stmt = Database::getInstance()->prepare(
            "UPDATE cycles
                SET cycle_status      = 'Completed',
                    ended_at_bs_year  = :bs_year,
                    ended_at_bs_month = :bs_month,
                    updated_by        = :updated_by
              WHERE id = :id"
        );
        $stmt->execute([
            ':bs_year'    => $endBsYear,
            ':bs_month'   => $endBsMonth,
            ':updated_by' => $adminId,
            ':id'         => $id,
        ]);
    }
}
