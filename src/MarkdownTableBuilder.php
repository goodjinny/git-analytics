<?php
declare(strict_types=1);

/**
 * Builds markdown sections for reports.
 *
 * Two renderers:
 *   - simple : | # | Розробник | <metric> |
 *   - pivot  : | # | Розробник | <period1> | <period2> | … | Всього |
 */
final class MarkdownTableBuilder
{
    /**
     * Build full markdown for one report (with header + table).
     *
     * $zeroDevs — extra developers to render with zero values (after data rows).
     * Used by reverts-* reports to show team members who have commits in the
     * period but no reverts.
     *
     * @param array<int, array{dev_id:int, dev_name:string}> $zeroDevs
     */
    public function buildReport(
        array  $def,
        array  $rows,
        string $branch,
        string $dateFrom,
        string $dateTo,
        int    $headingLevel = 1,
        array  $zeroDevs = []
    ): string {
        $section = $this->buildSection($def, $rows, $headingLevel, $zeroDevs);
        $meta    = $this->meta($branch, $dateFrom, $dateTo);

        return $this->heading($def['title'], $headingLevel) . PHP_EOL . PHP_EOL
             . $meta . PHP_EOL . PHP_EOL
             . $section;
    }

    /**
     * Build just the table + optional title (for combining inside full-report).
     *
     * @param array<int, array{dev_id:int, dev_name:string}> $zeroDevs
     */
    public function buildSection(array $def, array $rows, int $headingLevel = 2, array $zeroDevs = []): string
    {
        if (empty($rows) && empty($zeroDevs)) {
            return '> _Немає даних для цього звіту за вказаний період._' . PHP_EOL;
        }

        return match ($def['renderer']) {
            'simple' => $this->renderSimple($rows, $def['metricLabel'], $zeroDevs),
            'pivot'  => $this->renderPivot($rows, $def['pivotLabel'] ?? 'Всього', $zeroDevs),
            default  => throw new InvalidArgumentException("Unknown renderer: {$def['renderer']}"),
        };
    }

    public function heading(string $text, int $level = 1): string
    {
        $level = max(1, min(6, $level));
        return str_repeat('#', $level) . ' ' . $text;
    }

    public function meta(string $branch, string $from, string $to): string
    {
        return sprintf(
            "**Період:** %s — %s  \n**Гілка:** `%s`  \n**Згенеровано:** %s",
            $from,
            $to,
            $branch,
            date('Y-m-d H:i:s')
        );
    }

