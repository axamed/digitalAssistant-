<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Service handling CSV exports for tables and reports.
 * Compatible with Microsoft Excel (UTF-8 BOM, standard delimiter).
 */
final class ExportService
{
    /**
     * Stream an array of associative rows as a downloadable CSV.
     *
     * @param string $filename  The downloaded filename, e.g. "birth_records_2026.csv"
     * @param array  $headers   Associative array mapping [column_key => Display Header]
     * @param array  $rows      Array of associative row arrays
     */
    public static function downloadCsv(string $filename, array $headers, array $rows): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        if ($out === false) {
            return;
        }

        // Output UTF-8 BOM for Microsoft Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");

        // Write header row
        fputcsv($out, array_values($headers));

        // Write data rows
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($headers) as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }
}
