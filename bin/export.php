<?php
declare(strict_types=1);

/**
 * Git analytics — CSV / XLSX exporter.
 *
 * READ-ONLY for the source repository: this script does NOT import data from
 * git. It only reads the SQLite git_analytics DB previously populated by
 * bin/import.php and writes structured table exports.
 *
 * Output:
 *   reports/dd.mm.YYYY-dd.mm.YYYY/exports/<key>.csv         (--format=csv|both)
 *   reports/dd.mm.YYYY-dd.mm.YYYY/exports/git-analytics.xlsx (--format=xlsx|both)
 *
 * Usage:
 *   php bin/export.php --date-from=2026-01-01 --date-to=2026-04-25
 *   php bin/export.php --branch=master --date-from=… --date-to=… --format=xlsx
 *   php bin/export.php --branch=master --date-from=… --date-to=… --report=commits-full-period --format=csv
 *   php bin/export.php --help
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.' . PHP_EOL);
}

ini_set('memory_limit', '512M');

$baseDir = dirname(__DIR__);

foreach ([
    'Config', 'Db', 'Logger',
    'ProjectResolver',
    'GitCommandRunner',
    'AliasApplier',
    'ReportDefinitions',
    'ReportRepository',
    'ReportTableBuilder',
    'CsvExporter',
    'XlsxExporter',
    'ExportRunner',
] as $class) {
    require_once $baseDir . '/src/' . $class . '.php';
}

// ----------------------------------------------------------------------
// CLI args (parsed before loading config so --help works without config)
// ----------------------------------------------------------------------

$opts = getopt('', [
    'branch::',
    'date-from:',
    'date-to:',
    'project::',
    'report::',
    'format::',
    'alias::',
    'detail',
    'force',
    'skip-aliases',
    'help',
]);

if (isset($opts['help'])) {
    $keyWidth = max(
        strlen(ReportDefinitions::ALL),
        max(array_map('strlen', ReportDefinitions::allKeys()))
    );
    $reportLines = [];
    $reportLines[] = sprintf("    %-{$keyWidth}s   %s", ReportDefinitions::ALL,
        '(default) Export all 9 reports — one file/sheet per report');
    $reportLines[] = '';
    foreach (ReportDefinitions::REPORTS as $key => $def) {
        $reportLines[] = sprintf("    %-{$keyWidth}s   %s", $key, $def['title']);
    }
    $reports = implode("\n", $reportLines);

    echo <<<HELP
Git Analytics — Export to CSV / XLSX
====================================

This script ONLY reads the SQLite DB and writes table exports. It does NOT
collect data from git. Before running, you MUST import the relevant period
with bin/import.php.

Usage:
  php bin/export.php --date-from=<YYYY-MM-DD> --date-to=<YYYY-MM-DD> [OPTIONS]

Required:
  --date-from=<date>     Period start, YYYY-MM-DD (inclusive)
  --date-to=<date>       Period end,   YYYY-MM-DD (inclusive)

Optional:
  --branch=<name>        Branch (default: auto-detects "master" or "main")
  --project=<name>       Project key from the 'projects' map in config/config.php
                         (default: first project in the map)
  --report=<key>         Which report to export. Default: all
  --format=<fmt>         csv | xlsx | both. Default: both
  --alias=<value>        Filter reverts-* exports by a developer (same matching
                         rules as bin/report.php). Ignored for non-reverts.
  --detail               Also export a per-revert details table
                         (revert-details.csv  /  sheet "revert-details").
  --skip-aliases         Do NOT run AliasApplier before exporting
  --force                Overwrite existing files without warning
  --help                 Show this help

Output:
  reports/dd.mm.YYYY-dd.mm.YYYY/exports/
     <key>.csv               (one per report, when format in {csv, both})
     revert-details.csv      (when --detail and format in {csv, both})
     git-analytics.xlsx      (single workbook, sheet per report,
                              when format in {xlsx, both})

Available reports (--report=<key>):
{$reports}

Notes:
  - CSV files are UTF-8 with BOM (so Excel auto-detects encoding).
  - XLSX is a single workbook with one sheet per report (sheet name = report
    key). Sheet names are sanitised and capped at 31 characters per the
    Excel spec.
  - All exports honour developer aliases via vw_commit_facts / vw_revert_facts.

Examples:
  php bin/export.php --date-from=2026-01-01 --date-to=2026-04-25
  php bin/export.php --branch=master --date-from=2025-12-01 --date-to=2025-12-31 \
      --report=reverts-by-month --detail --format=xlsx
  php bin/export.php --branch=master --date-from=2025-01-01 --date-to=2025-12-31 \
      --format=csv --force

HELP;
    exit(0);
}

// ---- Ensure config exists (required for all operations except --help) ----

$configPath = $baseDir . '/config/config.php';
if (!file_exists($configPath)) {
    $examplePath = $baseDir . '/config/config.example.php';
    echo '[ERROR] Configuration file not found: config/config.php' . PHP_EOL;
    echo PHP_EOL;
    echo 'To get started, copy the example configuration:' . PHP_EOL;
    echo '  cp ' . $examplePath . ' ' . $configPath . PHP_EOL;
    echo PHP_EOL;
    echo 'Then edit config/config.php and set up the git-projects map with your repository paths.' . PHP_EOL;
    exit(1);
}

Config::load($configPath);
$outputPath = (string) Config::get('output.path', $baseDir . '/output');
Logger::init($outputPath);

// ---- Resolve project ----

$projectArg = isset($opts['project']) && $opts['project'] !== '' ? (string) $opts['project'] : null;
try {
    $project     = ProjectResolver::resolve($projectArg);
    $projectName = $project['name'];
    $repoPath    = $project['path'];
} catch (InvalidArgumentException $e) {
    echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
    echo PHP_EOL . 'Run with --help for usage.' . PHP_EOL;
    exit(1);
}

// ----------------------------------------------------------------------
// Validate
// ----------------------------------------------------------------------

$errors = [];

$branch = isset($opts['branch']) ? trim((string) $opts['branch']) : '';

$dateFrom = isset($opts['date-from']) ? (string) $opts['date-from'] : '';
$dateTo   = isset($opts['date-to'])   ? (string) $opts['date-to']   : '';

if ($dateFrom === '') {
    $errors[] = '--date-from is required';
} elseif (!validateDate($dateFrom)) {
    $errors[] = "--date-from is not a valid YYYY-MM-DD date: {$dateFrom}";
}

if ($dateTo === '') {
    $errors[] = '--date-to is required';
} elseif (!validateDate($dateTo)) {
    $errors[] = "--date-to is not a valid YYYY-MM-DD date: {$dateTo}";
}

if (empty($errors) && $dateFrom > $dateTo) {
    $errors[] = '--date-from must be earlier than or equal to --date-to';
}

$reportKey = isset($opts['report']) && $opts['report'] !== ''
    ? (string) $opts['report']
    : ReportDefinitions::ALL;

if (!ReportDefinitions::isValid($reportKey)) {
    $errors[] = sprintf(
        "--report=%s is unknown. Available: %s, %s, %s",
        $reportKey,
        ReportDefinitions::FULL,
        ReportDefinitions::ALL,
        implode(', ', ReportDefinitions::allKeys())
    );
}

$format = isset($opts['format']) && $opts['format'] !== ''
    ? strtolower(trim((string) $opts['format']))
    : 'both';

if (!in_array($format, ['csv', 'xlsx', 'both'], true)) {
    $errors[] = "--format must be one of: csv, xlsx, both (got: {$format})";
}

if (!empty($errors)) {
    foreach ($errors as $e) {
        Logger::error($e);
    }
    echo PHP_EOL . 'Run with --help for usage.' . PHP_EOL;
    exit(1);
}

// ---- Auto-detect branch if not provided ----

if ($branch === '') {
    $gitRunner = new GitCommandRunner($repoPath);
    foreach (['master', 'main'] as $candidate) {
        if ($gitRunner->branchExists($candidate)) {
            $branch = $candidate;
            Logger::info("Branch not specified. Auto-detected: '{$branch}'");
            break;
        }
    }
    if ($branch === '') {
        Logger::error('--branch is not specified and neither "master" nor "main" branch was found in repository: ' . $repoPath);
        $available = $gitRunner->listBranches();
        if (!empty($available)) {
            Logger::error('Available branches: ' . implode(', ', $available));
        }
        Logger::error('Please specify a branch explicitly with --branch=<name>');
        exit(1);
    }
}

$force        = isset($opts['force']);
$skipAliases  = isset($opts['skip-aliases']);
$alias        = isset($opts['alias']) && $opts['alias'] !== '' ? trim((string) $opts['alias']) : null;
$detail       = isset($opts['detail']);

// Warn if --alias/--detail given but the selected report is not reverts-*.
$selectedIsReverts = str_starts_with($reportKey, 'reverts-')
    || in_array($reportKey, [ReportDefinitions::FULL, ReportDefinitions::ALL], true);
if (($alias !== null || $detail) && !$selectedIsReverts) {
    Logger::warning(sprintf(
        '--alias/--detail apply only to reverts-* reports. Ignored for --report=%s.',
        $reportKey
    ));
    $alias  = null;
    $detail = false;
}

// ----------------------------------------------------------------------
// Run
// ----------------------------------------------------------------------

Logger::info('Export started');
Logger::info("Project: {$projectName} | Branch: {$branch} | Period: {$dateFrom} – {$dateTo} | Report: {$reportKey} | Format: {$format}");

$reportsRoot = $baseDir . '/reports';

try {
    $repo         = new ReportRepository();
    $tableBuilder = new ReportTableBuilder();
    $csv          = new CsvExporter();
    $xlsx         = new XlsxExporter();
    $runner       = new ExportRunner($repo, $tableBuilder, $csv, $xlsx, $baseDir, $reportsRoot);

    $runner->run([
        'branch'        => $branch,
        'date_from'     => $dateFrom,
        'date_to'       => $dateTo,
        'report'        => $reportKey,
        'format'        => $format,
        'force'         => $force,
        'apply_aliases' => !$skipAliases,
        'alias'         => $alias,
        'detail'        => $detail,
        'project_name'  => $projectName,
    ]);

    exit(0);
} catch (Throwable $e) {
    Logger::error('Fatal: ' . $e->getMessage());
    exit(1);
}

// ----------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------

function validateDate(string $s): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return false;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s);
    if ($dt === false) {
        return false;
    }
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return false;
    }
    return $dt->format('Y-m-d') === $s;
}
