<?php

/**
 * VCMS — Village Cooperative Management System
 * app/controllers/BackupController.php — Database backup and restore endpoints
 *
 * Routes handled:
 *   GET  /backup/list
 *   POST /backup/create
 *   POST /backup/restore   (two-step: validate → confirm)
 *
 * Requirements covered: 13.1–13.5
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Response;
use App\Services\BackupService;

class BackupController
{
    /** Hard ceiling on uploaded restore archives (128 MB). */
    private const MAX_UPLOAD_BYTES = 134217728;

    // =========================================================================
    // GET /backup/list
    // =========================================================================

    public static function list(array $params, array $body): never
    {
        Response::success(BackupService::list(), 'Backups retrieved.');
    }

    // =========================================================================
    // POST /backup/create
    // =========================================================================

    public static function create(array $params, array $body): never
    {
        $result = BackupService::create((int) ($_SESSION['admin_id'] ?? 0));

        if (!$result['success']) {
            Response::error('INTERNAL_ERROR', $result['error'], [], 500);
        }

        Response::success(
            [
                'filename'   => $result['filename'],
                'size_bytes' => $result['size_bytes'],
                'size_human' => $result['size_human'],
            ],
            "Backup created: {$result['filename']} ({$result['size_human']}).",
            201
        );
    }

    // =========================================================================
    // POST /backup/restore
    // =========================================================================

    /**
     * Accepts either an existing backup filename (JSON body) or a freshly
     * uploaded .sql.gz file (multipart form). With confirm=false the service
     * returns a validation summary and touches nothing; with confirm=true it
     * executes the restore (Req 13.3).
     */
    public static function restore(array $params, array $body): never
    {
        $confirm = self::truthy($body['confirm'] ?? $params['confirm'] ?? false);
        $source  = self::resolveSource($body);

        $result = BackupService::restore($source, $confirm, (int) ($_SESSION['admin_id'] ?? 0));

        if (!$result['success']) {
            // Invalid archives are rejected before any DB change (Req 13.4).
            $isMissing = str_contains($result['error'], 'not found');
            Response::error(
                $isMissing ? 'NOT_FOUND' : 'VALIDATION_ERROR',
                $result['error'],
                $isMissing ? [] : ['file' => $result['error']],
                $isMissing ? 404 : 422
            );
        }

        if (empty($result['restored'])) {
            Response::success(
                ['validated' => true, 'restored' => false, 'summary' => $result['summary'] ?? null],
                'Backup file validated. Confirm to overwrite the current database.'
            );
        }

        Response::success(
            ['validated' => true, 'restored' => true, 'summary' => $result['summary'] ?? null],
            'Database restored successfully.'
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Determine which file the restore should read: an upload if one was sent,
     * otherwise a filename from the backups directory.
     */
    private static function resolveSource(array $body): string
    {
        $upload = $_FILES['file'] ?? null;

        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
                Response::error(
                    'VALIDATION_ERROR',
                    'The backup file failed to upload.',
                    ['file' => 'Upload failed (error code ' . $upload['error'] . ').'],
                    422
                );
            }

            if ((int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
                Response::error(
                    'VALIDATION_ERROR',
                    'The backup file is too large.',
                    ['file' => 'Maximum upload size is 128 MB.'],
                    422
                );
            }

            $tmp = (string) ($upload['tmp_name'] ?? '');

            if ($tmp === '' || !is_uploaded_file($tmp)) {
                Response::error('VALIDATION_ERROR', 'The uploaded file could not be read.', ['file' => 'Invalid upload.'], 422);
            }

            // Move it next to the other backups so the service can validate and
            // keep it; the original client name preserves the .sql.gz suffix.
            $target = BackupService::storageDir() . '/' . self::safeName((string) ($upload['name'] ?? 'upload.sql.gz'));

            if (!move_uploaded_file($tmp, $target)) {
                Response::error('INTERNAL_ERROR', 'The uploaded file could not be stored.', [], 500);
            }

            return basename($target);
        }

        $filename = trim((string) ($body['filename'] ?? ''));

        if ($filename === '') {
            Response::error(
                'VALIDATION_ERROR',
                'A backup filename or file upload is required.',
                ['filename' => 'Select a backup to restore.'],
                422
            );
        }

        return $filename;
    }

    /**
     * Strip path components and unsafe characters from an uploaded filename.
     */
    private static function safeName(string $name): string
    {
        $base  = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? 'upload.sql.gz';

        return $clean === '' ? 'upload.sql.gz' : $clean;
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'TRUE', 'yes', 'on'], true);
    }
}
