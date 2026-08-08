<?php

/**
 * VCMS — Village Cooperative Management System
 * app/services/BackupService.php — gzip SQL dump / restore
 *
 * The dump is written by PHP rather than mysqldump so the feature works on a
 * stock Laragon/XAMPP box with no shell access. Row values are quoted through
 * PDO::quote(), so Nepali text and embedded quotes survive a round-trip.
 *
 * Restore is deliberately two-step: an unconfirmed call only validates and
 * reports, so a destructive overwrite always needs a second explicit request
 * (Req 13.3, 13.5).
 *
 * Requirements covered: 13.1, 13.2, 13.3, 13.4, 13.5
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

class BackupService
{
    /** Tables are dumped in dependency order so a restore satisfies every FK. */
    private const TABLE_ORDER = [
        'settings',
        'admins',
        'cycles',
        'accounting_periods',
        'members',
        'saving_transactions',
        'interest_transactions',
        'loans',
        'repayments',
        'cash_bank_transactions',
        'distributions',
        'distribution_items',
        'audit_logs',
    ];

    /** gzip magic bytes — the cheap structural check of Req 13.4. */
    private const GZIP_MAGIC = "\x1f\x8b";

    /** Rows per multi-row INSERT statement. */
    private const INSERT_CHUNK = 200;

    // =========================================================================
    // Storage
    // =========================================================================

    /**
     * Absolute path of the backup directory, created on first use.
     */
    public static function storageDir(): string
    {
        $dir = defined('PUBLIC_PATH')
            ? PUBLIC_PATH . '/uploads/backups'
            : dirname(__DIR__, 2) . '/public/uploads/backups';

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Every stored backup, newest first.
     *
     * @return array<int, array{filename: string, size_bytes: int, size_human: string, created_at: string}>
     */
    public static function list(): array
    {
        $files = glob(self::storageDir() . '/backup_*.sql.gz') ?: [];

        $items = array_map(static function (string $path): array {
            $size = (int) (filesize($path) ?: 0);

            return [
                'filename'   => basename($path),
                'size_bytes' => $size,
                'size_human' => self::humanSize($size),
                'created_at' => gmdate('Y-m-d H:i:s', (int) (filemtime($path) ?: 0)),
            ];
        }, $files);

        usort($items, static fn (array $a, array $b): int => strcmp($b['filename'], $a['filename']));

        return $items;
    }

    // =========================================================================
    // CREATE (Req 13.1)
    // =========================================================================

    /**
     * @return array{success: bool, filename?: string, size_bytes?: int,
     *               size_human?: string, error?: string}
     */
    public static function create(int $adminId): array
    {
        $filename = 'backup_' . gmdate('Ymd_His') . '.sql.gz';
        $path     = self::storageDir() . '/' . $filename;

        $handle = @gzopen($path, 'wb9');
        if ($handle === false) {
            return ['success' => false, 'error' => 'Backup file could not be created. Check directory permissions.'];
        }

        try {
            $pdo = Database::getInstance();

            gzwrite($handle, "-- VCMS backup generated " . gmdate('Y-m-d H:i:s') . " UTC\n");
            gzwrite($handle, "SET NAMES utf8mb4;\n");
            gzwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

            foreach (self::TABLE_ORDER as $table) {
                if (!self::tableExists($pdo, $table)) {
                    continue;
                }

                gzwrite($handle, "-- ─── {$table} ───\n");
                gzwrite($handle, "TRUNCATE TABLE `{$table}`;\n");

                self::dumpTableRows($pdo, $handle, $table);

                gzwrite($handle, "\n");
            }

            gzwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } catch (Throwable $e) {
            gzclose($handle);
            @unlink($path);

            return ['success' => false, 'error' => 'Backup failed: ' . $e->getMessage()];
        }

        gzclose($handle);

        $size = (int) (filesize($path) ?: 0);

        AuditLogger::log(
            AuditLogger::BACKUP,
            "Backup created: {$filename} (" . self::humanSize($size) . ').',
            $adminId
        );

        return [
            'success'    => true,
            'filename'   => $filename,
            'size_bytes' => $size,
            'size_human' => self::humanSize($size),
        ];
    }

    // =========================================================================
    // RESTORE (Req 13.2, 13.3, 13.4, 13.5)
    // =========================================================================

    /**
     * Validate — and, only when $confirm is true, execute — a restore.
     *
     * @param string $filename Name of a file already inside the backups directory,
     *                         or the temp path of a fresh upload.
     * @param bool   $confirm  false → validate and report; true → overwrite the DB.
     *
     * @return array{success: bool, validated?: bool, restored?: bool,
     *               summary?: array, error?: string}
     */
    public static function restore(string $filename, bool $confirm, int $adminId): array
    {
        $path = self::resolveRestorePath($filename);

        if ($path === null) {
            return ['success' => false, 'error' => 'Backup file not found.'];
        }

        // Extension check (Req 13.4)
        if (!str_ends_with(strtolower($path), '.sql.gz')) {
            return ['success' => false, 'error' => 'Only .sql.gz backup files can be restored.'];
        }

        // Magic-byte check (Req 13.4)
        $head = @file_get_contents($path, false, null, 0, 2);
        if ($head !== self::GZIP_MAGIC) {
            return ['success' => false, 'error' => 'The file is not a valid gzip archive.'];
        }

        $sql = @gzdecode((string) file_get_contents($path));
        if ($sql === false || trim($sql) === '') {
            return ['success' => false, 'error' => 'The backup archive could not be decompressed.'];
        }

        $statements = self::splitStatements($sql);
        if ($statements === []) {
            return ['success' => false, 'error' => 'The backup contains no SQL statements.'];
        }

        $summary = [
            'filename'        => basename($path),
            'size_bytes'      => (int) (filesize($path) ?: 0),
            'statement_count' => count($statements),
            'tables'          => self::tablesMentioned($sql),
        ];

        // Req 13.3 — dry run stops here, before any DB change.
        if (!$confirm) {
            return ['success' => true, 'validated' => true, 'restored' => false, 'summary' => $summary];
        }

        $pdo = Database::getInstance();

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $e) {
            // Best effort — DDL/TRUNCATE cannot be rolled back in MySQL, so the
            // caller is told exactly where the restore stopped.
            @$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            AuditLogger::log(
                AuditLogger::RESTORE,
                "Restore FAILED from {$summary['filename']}: " . $e->getMessage(),
                $adminId
            );

            return ['success' => false, 'error' => 'Restore failed: ' . $e->getMessage()];
        }

        AuditLogger::log(
            AuditLogger::RESTORE,
            "Database restored from {$summary['filename']} ({$summary['statement_count']} statements).",
            $adminId
        );

        return ['success' => true, 'validated' => true, 'restored' => true, 'summary' => $summary];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Stream one table's rows into the archive as chunked multi-row INSERTs.
     *
     * @param resource $handle gzip handle
     */
    private static function dumpTableRows(PDO $pdo, $handle, string $table): void
    {
        $stmt = $pdo->query("SELECT * FROM `{$table}`");
        if ($stmt === false) {
            return;
        }

        $buffer  = [];
        $columns = null;

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($columns === null) {
                $columns = array_map(static fn (string $c): string => "`{$c}`", array_keys($row));
            }

            $values = array_map(
                static fn ($value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                array_values($row)
            );

            $buffer[] = '(' . implode(',', $values) . ')';

            if (count($buffer) >= self::INSERT_CHUNK) {
                self::writeInsert($handle, $table, $columns, $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== [] && $columns !== null) {
            self::writeInsert($handle, $table, $columns, $buffer);
        }
    }

    /**
     * @param resource            $handle
     * @param array<int, string>  $columns
     * @param array<int, string>  $rows
     */
    private static function writeInsert($handle, string $table, array $columns, array $rows): void
    {
        gzwrite(
            $handle,
            "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES\n"
            . implode(",\n", $rows) . ";\n"
        );
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables
                                WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Accept either a stored filename or an uploaded temp path, rejecting any
     * attempt to traverse out of the backups directory.
     */
    private static function resolveRestorePath(string $filename): ?string
    {
        // Uploaded file: an absolute path that PHP itself created.
        if (is_file($filename) && is_uploaded_file($filename)) {
            return $filename;
        }

        $safe = basename($filename);
        $path = self::storageDir() . '/' . $safe;

        return is_file($path) ? $path : null;
    }

    /**
     * Split a dump into executable statements.
     *
     * Splitting on ";\n" (rather than every semicolon) keeps semicolons that
     * appear inside quoted values intact, which matters for Nepali remarks.
     *
     * @return array<int, string>
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];

        foreach (explode(";\n", $sql) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            // Drop pure comment blocks
            $lines = array_filter(
                array_map('trim', explode("\n", $chunk)),
                static fn (string $line): bool => $line !== '' && !str_starts_with($line, '--')
            );

            if ($lines === []) {
                continue;
            }

            $statements[] = rtrim(implode("\n", $lines), ';');
        }

        return $statements;
    }

    /**
     * @return array<int, string>
     */
    private static function tablesMentioned(string $sql): array
    {
        preg_match_all('/INSERT INTO `([a-z_]+)`/i', $sql, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
