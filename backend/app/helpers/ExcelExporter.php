<?php

/**
 * VCMS — Village Cooperative Management System
 * app/helpers/ExcelExporter.php — XLSX export via PhpSpreadsheet
 *
 * Consumes the same report envelope produced by ReportService:
 *   { title, columns: [{key, label, type}], rows: [...], totals: {...}, meta: {...} }
 *
 * Requirements covered: 11.4
 */

declare(strict_types=1);

namespace App\Helpers;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelExporter
{
    /** Number format for all money columns — 2 decimal places, thousands separator. */
    private const MONEY_FORMAT = '#,##0.00';

    /** Header fill colour (slate-700) and font colour. */
    private const HEADER_BG   = 'FF334155';
    private const HEADER_FONT = 'FFFFFFFF';

    /** Totals row fill (slate-100). */
    private const TOTALS_BG = 'FFF1F5F9';

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Stream a report as a downloadable .xlsx file and exit.
     *
     * @param array  $report   Report envelope from ReportService.
     * @param string $filename Suggested download name, without extension.
     */
    public static function streamReport(array $report, string $filename): never
    {
        $binary = self::reportXlsx($report);
        $safe   = self::safeFilename($filename) . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Length: ' . strlen($binary));
        header('Cache-Control: max-age=0, no-store');
        header('Pragma: public');

        echo $binary;
        exit;
    }

    /**
     * Render a report envelope to an XLSX binary string.
     */
    public static function reportXlsx(array $report): string
    {
        $title   = (string) ($report['title'] ?? 'Report');
        $columns = is_array($report['columns'] ?? null) ? $report['columns'] : [];
        $rows    = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        $totals  = is_array($report['totals'] ?? null) ? $report['totals'] : [];

        return self::export($rows, $columns, $title, $totals, $report['meta'] ?? []);
    }

    /**
     * Build an XLSX binary from raw rows + column definitions.
     *
     * @param array<int, array<string, mixed>>            $rows
     * @param array<int, array{key: string, label: string, type?: string}> $columns
     * @param string                                      $title
     * @param array<string, float>                        $totals  Keyed by column key.
     * @param mixed                                       $meta    Optional meta block (filters, balances).
     */
    public static function export(
        array $rows,
        array $columns,
        string $title = 'Report',
        array $totals = [],
        mixed $meta = []
    ): string {
        // Fall back to deriving columns from the first row when none were given.
        if ($columns === [] && $rows !== []) {
            $columns = array_map(
                static fn (string $key): array => ['key' => $key, 'label' => self::humanise($key), 'type' => 'text'],
                array_keys($rows[0])
            );
        }

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::safeSheetName($title));

        $lastColIndex = max(1, count($columns));
        $lastColLetter = self::columnLetter($lastColIndex);

        // ── Title banner ─────────────────────────────────────────────────────
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ── Sub-header with the filter summary ───────────────────────────────
        $sheet->setCellValue('A2', self::metaLine($meta));
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ── Column headers ───────────────────────────────────────────────────
        $headerRow = 4;

        foreach ($columns as $index => $column) {
            $letter = self::columnLetter($index + 1);
            $sheet->setCellValue($letter . $headerRow, (string) ($column['label'] ?? $column['key'] ?? ''));
        }

        $headerRange = "A{$headerRow}:{$lastColLetter}{$headerRow}";
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB(self::HEADER_FONT);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB(self::HEADER_BG);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ── Data rows ────────────────────────────────────────────────────────
        $rowNumber = $headerRow + 1;

        foreach ($rows as $row) {
            foreach ($columns as $index => $column) {
                $letter = self::columnLetter($index + 1);
                $key    = (string) ($column['key'] ?? '');
                $type   = (string) ($column['type'] ?? 'text');
                $value  = $row[$key] ?? null;

                $cell = $letter . $rowNumber;

                if ($type === 'money' || $type === 'decimal') {
                    $sheet->setCellValue($cell, round((float) $value, 2));
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
                } elseif ($type === 'int') {
                    $sheet->setCellValue($cell, (int) $value);
                } else {
                    // Explicit string set so Nepali Unicode and leading zeros survive.
                    $sheet->setCellValueExplicit(
                        $cell,
                        $value === null ? '' : (string) $value,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                    );
                }
            }

            $rowNumber++;
        }

        $lastDataRow = $rowNumber - 1;

        // ── Totals row ───────────────────────────────────────────────────────
        if ($totals !== [] && $rows !== []) {
            $sheet->setCellValue('A' . $rowNumber, 'TOTAL');

            foreach ($columns as $index => $column) {
                $key = (string) ($column['key'] ?? '');
                if (!array_key_exists($key, $totals)) {
                    continue;
                }

                $letter = self::columnLetter($index + 1);
                $cell   = $letter . $rowNumber;
                $sheet->setCellValue($cell, round((float) $totals[$key], 2));
                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            }

            $totalsRange = "A{$rowNumber}:{$lastColLetter}{$rowNumber}";
            $sheet->getStyle($totalsRange)->getFont()->setBold(true);
            $sheet->getStyle($totalsRange)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB(self::TOTALS_BG);

            $lastDataRow = $rowNumber;
        }

        // ── Borders, autofilter, widths, freeze pane ─────────────────────────
        if ($lastDataRow >= $headerRow) {
            $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$lastDataRow}")
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        if ($rows !== []) {
            $sheet->setAutoFilter("A{$headerRow}:{$lastColLetter}" . ($headerRow + count($rows)));
        }

        for ($i = 1; $i <= $lastColIndex; $i++) {
            $sheet->getColumnDimension(self::columnLetter($i))->setAutoSize(true);
        }

        $sheet->freezePane('A' . ($headerRow + 1));

        // ── Write to memory ──────────────────────────────────────────────────
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $binary = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $binary;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Convert a 1-based column index to its spreadsheet letter (1 → A, 27 → AA).
     */
    private static function columnLetter(int $index): string
    {
        $letter = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter    = chr(65 + $remainder) . $letter;
            $index     = (int) (($index - $remainder - 1) / 26);
        }

        return $letter === '' ? 'A' : $letter;
    }

    /**
     * Build the italic sub-header line summarising the active filters.
     */
    private static function metaLine(mixed $meta): string
    {
        $parts = ['Generated ' . gmdate('Y-m-d H:i') . ' UTC'];

        if (is_array($meta) && is_array($meta['filters'] ?? null)) {
            foreach ($meta['filters'] as $key => $value) {
                if ($value === null || $value === '' || is_array($value)) {
                    continue;
                }
                $parts[] = self::humanise((string) $key) . ': ' . $value;
            }
        }

        return implode('  |  ', $parts);
    }

    /**
     * "total_savings" → "Total Savings"
     */
    private static function humanise(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    /**
     * Excel sheet names are max 31 chars and cannot contain : \ / ? * [ ]
     */
    private static function safeSheetName(string $title): string
    {
        $clean = preg_replace('/[:\\\\\/\?\*\[\]]/', '-', $title) ?? 'Report';

        return mb_substr(trim($clean) === '' ? 'Report' : $clean, 0, 31);
    }

    /**
     * Strip characters that break Content-Disposition headers.
     */
    private static function safeFilename(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename) ?? 'report';

        return trim($clean, '_') === '' ? 'report' : trim($clean, '_');
    }
}
