<?php
declare(strict_types=1);

/**
 * Orchestrates report generation (read-only — never imports from git):
 *   - validates DB readiness (views, alias column)
 *   - verifies data exists for the requested period (fails otherwise)
 *   - resolves the list of reports to generate
 *   - writes each report into reports/dd.mm.YYYY-dd.mm.YYYY/<key>.md
 *   - optionally produces a combined full-report.md
 */
final class ReportRunner
{
    public function __construct(
        private readonly ReportRepository     $repo,
        private readonly MarkdownTableBuilder $builder,
        private readonly string               $baseDir,
        private readonly string               $reportsRoot,
        private readonly MermaidChartBuilder  $mermaid,
        private readonly HtmlDashboardBuilder $htmlDashboard
    ) {}

    /**
     * @param array{
     *     branch:string, date_from:string, date_to:string,
     *     report:string, force:bool, apply_aliases?:bool,
     *     alias?:?string, detail?:bool, make_charts?:bool, with_reverts?:bool,
     *     project_name?:string
     * } $opts
     */
    public function run(array $opts): void
    {
        $branch       = $opts['branch'];
        $from         = $opts['date_from'];
        $to           = $opts['date_to'];
        $reportKey    = $opts['report'];
        $force        = $opts['force'];
        $applyAliases = (bool) ($opts['apply_aliases'] ?? true);
        $alias        = isset($opts['alias']) && $opts['alias'] !== '' ? (string) $opts['alias'] : null;
        $detail       = (bool) ($opts['detail'] ?? false);
        $makeCharts   = (bool) ($opts['make_charts'] ?? false);
        $withReverts  = (bool) ($opts['with_reverts'] ?? false);

        if (empty($opts['project_name'])) {
            throw new RuntimeException("'project_name' must be passed to ReportRunner::run().");
        }
        $projectName = (string) $opts['project_name'];

        $this->ensureDbReady($applyAliases);
        $this->ensureDataExists($projectName, $branch, $from, $to);

        $outDir = $this->reportsRoot . '/' . $projectName . '/' . $this->dirNameFor($from, $to);
        if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new RuntimeException("Cannot create reports directory: {$outDir}");
        }

        $keys             = $this->resolveReports($reportKey, $withReverts);
        $needsCombined    = in_array($reportKey, [ReportDefinitions::FULL, ReportDefinitions::ALL], true);
        $renderedSections = [];

        foreach ($keys as $key) {
            $def         = ReportDefinitions::get($key);
            $isReverts   = str_starts_with($key, 'reverts-');
            $methodAlias = ($isReverts && $alias !== null) ? $alias : null;

            $rows = $isReverts
                ? $this->repo->{$def['method']}($projectName, $branch, $from, $to, $methodAlias)
                : $this->repo->{$def['method']}($projectName, $branch, $from, $to);

            $zeroDevs = $isReverts
                ? $this->repo->commitDevelopersInPeriod($projectName, $branch, $from, $to, $methodAlias)
                : [];

            $md = $this->builder->buildReport($def, $rows, $branch, $from, $to, 1, $zeroDevs);

            $mermaid = $this->mermaid->build($def, $rows);
            if ($mermaid !== '') {
                $md .= $mermaid;
            }

            if ($detail && $isReverts) {
                $detailRows = $this->repo->revertDetails($projectName, $branch, $from, $to, $methodAlias);
                $md        .= $this->builder->buildRevertDetails($detailRows, $methodAlias);
                Logger::info(sprintf('  detail: %d revert commits listed', count($detailRows)));
            }

            $target = $outDir . '/' . $key . '.md';

            if (file_exists($target) && !$force) {
                Logger::warning("Exists, overwriting: {$key}.md (use --force=false to abort) — overwriting anyway");
            }
            file_put_contents($target, $md);
            Logger::info(sprintf(
                'Generated: %s (%d data rows%s)',
                $key . '.md',
                count($rows),
                $isReverts ? ', ' . count($zeroDevs) . ' zero-dev candidates' : ''
            ));

            $renderedSections[$key] = [
                'def'         => $def,
                'rows'        => $rows,
                'is_reverts'  => $isReverts,
                'zero_devs'   => $zeroDevs,
                'detail_rows' => ($detail && $isReverts) ? ($detailRows ?? []) : null,
            ];
        }

        if ($needsCombined) {
            $this->writeCombinedReport($outDir, $renderedSections, $branch, $from, $to, $alias);
        }

        if ($makeCharts) {
            $this->writeHtmlDashboard($outDir, $renderedSections, $branch, $from, $to, $alias);
        }

