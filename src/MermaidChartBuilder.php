<?php
declare(strict_types=1);

/**
 * Builds Mermaid (xychart-beta / pie) code blocks for inclusion in markdown
 * reports. Renders natively on GitHub/GitLab without external dependencies.
 *
 * Strategy:
 *   - All reports → horizontal-style bar chart of TOP_N developers by total.
 *   - `reverts-by-month` (pivot, long period) → also a trend line per period.
 *
 * Mermaid xychart-beta limitations:
 *   - one series per chart works most reliably across renderers,
 *   - Cyrillic and other Unicode labels are supported in Mermaid >= 10,
 *   - double quotes inside labels must be normalised.
 */
final class MermaidChartBuilder
{
    /** How many developers to include in charts (top by total). */
    private const TOP_N = 10;

    /** Max display length for dev label on the x-axis. */
    private const LABEL_MAX = 22;

    public function build(array $def, array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        // Aggregate per developer (sums across all periods for pivot rows).
        $totals = $this->aggregateTotals($rows);
        if (empty($totals)) {
            return '';
        }

        $top    = array_slice($totals, 0, self::TOP_N, true);
        $title  = (string) ($def['title'] ?? 'Звіт');
        $yLabel = (string) ($def['metricLabel'] ?? 'Значення');

        $blocks = [];
        $blocks[] = $this->barChart("Топ-" . count($top) . ": " . $title, $yLabel, $top);

        // Add a period-trend line for pivot reports (year / month).
        if (($def['renderer'] ?? '') === 'pivot') {
            $byPeriod = $this->aggregateByPeriod($rows);
            if (!empty($byPeriod)) {
                $blocks[] = $this->lineChart(
                    'Динаміка за періодами: ' . $title,
                    $yLabel,
                    $byPeriod
                );
            }
        }

        return PHP_EOL . '## Діаграма' . PHP_EOL . PHP_EOL . implode(PHP_EOL . PHP_EOL, $blocks) . PHP_EOL;
    }

    // ------------------------------------------------------------------
    // Aggregation helpers
    // ------------------------------------------------------------------

    /**
     * @return array<int, array{name:string, total:int}> indexed by dev_id, sorted DESC by total
     */
    private function aggregateTotals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $r) {
            $id    = (int) ($r['dev_id'] ?? 0);
            $value = (int) ($r['value'] ?? 0);
            if (!isset($totals[$id])) {
                $totals[$id] = ['name' => (string) ($r['dev_name'] ?? ''), 'total' => 0];
            }
            $totals[$id]['total'] += $value;
        }

        // Drop empty rows (e.g. zero-dev placeholders accidentally included).
        $totals = array_filter($totals, static fn(array $t): bool => $t['total'] > 0);

        uasort($totals, static fn(array $a, array $b): int =>
            ($b['total'] <=> $a['total']) ?: strcmp($a['name'], $b['name'])
        );

        return $totals;
    }

    /**
     * @return array<string, int>  period => sum across all devs
     */
    private function aggregateByPeriod(array $rows): array
    {
        $byPeriod = [];
        foreach ($rows as $r) {
            $period = (string) ($r['period'] ?? '');
            if ($period === '') {
                continue;
            }
            $byPeriod[$period] = ($byPeriod[$period] ?? 0) + (int) ($r['value'] ?? 0);
        }
        ksort($byPeriod, SORT_STRING);
        return $byPeriod;
    }

    // ------------------------------------------------------------------
    // Mermaid renderers
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{name:string, total:int}> $items
     */
    private function barChart(string $title, string $yLabel, array $items): string
    {
        $labels = [];
        $values = [];
        foreach ($items as $it) {
            $labels[] = '"' . $this->escape($this->shortName($it['name'])) . '"';
            $values[] = (int) $it['total'];
        }
        $yMax = (int) max(1, ceil(max($values) * 1.1));

        return "```mermaid\n"
             . "xychart-beta\n"
             . '    title "' . $this->escape($title) . "\"\n"
             . '    x-axis [' . implode(', ', $labels) . "]\n"
             . '    y-axis "' . $this->escape($yLabel) . '" 0 --> ' . $yMax . "\n"
             . '    bar [' . implode(', ', $values) . "]\n"
             . '```';
    }

    /**
     * @param array<string, int> $byPeriod
     */
    private function lineChart(string $title, string $yLabel, array $byPeriod): string
    {
        $labels = [];
        $values = [];
        foreach ($byPeriod as $period => $value) {
            $labels[] = '"' . $this->escape((string) $period) . '"';
            $values[] = (int) $value;
        }
        $yMax = (int) max(1, ceil(max($values) * 1.1));

        return "```mermaid\n"
             . "xychart-beta\n"
             . '    title "' . $this->escape($title) . "\"\n"
             . '    x-axis [' . implode(', ', $labels) . "]\n"
             . '    y-axis "' . $this->escape($yLabel) . '" 0 --> ' . $yMax . "\n"
             . '    line [' . implode(', ', $values) . "]\n"
             . '```';
    }

    /**
     * Strip <email> suffix and truncate long names for axis labels.
     */
    private function shortName(string $name): string
    {
        $name = (string) preg_replace('/\s*<[^>]+>\s*$/u', '', $name);
        if (mb_strlen($name, 'UTF-8') > self::LABEL_MAX) {
            $name = mb_substr($name, 0, self::LABEL_MAX - 1, 'UTF-8') . '…';
        }
        return $name;
    }

    /**
     * Sanitize strings used inside Mermaid quoted contexts.
     */
    private function escape(string $s): string
    {
        return str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $s);
    }
}
