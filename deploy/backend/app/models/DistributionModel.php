<?php

/**
 * VCMS — Village Cooperative Management System
 * app/models/DistributionModel.php — Distribution header + per-member items
 *
 * One distribution row per cycle (UNIQUE on cycle_id), so the two-phase flow
 * (generate PDF → confirm) always operates on the same header.
 *
 * Requirements covered: 10.1, 10.2, 10.4, 10.5, 10.6
 */

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use PDO;

class DistributionModel
{
    // =========================================================================
    // READ
    // =========================================================================

    public static function getHeaderByCycle(int $cycleId): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM distributions WHERE cycle_id = ? LIMIT 1'
        );
        $stmt->execute([$cycleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM distributions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Ledger rows joined to member identity, ordered by Member_ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getItemsByDistribution(int $distributionId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT di.id, di.member_id, m.member_id AS member_code, m.full_name,
                    di.total_savings, di.total_interest, di.total_outstanding_loan,
                    di.final_payable, di.is_shortfall
               FROM distribution_items di
               JOIN members m ON m.id = di.member_id
              WHERE di.distribution_id = ? AND di.status = 1
              ORDER BY m.member_seq ASC'
        );
        $stmt->execute([$distributionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Every distribution ever run, newest cycle first (Req 10.6).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function history(): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT d.id, d.cycle_id, c.cycle_number, c.name AS cycle_name,
                    d.distribution_status, d.pdf_path, d.pdf_generated_at,
                    d.confirmed_at, d.total_disbursed, d.member_count
               FROM distributions d
               JOIN cycles c ON c.id = d.cycle_id
              WHERE d.status = 1
              ORDER BY c.cycle_number DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Create or refresh the header row for a cycle and return its ID.
     *
     * @param array $data {distribution_status?, pdf_path?, pdf_generated_at?,
     *                     total_disbursed?, member_count?, created_by?}
     */
    public static function upsertHeader(int $cycleId, array $data): int
    {
        $pdo      = Database::getInstance();
        $existing = self::getHeaderByCycle($cycleId);

        if ($existing === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO distributions
                    (cycle_id, distribution_status, pdf_path, pdf_generated_at,
                     total_disbursed, member_count, created_by)
                 VALUES
                    (:cycle_id, :dist_status, :pdf_path, :pdf_at,
                     :total, :members, :created_by)'
            );
            $stmt->execute([
                ':cycle_id'    => $cycleId,
                ':dist_status' => $data['distribution_status'] ?? 'Draft',
                ':pdf_path'    => $data['pdf_path'] ?? null,
                ':pdf_at'      => $data['pdf_generated_at'] ?? null,
                ':total'       => isset($data['total_disbursed'])
                    ? number_format((float) $data['total_disbursed'], 2, '.', '')
                    : null,
                ':members'     => $data['member_count'] ?? null,
                ':created_by'  => $data['created_by'] ?? null,
            ]);

            return (int) $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare(
            'UPDATE distributions
                SET distribution_status = :dist_status,
                    pdf_path            = :pdf_path,
                    pdf_generated_at    = :pdf_at,
                    total_disbursed     = :total,
                    member_count        = :members,
                    updated_by          = :updated_by
              WHERE id = :id'
        );
        $stmt->execute([
            ':dist_status' => $data['distribution_status'] ?? $existing['distribution_status'],
            ':pdf_path'    => $data['pdf_path'] ?? $existing['pdf_path'],
            ':pdf_at'      => $data['pdf_generated_at'] ?? $existing['pdf_generated_at'],
            ':total'       => isset($data['total_disbursed'])
                ? number_format((float) $data['total_disbursed'], 2, '.', '')
                : $existing['total_disbursed'],
            ':members'     => $data['member_count'] ?? $existing['member_count'],
            ':updated_by'  => $data['created_by'] ?? null,
            ':id'          => (int) $existing['id'],
        ]);

        return (int) $existing['id'];
    }

    /**
     * Replace all ledger rows for a distribution.
     *
     * Regeneration must not leave stale rows behind, so the previous set is
     * deleted first. Safe because items are only referenced after confirmation,
     * at which point regeneration is rejected by the service.
     *
     * @param array<int, array{member_id: int, total_savings: float,
     *         total_interest: float, total_outstanding_loan: float,
     *         final_payable: float, is_shortfall: bool}> $items
     */
    public static function replaceItems(
        int $distributionId,
        int $cycleId,
        array $items,
        ?int $adminId = null
    ): void {
        $pdo = Database::getInstance();

        $del = $pdo->prepare('DELETE FROM distribution_items WHERE distribution_id = ?');
        $del->execute([$distributionId]);

        if ($items === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO distribution_items
                (distribution_id, cycle_id, member_id, total_savings, total_interest,
                 total_outstanding_loan, final_payable, is_shortfall, created_by)
             VALUES
                (:dist_id, :cycle_id, :member_id, :savings, :interest,
                 :loan, :payable, :shortfall, :created_by)'
        );

        foreach ($items as $item) {
            $stmt->execute([
                ':dist_id'    => $distributionId,
                ':cycle_id'   => $cycleId,
                ':member_id'  => (int) $item['member_id'],
                ':savings'    => number_format((float) $item['total_savings'], 2, '.', ''),
                ':interest'   => number_format((float) $item['total_interest'], 2, '.', ''),
                ':loan'       => number_format((float) $item['total_outstanding_loan'], 2, '.', ''),
                ':payable'    => number_format((float) $item['final_payable'], 2, '.', ''),
                ':shortfall'  => !empty($item['is_shortfall']) ? 1 : 0,
                ':created_by' => $adminId,
            ]);
        }
    }

    /**
     * Mark the distribution Completed (Phase 2).
     */
    public static function markCompleted(
        int $id,
        float $totalDisbursed,
        int $memberCount,
        int $adminId
    ): void {
        $stmt = Database::getInstance()->prepare(
            "UPDATE distributions
                SET distribution_status = 'Completed',
                    total_disbursed     = :total,
                    member_count        = :members,
                    confirmed_at        = UTC_TIMESTAMP(),
                    confirmed_by        = :admin_id,
                    updated_by          = :admin_id2
              WHERE id = :id"
        );
        $stmt->execute([
            ':total'      => number_format($totalDisbursed, 2, '.', ''),
            ':members'    => $memberCount,
            ':admin_id'   => $adminId,
            ':admin_id2'  => $adminId,
            ':id'         => $id,
        ]);
    }
}
