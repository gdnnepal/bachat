<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/SettingsController.php — Cooperative settings endpoints
 *
 * Routes handled:
 *   GET /settings  (any authenticated admin)
 *   PUT /settings  (Super_Admin only — enforced by RbacMiddleware)
 *
 * Requirements covered: 12.1, 14.3
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Models\SettingModel;
use App\Services\AuditLogger;

class SettingsController
{
    /**
     * Writable settings and the rule applied to each. Keys not listed here
     * (such as the internal member_seq counter) can never be written over the API.
     */
    private const WRITABLE = [
        'cooperative_name'     => 'required|type:string|minLength:2|maxLength:150',
        'fixed_monthly_saving' => 'required|type:decimal|positive|decimal_places:2',
        'interest_rate_annual' => 'required|type:decimal|min:0|max:100',
        'default_language'     => 'required|type:enum:en,ne',
    ];

    // =========================================================================
    // GET /settings
    // =========================================================================

    public static function index(array $params, array $body): never
    {
        Response::success(
            [
                'settings' => SettingModel::map(),
                'writable' => array_keys(self::WRITABLE),
            ],
            'Settings retrieved.'
        );
    }

    // =========================================================================
    // PUT /settings   (Super_Admin)
    // =========================================================================

    public static function update(array $params, array $body): never
    {
        // Accept both a flat body and a nested { settings: {...} } payload.
        $incoming = is_array($body['settings'] ?? null) ? $body['settings'] : $body;

        // Keep only recognised, writable keys.
        $payload = array_intersect_key($incoming, self::WRITABLE);

        if ($payload === []) {
            Response::error(
                'VALIDATION_ERROR',
                'No writable settings were supplied.',
                ['settings' => 'Provide at least one of: ' . implode(', ', array_keys(self::WRITABLE)) . '.'],
                422
            );
        }

        // Validate only the keys actually being changed.
        $rules      = array_intersect_key(self::WRITABLE, $payload);
        $validation = Validator::validate($payload, $rules);

        if (!$validation['valid']) {
            Response::error('VALIDATION_ERROR', 'Validation failed.', $validation['errors'], 422);
        }

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $before  = SettingModel::map();
        $changes = [];

        foreach ($payload as $key => $value) {
            $newValue = is_string($value) ? trim($value) : (string) $value;
            $oldValue = (string) ($before[$key] ?? '');

            if ($oldValue === $newValue) {
                continue;
            }

            SettingModel::set((string) $key, $newValue, $adminId);
            $changes[] = "{$key}: '{$oldValue}' → '{$newValue}'";
        }

        if ($changes !== []) {
            AuditLogger::log(
                AuditLogger::SETTINGS_CHANGE,
                'Settings updated — ' . implode('; ', $changes),
                $adminId
            );
        }

        Response::success(
            ['settings' => SettingModel::map(), 'changed' => count($changes)],
            $changes === [] ? 'No changes were made.' : 'Settings updated successfully.'
        );
    }
}