    /**
     * Render the --detail section for reverts-* reports.
     * Rows are grouped by developer. Within each developer revert commits are
     * listed in reverse chronological order.
     *
     * Expected row keys (from ReportRepository::revertDetails):
     *   dev_id, dev_name, revert_date, revert_datetime, commit_hash,
     *   commit_hash_short, subject, ticket_code, matched_commit_hash,
     *   matched_commit_subject, detected_by
     */
    public function buildRevertDetails(array $rows, ?string $aliasFilter = null): string
    {
        if (empty($rows)) {
            $f = $aliasFilter !== null ? " для `--alias={$aliasFilter}`" : '';
            return PHP_EOL . '## Деталі відкатів' . $f . PHP_EOL . PHP_EOL
                 . '> _Немає відкатів для відображення._' . PHP_EOL;
        }

        // Group by developer
        $byDev = [];
        foreach ($rows as $r) {
            $key = (int) ($r['dev_id'] ?? 0) . '|' . ($r['dev_name'] ?? '');
            $byDev[$key]['name']   = (string) ($r['dev_name'] ?? '(невідомо)');
            $byDev[$key]['rows'][] = $r;
        }

        $lines   = [];
        $lines[] = '';
        $lines[] = '## Деталі відкатів' . ($aliasFilter !== null ? " — `--alias={$aliasFilter}`" : '');
        $lines[] = '';
        $lines[] = '_Усього відкатів у вибірці: **' . count($rows) . '**, розробників: **' . count($byDev) . '**_';
        $lines[] = '';

        foreach ($byDev as $dev) {
            $lines[] = '### ' . $this->escape($dev['name']) . ' — ' . count($dev['rows']) . ' відкат(ів)';
            $lines[] = '';
            $lines[] = '| #   | Дата       | Хеш       | Тікет | Тема відкату | Відкочений коміт |';
            $lines[] = '| --- | ---------- | --------- | ----- | ------------ | ---------------- |';

            $i = 0;
            foreach ($dev['rows'] as $r) {
                $i++;
                $matched = '';
                if (!empty($r['matched_commit_hash'])) {
                    $matched = sprintf(
                        '`%s` %s',
                        substr((string) $r['matched_commit_hash'], 0, 8),
                        $this->escape((string) $r['matched_commit_subject'])
                    );
                }
                $lines[] = sprintf(
                    '| %d | %s | `%s` | %s | %s | %s |',
                    $i,
                    $this->escape((string) ($r['revert_date'] ?? '')),
                    $this->escape((string) ($r['commit_hash_short'] ?? '')),
                    $this->escape((string) ($r['ticket_code'] ?? '')),
                    $this->escape((string) ($r['subject'] ?? '')),
                    $matched
                );
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    // ------------------------------------------------------------------
    // Renderers
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{dev_id:int, dev_name:string}> $zeroDevs
     */
    private function renderSimple(array $rows, string $metricLabel, array $zeroDevs = []): string
    {
        $lines   = [];
        $lines[] = "| #   | Розробник | " . $metricLabel . " |";
        $lines[] = "| --- | --------- | ---------------: |";

        $total       = 0;
        $i           = 0;
        $presentIds  = [];

        foreach ($rows as $row) {
            $i++;
            $value         = (int) $row['value'];
            $total        += $value;
            $presentIds[]  = (int) ($row['dev_id'] ?? 0);
            $lines[] = sprintf(
                "| %d | %s | %s |",
                $i,
                $this->escape((string) $row['dev_name']),
                $this->formatNumber($value)
            );
        }

        // Append zero-rows for devs not in $rows (alphabetical by name).
        $remaining = array_values(array_filter(
            $zeroDevs,
            static fn(array $d): bool => !in_array((int) $d['dev_id'], $presentIds, true)
        ));
        usort($remaining, static fn(array $a, array $b): int => strcmp($a['dev_name'], $b['dev_name']));

        $zeroCount = 0;
        foreach ($remaining as $d) {
            $i++;
            $zeroCount++;
            $lines[] = sprintf(
                "| %d | %s | %s |",
                $i,
                $this->escape((string) $d['dev_name']),
                $this->formatNumber(0)
            );
        }

        $lines[] = '';
        $summary = sprintf(
            '**Всього розробників:** %d  •  **Всього (сума):** %s',
            $i,
            $this->formatNumber($total)
        );
        if ($zeroCount > 0) {
            $summary .= sprintf('  •  **З нульовими показниками:** %d', $zeroCount);
        }
        $lines[] = $summary;

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * @param array<int, array{dev_id:int, dev_name:string}> $zeroDevs
     */
    private function renderPivot(array $rows, string $totalLabel, array $zeroDevs = []): string
    {
        // Aggregate rows into matrix: dev_id => [dev_name, periods => [period => value, ...]]
        $matrix  = [];
        $periods = [];

        foreach ($rows as $row) {
            $devId   = (int) ($row['dev_id'] ?? 0);
            $period  = (string) $row['period'];
            $value   = (int) $row['value'];

            $periods[$period] = true;

            if (!isset($matrix[$devId])) {
                $matrix[$devId] = [
                    'name'    => (string) $row['dev_name'],
                    'periods' => [],
                    'total'   => 0,
                ];
            }
            $matrix[$devId]['periods'][$period] = ($matrix[$devId]['periods'][$period] ?? 0) + $value;
            $matrix[$devId]['total']           += $value;
        }

        // Seed zero entries for devs not present (total=0 → they land at bottom
        // after uasort by total DESC, with strcmp(name) as a stable tiebreaker).
        foreach ($zeroDevs as $d) {
            $devId = (int) $d['dev_id'];
            if (!isset($matrix[$devId])) {
                $matrix[$devId] = [
                    'name'    => (string) $d['dev_name'],
                    'periods' => [],
                    'total'   => 0,
                ];
            }
        }

        // Sort periods ascending (works for both YYYY and YYYY-MM since string compare)
        $periodList = array_keys($periods);
        sort($periodList, SORT_STRING);

        // Sort devs by total DESC, then name
        uasort($matrix, static function (array $a, array $b): int {
            return ($b['total'] <=> $a['total']) ?: strcmp($a['name'], $b['name']);
        });

        // Header
        $header  = ['#', 'Розробник'];
        $separat = ['---', '---'];
        foreach ($periodList as $p) {
            $header[]  = $p;
            $separat[] = '---:';
        }
        $header[]  = $totalLabel;
        $separat[] = '---:';

        $lines   = [];
        $lines[] = '| ' . implode(' | ', $header)  . ' |';
        $lines[] = '| ' . implode(' | ', $separat) . ' |';

        $i           = 0;
        $columnTotal = array_fill_keys($periodList, 0);
        $grandTotal  = 0;

        foreach ($matrix as $dev) {
            $i++;
            $cells = [(string) $i, $this->escape($dev['name'])];
            foreach ($periodList as $p) {
                $v             = (int) ($dev['periods'][$p] ?? 0);
                $cells[]       = $v === 0 ? '' : $this->formatNumber($v);
                $columnTotal[$p] += $v;
            }
            $cells[]    = $this->formatNumber((int) $dev['total']);
            $grandTotal += (int) $dev['total'];
            $lines[]    = '| ' . implode(' | ', $cells) . ' |';
        }

        // Footer with column totals
        $footer = ['', '**' . $totalLabel . '**'];
        foreach ($periodList as $p) {
            $footer[] = '**' . $this->formatNumber($columnTotal[$p]) . '**';
        }
        $footer[] = '**' . $this->formatNumber($grandTotal) . '**';
        $lines[]  = '| ' . implode(' | ', $footer) . ' |';

        $lines[] = '';
        $lines[] = sprintf('**Всього розробників:** %d  •  **Загальна сума:** %s',
            $i, $this->formatNumber($grandTotal));

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function escape(string $s): string
    {
        // Minimal markdown escaping for table cells (pipe and backslash).
        return str_replace(['\\', '|'], ['\\\\', '\\|'], $s);
    }

    private function formatNumber(int $n): string
    {
        return number_format($n, 0, '.', ' ');
    }
}
