<?php

/**
 * VCMS — Village Cooperative Management System
 * app/helpers/PdfGenerator.php — A4 PDF output via mPDF
 *
 * mPDF is used because it renders Devanagari out of the box, which the core
 * FPDF fonts cannot do — member names such as राम बहादुर must print correctly
 * on the distribution ledger members physically sign (Req 11.4, 10.2).
 *
 * If the dependency is missing the helper throws a RuntimeException carrying
 * the exact remedy; callers surface it as a normal error response rather than
 * emitting a broken file.
 *
 * Requirements covered: 10.2, 11.4
 */

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

class PdfGenerator
{
    /** Font stack that covers Latin + Devanagari in mPDF. */
    private const FONT = 'freeserif';

    // =========================================================================
    // Paths
    // =========================================================================

    /**
     * Directory holding generated PDFs (shared with backups, blocked from
     * direct web access by public/.htaccess).
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

    // =========================================================================
    // Generic report PDF (Req 11.4)
    // =========================================================================

    /**
     * Stream a report as a downloadable A4 PDF.
     *
     * @param array $report  {title, columns, rows, totals, meta} from ReportService.
     * @param string $filename Suggested download name without extension.
     */
    public static function streamReport(array $report, string $filename): never
    {
        $mpdf = self::mpdf('L');
        $mpdf->WriteHTML(self::reportHtml($report));
        $mpdf->Output($filename . '.pdf', 'D');

        exit;
    }

    /**
     * Render a report to a PDF string.
     */
    public static function reportPdf(array $report, string $orientation = 'L'): string
    {
        $mpdf = self::mpdf($orientation);
        $mpdf->WriteHTML(self::reportHtml($report));

        return (string) $mpdf->Output('', 'S');
    }

    // =========================================================================
    // Distribution ledger (Req 10.2, 10.3)
    // =========================================================================

    /**
     * Render and save the signature-ready distribution ledger.
     *
     * @param array<int, array<string, mixed>> $items  Ledger rows.
     * @param array $context {cycle, totals, cooperative_name}
     *
     * @return string Absolute path of the saved PDF.
     */
    public static function distributionLedger(array $items, array $context): string
    {
        $cycle    = $context['cycle'] ?? [];
        $totals   = $context['totals'] ?? [];
        $coopName = (string) ($context['cooperative_name'] ?? 'Cooperative');
        $cycleId  = (int) ($cycle['id'] ?? 0);

        $rowsHtml = '';

        foreach ($items as $index => $item) {
            $shortfall = !empty($item['is_shortfall']);
            $rowStyle  = $shortfall ? ' style="background-color:#fde8e8;"' : '';
            $flag      = $shortfall ? ' <strong>(SHORTFALL)</strong>' : '';

            $rowsHtml .= '<tr' . $rowStyle . '>'
                . '<td class="c">' . ($index + 1) . '</td>'
                . '<td>' . self::esc($item['full_name']) . $flag . '</td>'
                . '<td class="c">' . self::esc($item['member_code']) . '</td>'
                . '<td class="r">' . self::money($item['total_savings']) . '</td>'
                . '<td class="r">' . self::money($item['total_interest']) . '</td>'
                . '<td class="r">' . self::money($item['total_outstanding_loan']) . '</td>'
                . '<td class="r"><strong>' . self::money($item['final_payable']) . '</strong></td>'
                . '<td class="sig"></td>'
                . '</tr>';
        }

        $html = '<html><head><meta charset="utf-8">' . self::css() . '</head><body>'
            . '<h1>' . self::esc($coopName) . '</h1>'
            . '<h2>Distribution Ledger — Cycle ' . (int) ($cycle['cycle_number'] ?? 0) . '</h2>'
            . '<p class="meta">Generated ' . gmdate('Y-m-d H:i') . ' UTC — '
            . count($items) . ' members</p>'
            . '<table>'
            . '<thead><tr>'
            . '<th class="c">#</th><th>Member</th><th class="c">Member ID</th>'
            . '<th class="r">Savings</th><th class="r">Interest</th>'
            . '<th class="r">Outstanding Loan</th><th class="r">Final Payable</th>'
            . '<th class="sig">Signature</th>'
            . '</tr></thead>'
            . '<tbody>' . $rowsHtml . '</tbody>'
            . '<tfoot><tr>'
            . '<th colspan="3" class="r">Total</th>'
            . '<th class="r">' . self::money($totals['total_savings'] ?? 0) . '</th>'
            . '<th class="r">' . self::money($totals['total_interest'] ?? 0) . '</th>'
            . '<th class="r">' . self::money($totals['total_outstanding_loan'] ?? 0) . '</th>'
            . '<th class="r">' . self::money($totals['final_payable'] ?? 0) . '</th>'
            . '<th class="sig"></th>'
            . '</tr></tfoot>'
            . '</table>'
            . '<div class="signblock">'
            . '<div>Prepared by: ______________________</div>'
            . '<div>Secretary: ______________________</div>'
            . '<div>Chairperson: ______________________</div>'
            . '</div>'
            . '</body></html>';

        $mpdf = self::mpdf('L');
        $mpdf->WriteHTML($html);

        $filename = 'dist_cycle_' . $cycleId . '_' . gmdate('Ymd_His') . '.pdf';
        $path     = self::storageDir() . '/' . $filename;

        $mpdf->Output($path, 'F');

        return $path;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @param string $orientation 'P' portrait or 'L' landscape.
     * @return \Mpdf\Mpdf
     */
    private static function mpdf(string $orientation = 'P'): object
    {
        if (!class_exists('\Mpdf\Mpdf')) {
            throw new RuntimeException(
                'PDF export requires the mpdf/mpdf package. Run "composer install" in the backend directory.'
            );
        }

        $tmp = sys_get_temp_dir() . '/vcms_mpdf';
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }

        /** @var \Mpdf\Mpdf $mpdf */
        $mpdf = new \Mpdf\Mpdf([
            'mode'           => 'utf-8',
            'format'         => 'A4' . ($orientation === 'L' ? '-L' : ''),
            'default_font'   => self::FONT,
            'margin_left'    => 10,
            'margin_right'   => 10,
            'margin_top'     => 12,
            'margin_bottom'  => 12,
            'tempDir'        => $tmp,
        ]);

        $mpdf->SetTitle('VCMS Report');
        $mpdf->SetAuthor('VCMS');

        return $mpdf;
    }

