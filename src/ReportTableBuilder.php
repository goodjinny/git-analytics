<?php
declare(strict_types=1);

/**
 * Build a normalised 2D table (headers + data rows + per-column metadata)
 * from a report definition + raw repository rows + optional zero-rows.
 *
 * Used by CSV/XLSX exporters so they share a single source of truth with
 * the markdown renderer. The output is intentionally simple — pure arrays
 * with no formatting — so each exporter can format numbers as it sees fit.
 *
 * Output structure:
 *   [
 *     'title'   => string,
 *     'headers' => string[],          // human-readable column names
 *     'rows'    => array<int, array<int, scalar>>, // 2D table of cells
 *     'numeric' => bool[],            // per-column: is the cell numeric?
 *     'totals'  => array<int, scalar>|null, // optional footer row (pivot only)
 *   ]
 */
final class ReportTableBuilder
{
    /**
     * @param array<int, array{dev_id:int, dev_name:string}> $zeroDevs
     */
    public function build(array $def, array $rows, array $zeroDevs = []): array
    {
        return match ($def['renderer']) {
            'simple' => $this->buildSimple($def, $rows, $zeroDevs),
            'pivot'  => $this->buildPivot($def, $rows, $zeroDevs),
            default  => throw new InvalidArgumentException("Unknown renderer: {$def['renderer']}"),
        };
    }

    /**
     * Build a flat table of revert details (one row per revert commit).
     * Used when --detail is requested.
     *
     * Expected row keys (from ReportRepository::revertDetails):
     *   dev_name, revert_date, commit_hash_short, ticket_code, subject,
     *   matched_commit_hash, matched_commit_subject
     */
    public function buildRevertDetails(array $rows, ?string $aliasFilter = null): array
    {
        $title = 'Деталі відкатів'
            . ($aliasFilter !== null ? " — --alias={$aliasFilter}" : '');

        $headers = ['#', 'Розробник', 'Дата', 'Хеш', 'Тікет', 'Тема відкату', 'Відкочений коміт'];
        $numeric = [true, false, false, false, false, false, false];
        $data    = [];

        $i = 0;
        foreach ($rows as $r) {
            $i++;
            $matched = '';
            if (!empty($r['matched_commit_hash'])) {
                $matched = substr((string) $r['matched_commit_hash'], 0, 8) . ' '
                    . (string) ($r['matched_commit_subject'] ?? '');
            }
            $data[] = [
                $i,
                (string) ($r['dev_name'] ?? '(невідомо)'),
                (string) ($r['revert_date'] ?? ''),
                (string) ($r['commit_hash_short'] ?? ''),
                (string) ($r['ticket_code'] ?? ''),
                (string) ($r['subject'] ?? ''),
                $matched,
            ];
        }

        return [
            'title'   => $title,
            'headers' => $headers,
            'rows'    => $data,
            'numeric' => $numeric,
            'totals'  => null,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * @param array<int, array{dev_id:int, dev_name:string}> $zeroDevs
     */
    private function buildSimple(array $def, array $rows, array $zeroDevs): array
    {
        $metricLabel = (string) ($def['metricLabel'] ?? 'Значення');
        $headers     = ['#', 'Розробник', $metricLabel];
        $numeric     = [true, false, true];

        $data       = [];
        $presentIds = [];
        $total      = 0;
        $i          = 0;

        foreach ($rows as $row) {
            $i++;
            $value        = (int) $row['value'];
            $total       += $value;
            $presentIds[] = (int) ($row['dev_id'] ?? 0);
            $data[] = [
                $i,
                (string) $row['dev_name'],
                $value,
            ];
        }

        // Append zero rows (devs with commits but no data in this metric).
        $remaining = array_values(array_filter(
            $zeroDevs,
            static fn(array $d): bool => !in_array((int) $d['dev_id'], $presentIds, true)
        ));
        usort($remaining, static fn(array $a, array $b): int => strcmp($a['dev_name'], $b['dev_name']));

        foreach ($remaining as $d) {
            $i++;
            $data[] = [$i, (string) $d['dev_name'], 0];
        }

        return [
            'title'   => (string) $def['title'],
            'headers' => $headers,
            'rows'    => $data,
            'numeric' => $numeric,
            'totals'  => ['', 'Всього', $total],
        ];
    }

    /**
     * @param array<int, array{dev_id:int, dev_name:string}> $zeroDevs
     */
    private function buildPivot(array $def, array $rows, array $zeroDevs): array
    {
        $totalLabel = (string) ($def['pivotLabel'] ?? 'Всього');

        // Build matrix: dev_id => [name, periods => [period => v], total]
        $matrix  = [];
        $periods = [];

        foreach ($rows as $row) {
            $devId  = (int) ($row['dev_id'] ?? 0);
            $period = (string) $row['period'];
            $value  = (int) $row['value'];

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

        $periodList = array_keys($periods);
        sort($periodList, SORT_STRING);

        uasort($matrix, static function (array $a, array $b): int {
            return ($b['total'] <=> $a['total']) ?: strcmp($a['name'], $b['name']);
        });

        // Header
        $headers = ['#', 'Розробник'];
        $numeric = [true, false];
        foreach ($periodList as $p) {
            $headers[] = $p;
            $numeric[] = true;
        }
        $headers[] = $totalLabel;
        $numeric[] = true;

        $data         = [];
        $columnTotals = array_fill_keys($periodList, 0);
        $grandTotal   = 0;
        $i            = 0;

        foreach ($matrix as $dev) {
            $i++;
            $cells = [$i, $dev['name']];
            foreach ($periodList as $p) {
                $v = (int) ($dev['periods'][$p] ?? 0);
                $cells[] = $v;
                $columnTotals[$p] += $v;
            }
            $cells[] = (int) $dev['total'];
            $grandTotal += (int) $dev['total'];
            $data[] = $cells;
        }

        // Footer row
        $totals = ['', $totalLabel];
        foreach ($periodList as $p) {
            $totals[] = $columnTotals[$p];
        }
        $totals[] = $grandTotal;

        return [
            'title'   => (string) $def['title'],
            'headers' => $headers,
            'rows'    => $data,
            'numeric' => $numeric,
            'totals'  => $totals,
        ];
    }
}
