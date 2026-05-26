<?php
declare(strict_types=1);

/**
 * Orchestrates data export to CSV / XLSX.
 *
 * Mirrors ReportRunner semantics:
 *   - read-only; never imports from git
 *   - validates DB readiness (views + alias_id column)
 *   - applies developer aliases unless opted out (--skip-aliases)
 *   - resolves which reports to export
 *   - dispatches to CsvExporter (one file per report) and/or XlsxExporter
 *     (one workbook, one sheet per report)
 *
 * Output:
 *   reports/dd.mm.YYYY-dd.mm.YYYY/exports/
 *       <key>.csv               (one per report when format=csv|both)
 *       git-analytics.xlsx      (single workbook when format=xlsx|both)
 */
final class ExportRunner
{
    public function __construct(
        private readonly ReportRepository   $repo,
        private readonly ReportTableBuilder $tableBuilder,
        private readonly CsvExporter        $csv,
        private readonly XlsxExporter       $xlsx,
        private readonly string             $baseDir,
        private readonly string             $reportsRoot
    ) {}

    /**
     * @param array{
     *     branch:string, date_from:string, date_to:string,
     *     report:string, format:string, force:bool,
     *     apply_aliases?:bool, alias?:?string, detail?:bool, project_name?:string
     * } $opts
     */
    public function run(array $opts): void
    {
        $branch       = $opts['branch'];
        $from         = $opts['date_from'];
        $to           = $opts['date_to'];
        $reportKey    = $opts['report'];
        $format       = $opts['format'];
        $force        = (bool) $opts['force'];
        $applyAliases = (bool) ($opts['apply_aliases'] ?? true);
        $alias        = isset($opts['alias']) && $opts['alias'] !== '' ? (string) $opts['alias'] : null;
        $detail       = (bool) ($opts['detail'] ?? false);

        if (empty($opts['project_name'])) {
            throw new RuntimeException("'project_name' must be passed to ExportRunner::run().");
        }
        $projectName = (string) $opts['project_name'];

        $this->ensureDbReady($applyAliases);
        $this->ensureDataExists($branch, $from, $to);

        $exportDir = $this->reportsRoot . '/' . $projectName . '/' . $this->dirNameFor($from, $to) . '/exports';
        if (!is_dir($exportDir) && !mkdir($exportDir, 0755, true) && !is_dir($exportDir)) {
            throw new RuntimeException("Cannot create exports directory: {$exportDir}");
        }

        $keys = $this->resolveReports($reportKey);

        // Build tables once — used by both CSV and XLSX outputs.
        // Format: [ key => [ 'def' => $def, 'table' => $table ] ]
        $tables    = [];
        $detailRow = null; // single shared revert-details table (if --detail)

        foreach ($keys as $key) {
            $def       = ReportDefinitions::get($key);
            $isReverts = str_starts_with($key, 'reverts-');
            $aliasArg  = ($isReverts && $alias !== null) ? $alias : null;

            $rows = $isReverts
                ? $this->repo->{$def['method']}($branch, $from, $to, $aliasArg)
                : $this->repo->{$def['method']}($branch, $from, $to);

            $zeroDevs = $isReverts
                ? $this->repo->commitDevelopersInPeriod($branch, $from, $to, $aliasArg)
                : [];

            $tables[$key] = [
                'def'        => $def,
                'is_reverts' => $isReverts,
                'table'      => $this->tableBuilder->build($def, $rows, $zeroDevs),
            ];

            Logger::info(sprintf(
                'Prepared: %s (%d data rows%s)',
                $key,
                count($rows),
                $isReverts ? ', ' . count($zeroDevs) . ' zero-dev candidates' : ''
            ));
        }

        // Detail table (only built once; appended to CSV/XLSX as separate output).
        if ($detail) {
            $detailRows = $this->repo->revertDetails($branch, $from, $to, $alias);
            $detailRow  = $this->tableBuilder->buildRevertDetails($detailRows, $alias);
            Logger::info(sprintf('Prepared: revert-details (%d commits)', count($detailRows)));
        }

        $wantCsv  = in_array($format, ['csv', 'both'], true);
        $wantXlsx = in_array($format, ['xlsx', 'both'], true);

        if ($wantCsv) {
            $this->exportCsv($exportDir, $tables, $detailRow, $force);
        }

        if ($wantXlsx) {
            $this->exportXlsx($exportDir, $tables, $detailRow, $force);
        }

        Logger::info('Done. Exports: ' . $exportDir);
    }