    private static function reportHtml(array $report): string
    {
        $columns = $report['columns'] ?? [];
        $rows    = $report['rows'] ?? [];
        $totals  = $report['totals'] ?? [];

        $head = '';
        foreach ($columns as $column) {
            $class = self::alignClass((string) ($column['type'] ?? 'text'));
            $head .= '<th class="' . $class . '">' . self::esc($column['label']) . '</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>';
            foreach ($columns as $column) {
                $key   = (string) $column['key'];
                $type  = (string) ($column['type'] ?? 'text');
                $class = self::alignClass($type);
                $value = $row[$key] ?? '';

                $body .= '<td class="' . $class . '">'
                    . ($type === 'money' ? self::money($value) : self::esc($value))
                    . '</td>';
            }
            $body .= '</tr>';
        }

        $foot = '';
        if ($totals !== []) {
            $foot = '<tr>';
            $first = true;
            foreach ($columns as $column) {
                $key = (string) $column['key'];
                if (array_key_exists($key, $totals)) {
                    $foot .= '<th class="r">' . self::money($totals[$key]) . '</th>';
                } elseif ($first) {
                    $foot .= '<th>Total</th>';
                } else {
                    $foot .= '<th></th>';
                }
                $first = false;
            }
            $foot .= '</tr>';
        }

        return '<html><head><meta charset="utf-8">' . self::css() . '</head><body>'
            . '<h1>' . self::esc($report['title'] ?? 'Report') . '</h1>'
            . '<p class="meta">Generated ' . gmdate('Y-m-d H:i') . ' UTC — ' . count($rows) . ' rows</p>'
            . '<table><thead><tr>' . $head . '</tr></thead>'
            . '<tbody>' . $body . '</tbody>'
            . ($foot === '' ? '' : '<tfoot>' . $foot . '</tfoot>')
            . '</table></body></html>';
    }

    private static function css(): string
    {
        return '<style>
            body  { font-family: freeserif, sans-serif; font-size: 9pt; color: #111827; }
            h1    { font-size: 14pt; margin: 0 0 2mm 0; }
            h2    { font-size: 11pt; margin: 0 0 2mm 0; font-weight: normal; }
            .meta { font-size: 8pt; color: #6b7280; margin: 0 0 4mm 0; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 0.2mm solid #d1d5db; padding: 1.4mm 1.8mm; }
            thead th { background-color: #f3f4f6; font-size: 8.5pt; }
            tfoot th { background-color: #f9fafb; }
            .r { text-align: right; }
            .c { text-align: center; }
            .sig { width: 30mm; }
            .signblock { margin-top: 10mm; font-size: 9pt; }
            .signblock div { margin-bottom: 6mm; }
        </style>';
    }

    private static function alignClass(string $type): string
    {
        return match ($type) {
            'money', 'int' => 'r',
            'date'         => 'c',
            default        => '',
        };
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ',');
    }

    private static function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
