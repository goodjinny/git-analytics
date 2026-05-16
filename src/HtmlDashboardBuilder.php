<?php
declare(strict_types=1);

/**
 * Builds a single-file interactive HTML dashboard powered by Chart.js.
 *
 * One `index.html` is generated per period under reports/<period>/diagrams/.
 * The dashboard contains one section per generated report:
 *   - simple reports  → horizontal bar chart (top-N developers)
 *   - pivot reports   → multi-line chart (top-N developers across periods)
 *
 * Chart.js loaded from CDN (`cdn.jsdelivr.net`); no other JS deps.
 *
 * Public API:
 *   $html = new HtmlDashboardBuilder()->build($sections, $branch, $from, $to);
 *   file_put_contents($dir . '/index.html', $html);
 */
final class HtmlDashboardBuilder
{
    /** Top-N developers to render on every chart. */
    private const TOP_N = 10;

    /**
     * @param array<string, array{def:array, rows:array, is_reverts:bool, zero_devs?:array}> $sections
     */
    public function build(array $sections, string $branch, string $from, string $to, ?string $alias = null): string
    {
        $title = sprintf('Git Analytics — %s (%s — %s)', $branch, $from, $to);

        $sectionHtml = [];
        $sectionNav  = [];

        foreach ($sections as $key => $payload) {
            $def       = $payload['def'];
            $rows      = $payload['rows'] ?? [];
            $isReverts = !empty($payload['is_reverts']);
            $sectionNav[]   = sprintf('<li><a href="#%s">%s</a></li>', $key, htmlspecialchars($def['title'], ENT_QUOTES, 'UTF-8'));
            $sectionHtml[]  = $this->buildSection($key, $def, $rows, $isReverts);
        }

        $generated = date('Y-m-d H:i:s');
        $aliasInfo = $alias !== null
            ? sprintf('<div class="meta-row"><strong>Фільтр:</strong> <code>--alias=%s</code></div>', htmlspecialchars($alias, ENT_QUOTES, 'UTF-8'))
            : '';

        $nav      = implode("\n        ", $sectionNav);
        $sections = implode("\n", $sectionHtml);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$this->h($title)}</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #f7f8fa;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --accent: #2563eb;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 0;
        }
        header {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 1.5rem 2rem;
        }
        header h1 { margin: 0 0 .5rem 0; font-size: 1.5rem; }
        .meta-row { color: var(--muted); font-size: .9rem; margin-top: .3rem; }
        .meta-row code { background: #f1f5f9; padding: 1px 6px; border-radius: 3px; font-size: .85em; }
        nav.toc {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 1rem 2rem;
        }
        nav.toc ul { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem; }
        nav.toc li a {
            display: inline-block;
            padding: .35rem .8rem;
            background: #eef2ff;
            color: var(--accent);
            border-radius: 4px;
            text-decoration: none;
            font-size: .85rem;
        }
        nav.toc li a:hover { background: var(--accent); color: #fff; }
        main { padding: 1.5rem 2rem 3rem; max-width: 1400px; margin: 0 auto; }
        section.report-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
        }
        section.report-card h2 { margin: 0 0 .25rem 0; font-size: 1.15rem; }
        section.report-card .subtitle { color: var(--muted); font-size: .85rem; margin-bottom: .8rem; }
        .chart-wrapper { position: relative; height: 420px; }
        .empty { color: var(--muted); font-style: italic; padding: 2rem 0; text-align: center; }
        footer {
            color: var(--muted);
            font-size: .8rem;
            text-align: center;
            padding: 1.5rem;
        }
    </style>
</head>
<body>
    <header>
        <h1>{$this->h($title)}</h1>
        <div class="meta-row"><strong>Гілка:</strong> <code>{$this->h($branch)}</code></div>
        <div class="meta-row"><strong>Період:</strong> {$this->h($from)} — {$this->h($to)}</div>
        {$aliasInfo}
        <div class="meta-row"><strong>Згенеровано:</strong> {$generated}</div>
    </header>
    <nav class="toc">
        <ul>
        {$nav}
        </ul>
    </nav>
    <main>
{$sections}
    </main>
    <footer>
        Згенеровано за допомогою bin/report.php --make-charts
    </footer>
</body>
</html>
HTML;

        return $html;
    }

    // ------------------------------------------------------------------
    // Section rendering
    // ------------------------------------------------------------------

    private function buildSection(string $key, array $def, array $rows, bool $isReverts): string
    {
        $title    = $def['title'] ?? $key;
        $renderer = $def['renderer'] ?? 'simple';
        $canvasId = 'chart-' . $key;
        $rowCount = count($rows);

        if ($rowCount === 0) {
            return sprintf(
                '<section class="report-card" id="%s"><h2>%s</h2><div class="empty">Немає даних для цього звіту за вказаний період.</div></section>',
                $this->h($key),
                $this->h($title)
            );
        }

        // Build per-renderer chart config
        $config = $renderer === 'pivot'
            ? $this->buildPivotChartConfig($def, $rows)
            : $this->buildSimpleChartConfig($def, $rows);

        $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sprintf(
            '    <section class="report-card" id="%s">' . "\n"
            . '        <h2>%s</h2>' . "\n"
            . '        <div class="subtitle">Тип: %s. Рядків даних: %d.</div>' . "\n"
            . '        <div class="chart-wrapper"><canvas id="%s"></canvas></div>' . "\n"
            . '        <script>new Chart(document.getElementById(%s), %s);</script>' . "\n"
            . '    </section>',
            $this->h($key),
            $this->h($title),
            $this->h($renderer === 'pivot' ? 'pivot (multi-line, top-' . self::TOP_N . ')' : 'simple (horizontal bar, top-' . self::TOP_N . ')'),
            $rowCount,
            $this->h($canvasId),
            json_encode($canvasId, JSON_UNESCAPED_UNICODE),
            $configJson
        );
    }

    /**
     * Horizontal bar chart for simple reports (one metric per developer).
     */
    private function buildSimpleChartConfig(array $def, array $rows): array
    {
        $sorted = $rows;
        usort($sorted, static fn(array $a, array $b): int =>
            ((int) $b['value']) <=> ((int) $a['value'])
        );
        $top = array_slice($sorted, 0, self::TOP_N);

        $labels = array_map(fn(array $r): string => $this->shortName((string) $r['dev_name']), $top);
        $values = array_map(static fn(array $r): int => (int) $r['value'], $top);

        return [
            'type' => 'bar',
            'data' => [
                'labels'   => $labels,
                'datasets' => [[
                    'label'           => $def['metricLabel'] ?? 'Значення',
                    'data'            => $values,
                    'backgroundColor' => '#2563eb',
                    'borderRadius'    => 4,
                ]],
            ],
            'options' => [
                'indexAxis'  => 'y',
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins'    => [
                    'legend' => ['display' => false],
                    'title'  => ['display' => false],
                ],
                'scales' => [
                    'x' => ['beginAtZero' => true],
                ],
            ],
        ];
    }

    /**
     * Multi-line chart for pivot reports (top-N devs across periods).
     */
    private function buildPivotChartConfig(array $def, array $rows): array
    {
        // Aggregate dev → [period => value] and dev → total
        $matrix  = [];
        $periods = [];
        foreach ($rows as $r) {
            $devId  = (int) ($r['dev_id'] ?? 0);
            $period = (string) ($r['period'] ?? '');
            $value  = (int) ($r['value'] ?? 0);
            if ($period === '') {
                continue;
            }
            $periods[$period] = true;
            if (!isset($matrix[$devId])) {
                $matrix[$devId] = [
                    'name'    => (string) ($r['dev_name'] ?? ''),
                    'periods' => [],
                    'total'   => 0,
                ];
            }
            $matrix[$devId]['periods'][$period] = ($matrix[$devId]['periods'][$period] ?? 0) + $value;
            $matrix[$devId]['total']           += $value;
        }

        ksort($periods, SORT_STRING);
        $periodList = array_keys($periods);

        uasort($matrix, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
        $top = array_slice($matrix, 0, self::TOP_N, true);

        $palette = [
            '#2563eb', '#dc2626', '#16a34a', '#ea580c', '#9333ea',
            '#0891b2', '#c026d3', '#65a30d', '#e11d48', '#7c3aed',
        ];

        $datasets = [];
        $i = 0;
        foreach ($top as $dev) {
            $color = $palette[$i % count($palette)];
            $data  = array_map(static fn(string $p): int => (int) ($dev['periods'][$p] ?? 0), $periodList);
            $datasets[] = [
                'label'           => $this->shortName($dev['name']),
                'data'            => $data,
                'borderColor'     => $color,
                'backgroundColor' => $color . '33',
                'tension'         => 0.25,
                'fill'            => false,
                'pointRadius'     => 3,
            ];
            $i++;
        }

        return [
            'type' => 'line',
            'data' => [
                'labels'   => $periodList,
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'interaction' => ['mode' => 'index', 'intersect' => false],
                'plugins' => [
                    'legend' => ['position' => 'bottom'],
                    'title'  => ['display' => false],
                ],
                'scales' => [
                    'y' => ['beginAtZero' => true],
                ],
            ],
        ];
    }

    private function shortName(string $name): string
    {
        $name = (string) preg_replace('/\s*<[^>]+>\s*$/u', '', $name);
        if (mb_strlen($name, 'UTF-8') > 32) {
            $name = mb_substr($name, 0, 31, 'UTF-8') . '…';
        }
        return $name;
    }

    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