    // ------------------------------------------------------------------

    private function exportCsv(string $exportDir, array $tables, ?array $detailRow, bool $force): void
    {
        foreach ($tables as $key => $payload) {
            $target = $exportDir . '/' . $key . '.csv';
            $this->warnIfExists($target, $force, $key . '.csv');
            $this->csv->write($payload['table'], $target);
            Logger::info('Generated: ' . basename($target));
        }

        if ($detailRow !== null) {
            $target = $exportDir . '/revert-details.csv';
            $this->warnIfExists($target, $force, 'revert-details.csv');
            $this->csv->write($detailRow, $target);
            Logger::info('Generated: ' . basename($target));
        }
    }

    private function exportXlsx(string $exportDir, array $tables, ?array $detailRow, bool $force): void
    {
        $sheets = [];
        foreach ($tables as $key => $payload) {
            $sheets[] = ['name' => $key, 'table' => $payload['table']];
        }
        if ($detailRow !== null) {
            $sheets[] = ['name' => 'revert-details', 'table' => $detailRow];
        }

        $target = $exportDir . '/git-analytics.xlsx';
        $this->warnIfExists($target, $force, 'git-analytics.xlsx');
        $this->xlsx->write($sheets, $target);
        Logger::info('Generated: ' . basename($target) . ' (' . count($sheets) . ' sheets)');
    }

    private function warnIfExists(string $target, bool $force, string $label): void
    {
        if (file_exists($target) && !$force) {
            Logger::warning("Exists, overwriting: {$label} (pass --force to silence) — overwriting anyway");
        }
    }

    // ------------------------------------------------------------------

    private function ensureDbReady(bool $applyAliases): void
    {
        Db::initSchema($this->baseDir . '/schema.sqlite.sql');

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

        foreach (['vw_commit_facts', 'vw_revert_facts'] as $view) {
            if (!$this->repo->hasView($view)) {
                throw new RuntimeException(
                    "Missing analytics view: {$view}. " .
                    'Run without --skip-aliases or run: php bin/apply-aliases.php'
                );
            }
        }
    }

    private function ensureDataExists(string $branch, string $from, string $to): void
    {
        $count = $this->repo->commitsCountInPeriod($branch, $from, $to);
        if ($count > 0) {
            Logger::info("Commits in period: {$count}");
            return;
        }

        throw new RuntimeException(sprintf(
            "Неможливо створити експорт: у БД немає даних для branch=%s, period=%s..%s.\n" .
            "Цей скрипт не виконує імпорт з git. Спочатку імпортуйте дані:\n" .
            "  php bin/import.php --branch=%s --date-from=%s --date-to=%s --fresh\n" .
            "Після успішного імпорту повторіть запуск bin/export.php.",
            $branch, $from, $to,
            $branch, $from, $to
        ));
    }

    /** @return string[] */
    private function resolveReports(string $key): array
    {
        if ($key === ReportDefinitions::FULL || $key === ReportDefinitions::ALL) {
            return ReportDefinitions::allKeys();
        }
        return [$key];
    }

    private function dirNameFor(string $from, string $to): string
    {
        return DateTimeImmutable::createFromFormat('Y-m-d', $from)->format('d.m.Y')
             . '-' .
               DateTimeImmutable::createFromFormat('Y-m-d', $to)->format('d.m.Y');
    }
}
