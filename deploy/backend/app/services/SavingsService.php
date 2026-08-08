<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/SavingsService.php — Bulk monthly savings collection
 *
 * Requirements covered: 5.1, 5.2, 5.3, 5.4, 5.5
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\BsCalendar;
use App\Models\AccountingPeriodModel;
use App\Models\CashBankTransactionModel;
use App\Models\MemberModel;
use App\Models\SavingTransactionModel;
use App\Models\SettingModel;

class SavingsService
{
    /**
     * Return the bulk-collection screen data: all active members with a flag
     * indicating whether they have already saved this period (Req 5.1).
     *
     * @return array{
     *   period: array,
     *   fixed_amount: float,
     *   members: array
     * }|array{error: string}
     */
    public static function bulkScreenData(): array
    {
        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return ['error' => 'No open accounting period found.'];
        }

        $fixedAmount    = SettingModel::getFloat('fixed_monthly_saving', 1000.0);
        $activeMembers  = MemberModel::listActive();
        $alreadyPaid    = SavingTransactionModel::memberIdsWithSavingInPeriod((int) $period['id']);
        $alreadyPaidSet = array_flip($alreadyPaid);

        $members = array_map(static function (array $m) use ($alreadyPaidSet, $fixedAmount): array {
            return [
                'id'         => (int) $m['id'],
                'member_id'  => $m['member_id'],
                'full_name'  => $m['full_name'],
                'phone'      => $m['phone'],
                'already_paid' => isset($alreadyPaidSet[(int) $m['id']]),
                'amount'     => $fixedAmount,
            ];
        }, $activeMembers);

        return [
            'period'       => $period,
            'fixed_amount' => $fixedAmount,
            'members'      => $members,
        ];
    }

    /**
     * Record savings for one or more members in the current OPEN period
     * within a single database transaction (Req 5.2).
     *
     * Duplicates are skipped and reported in the response (Req 5.4).
     * Empty member list is rejected (Req 5.3).
     *
     * @param array<int> $memberIds  List of member DB IDs to collect from.
     * @param int        $adminId    Admin performing the collection.
     * @return array{success: bool, saved: int, skipped: int, duplicates: array, error?: string}
     */
    public static function bulkCollect(array $memberIds, int $adminId): array
    {
        if (empty($memberIds)) {
            return [
                'success'    => false,
                'saved'      => 0,
                'skipped'    => 0,
                'duplicates' => [],
                'error'      => 'At least one member must be selected.',
            ];
        }

        $period = AccountingPeriodModel::getOpenPeriod();
        if ($period === null) {
            return [
                'success'    => false,
                'saved'      => 0,
                'skipped'    => 0,
                'duplicates' => [],
                'error'      => 'No open accounting period found.',
            ];
        }

        $periodId    = (int) $period['id'];
        $cycleId     = (int) $period['cycle_id'];
        $fixedAmount = SettingModel::getFloat('fixed_monthly_saving', 1000.0);

        // Today in both calendars
        $todayAd = date('Y-m-d');
        $todayBs = BsCalendar::adToBs($todayAd);

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        $saved      = 0;
        $skipped    = 0;
        $duplicates = [];

        try {
            foreach ($memberIds as $memberId) {
                $memberId = (int) $memberId;

                // Duplicate guard (Req 5.4)
                if (SavingTransactionModel::existsForMemberPeriod($memberId, $periodId)) {
                    $member       = MemberModel::findById($memberId);
                    $duplicates[] = [
                        'id'        => $memberId,
                        'full_name' => $member['full_name'] ?? 'Unknown',
                        'member_id' => $member['member_id'] ?? '',
                    ];
                    $skipped++;
                    continue;
                }

                // Insert saving transaction
                SavingTransactionModel::create([
                    'cycle_id'                  => $cycleId,
                    'accounting_period_id'      => $periodId,
                    'member_id'                 => $memberId,
                    'amount'                    => $fixedAmount,
                    'transaction_date_bs_year'  => $todayBs['year'],
                    'transaction_date_bs_month' => $todayBs['month'],
                    'transaction_date_ad'       => $todayAd,
                    'created_by'                => $adminId,
                ]);

                // Cash-in entry (Req 5.2 — savings increase Cash_In_Hand)
                CashBankTransactionModel::create([
                    'cycle_id'                  => $cycleId,
                    'accounting_period_id'      => $periodId,
                    'transaction_type'          => 'CashIn',
                    'amount'                    => $fixedAmount,
                    'reference_type'            => 'Saving',
                    'reference_id'              => null,
                    'description'               => "Monthly saving - member ID {$memberId}",
                    'transaction_date_bs_year'  => $todayBs['year'],
                    'transaction_date_bs_month' => $todayBs['month'],
                    'transaction_date_ad'       => $todayAd,
                    'created_by'                => $adminId,
                ]);

                $saved++;
            }

            $pdo->commit();

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'success'    => false,
                'saved'      => 0,
                'skipped'    => 0,
                'duplicates' => [],
                'error'      => 'Bulk collection failed: ' . $e->getMessage(),
            ];
        }

        // Single audit log entry (Req 5.5)
        AuditLogger::log(
            AuditLogger::MONTHLY_SAVING,
            "Bulk savings: {$saved} recorded, {$skipped} duplicates skipped. " .
            "Period: {$period['bs_year']}/{$period['bs_month']}.",
            $adminId
        );

        return [
            'success'    => true,
            'saved'      => $saved,
            'skipped'    => $skipped,
            'duplicates' => $duplicates,
        ];
    }
}
