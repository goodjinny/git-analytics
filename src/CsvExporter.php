<?php
declare(strict_types=1);

/**
 * Writes a normalised report table (see ReportTableBuilder) to a CSV file.
 *
 * Format:
 *   - UTF-8 with BOM (so Excel auto-detects encoding on open)
 *   - RFC 4180 quoting (handled by fputcsv)
 *   - Separator: comma (",")  — change SEPARATOR if a different default needed
 *   - Includes title row, blank row, header row, data rows, blank, totals row
 *
 * Each call writes one CSV file. Multiple reports → multiple files
 * (handled by ExportRunner).
 */
final class CsvExporter
{
    public const SEPARATOR = ',';
    public const ENCLOSURE = '"';
    public const ESCAPE    = '\\';
    public const UTF8_BOM  = "\xEF\xBB\xBF";

    /**
     * Write a single CSV file from a table built by ReportTableBuilder.
     */
    public function write(array $table, string $targetPath): void
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create CSV directory: {$dir}");
        }

        $fh = fopen($targetPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open CSV file for writing: {$targetPath}");
        }

        // UTF-8 BOM (Excel-friendly)
        fwrite($fh, self::UTF8_BOM);

        try {
            // Title row (single cell)
            $this->putRow($fh, [(string) ($table['title'] ?? '')]);
            $this->putRow($fh, []); // blank

            // Headers
            $this->putRow($fh, $table['headers']);

            // Data
            foreach ($table['rows'] as $row) {
                $this->putRow($fh, $row);
            }

            // Totals (optional)
            if (!empty($table['totals'])) {
                $this->putRow($fh, []); // blank
                $this->putRow($fh, $table['totals']);
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param array<int, scalar> $row
     */
    private function putRow($fh, array $row): void
    {
        $normalised = array_map(
            static fn($cell): string => is_scalar($cell) || $cell === null ? (string) $cell : '',
            $row
        );
        fputcsv($fh, $normalised, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);
    }
}