        Logger::info('Done. Output: ' . $outDir);
    }

    private function writeHtmlDashboard(
        string  $outDir,
        array   $sections,
        string  $branch,
        string  $from,
        string  $to,
        ?string $alias
    ): void {
        $diagramsDir = $outDir . '/diagrams';
        if (!is_dir($diagramsDir) && !mkdir($diagramsDir, 0755, true) && !is_dir($diagramsDir)) {
            throw new RuntimeException("Cannot create diagrams directory: {$diagramsDir}");
        }

        $html   = $this->htmlDashboard->build($sections, $branch, $from, $to, $alias);
        $target = $diagramsDir . '/index.html';
        file_put_contents($target, $html);
        Logger::info('Generated: diagrams/index.html (Chart.js dashboard, ' . count($sections) . ' charts)');
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    private function ensureDbReady(bool $applyAliases): void
    {
        // Ensure schema exists (idempotent).
        Db::initSchema($this->baseDir . '/schema.sqlite.sql');

        // Apply aliases automatically unless opted out. This also creates
        // the alias_id column, recreates views, and reassigns commits/reverts.
        if ($applyAliases) {
            Logger::info('Applying developer aliases (AliasApplier)…');
            $applier = new AliasApplier($this->baseDir);
            $stats   = $applier->apply(dryRun: false, quiet: true);
            Logger::info(sprintf(
                'Aliases — applied: %d, skipped: %d, total pairs: %d, alias records in DB: %d, ' .
                'commits reassigned: %d, reverts reassigned: %d',
                $stats['applied'], $stats['skipped'], $stats['total_pairs'],
                $stats['alias_records'], $stats['commits_reassigned'], $stats['reverts_reassigned']
            ));
        } else {
            Logger::info('Skipping AliasApplier (--skip-aliases).');
        }

        // Verify views and alias_id column exist after (optional) AliasApplier run.
        foreach (['vw_commit_facts', 'vw_revert_facts'] as $view) {
            if (!$this->repo->hasView($view)) {
                throw new RuntimeException(
                    "Missing analytics view: {$view}. " .
                    'Run without --skip-aliases or run: php bin/apply-aliases.php'
                );
            }
        }

        $pdo  = Db::getInstance();
        $cols = array_column(
            $pdo->query('PRAGMA table_info(developers)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        if (!in_array('alias_id', $cols, true)) {
            throw new RuntimeException(
                'developers.alias_id column is missing. Run without --skip-aliases ' .
                'or run: php bin/apply-aliases.php'
            );
        }

        $aliased = $this->repo->aliasedDevelopersCount();
        if ($aliased === 0) {
            Logger::warning(
                'No developer aliases applied (developers.alias_id is empty for all rows). ' .
                'Duplicate developer entries will appear as separate rows in reports.'
            );
        } else {
            Logger::info("Developer aliases active: {$aliased}");
        }
    }

    /**
     * Hard-fail if no data exists for the (branch, period). This script never
     * imports from git — that is the job of bin/import.php.
     */
    private function ensureDataExists(string $project, string $branch, string $from, string $to): void
    {
        $count = $this->repo->commitsCountInPeriod($project, $branch, $from, $to);

        if ($count > 0) {
            Logger::info("Commits in period: {$count}");
            return;
        }

        throw new RuntimeException(sprintf(
            "Неможливо створити звіт: у БД немає даних для project=%s, branch=%s, period=%s..%s.\n" .
            "Цей скрипт не виконує імпорт з git. Спочатку імпортуйте дані:\n" .
            "  php bin/import.php --project=%s --branch=%s --date-from=%s --date-to=%s --fresh\n" .
            "Після успішного імпорту повторіть запуск bin/report.php.",
            $project, $branch, $from, $to,
            $project, $branch, $from, $to
        ));
    }

    /** @return string[] List of individual report keys to generate. */
    private function resolveReports(string $key, bool $withReverts = false): array
    {
        if ($key === ReportDefinitions::FULL || $key === ReportDefinitions::ALL) {
            return $withReverts ? ReportDefinitions::allKeys() : ReportDefinitions::defaultKeys();
        }
        return [$key];
    }

    private function writeCombinedReport(
        string  $outDir,
        array   $sections,
        string  $branch,
        string  $from,
        string  $to,
        ?string $alias = null
    ): void {
        $parts = [];
        $parts[] = $this->builder->heading('Повний звіт по git-аналітиці', 1);
        $parts[] = '';
        $parts[] = $this->builder->meta($branch, $from, $to);
        if ($alias !== null) {
            $parts[] = '';
            $parts[] = "**Фільтр відкатів:** `--alias={$alias}`";
        }
        $parts[] = '';
        $parts[] = '## Зміст';
        $parts[] = '';
        $i = 0;
        foreach ($sections as $payload) {
            $i++;
            $anchor = $this->anchor($payload['def']['title']);
            $parts[] = sprintf('%d. [%s](#%s)', $i, $payload['def']['title'], $anchor);
        }
        $parts[] = '';

        foreach ($sections as $payload) {
            $parts[] = '---';
            $parts[] = '';
            $parts[] = $this->builder->heading($payload['def']['title'], 2);
            $parts[] = '';
            $parts[] = $this->builder->buildSection(
                $payload['def'],
                $payload['rows'],
                2,
                $payload['zero_devs'] ?? []
            );

            // Inline Mermaid chart (when data present).
            $mermaid = $this->mermaid->build($payload['def'], $payload['rows']);
            if ($mermaid !== '') {
                $parts[] = $mermaid;
            }

            // Inline --detail section right after each reverts-* table.
            if (!empty($payload['is_reverts']) && is_array($payload['detail_rows'] ?? null)) {
                $parts[] = $this->builder->buildRevertDetails($payload['detail_rows'], $alias);
            }

            $parts[] = '';
        }

        $target = $outDir . '/' . ReportDefinitions::FULL . '.md';
        file_put_contents($target, implode(PHP_EOL, $parts));
        Logger::info('Generated: ' . ReportDefinitions::FULL . '.md (combined)');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function dirNameFor(string $from, string $to): string
    {
        return DateTimeImmutable::createFromFormat('Y-m-d', $from)->format('d.m.Y')
             . '-' .
               DateTimeImmutable::createFromFormat('Y-m-d', $to)->format('d.m.Y');
    }


    private function anchor(string $title): string
    {
        // GitHub-style: lowercase, spaces → '-', strip non-word
        $a = mb_strtolower($title, 'UTF-8');
        $a = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $a) ?? '';
        $a = preg_replace('/\s+/', '-', trim($a)) ?? '';
        return $a;
    }
}
