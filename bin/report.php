<?php
declare(strict_types=1);

/**
 * Git analytics — report generator.
 *
 * READ-ONLY for the source repository: this script does NOT import data from
 * git. It only renders markdown reports from the SQLite git_analytics DB
 * previously populated by bin/import.php.
 *
 * If no data exists in the DB for the requested (branch, period) — the
 * script exits with an error and instructs the user to run bin/import.php.
 *
 * Reports honour developer aliases (developers.alias_id, applied by
 * bin/apply-aliases.php) via the vw_commit_facts / vw_revert_facts views.
 *
 * Output: test/git-analytics/reports/dd.mm.YYYY-dd.mm.YYYY/<key>.md
 *
 * Usage:
 *   php bin/report.php --branch=develop --date-from=2023-08-28 --date-to=2026-04-25
 *   php bin/report.php --branch=develop --date-from=... --date-to=... --report=commits-full-period
 *   php bin/report.php --branch=develop --date-from=... --date-to=... --report=all
 *   php bin/report.php --help
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.' . PHP_EOL);
}

ini_set('memory_limit', '512M');

$baseDir = dirname(__DIR__);

foreach ([
    'Config', 'Db', 'Logger',
    'ProjectResolver',
    'AliasApplier',
    'ReportDefinitions',
    'ReportRepository',
    'MarkdownTableBuilder',
    'MermaidChartBuilder',
    'HtmlDashboardBuilder',
    'ReportRunner',
] as $class) {
    require_once $baseDir . '/src/' . $class . '.php';
}

// ----------------------------------------------------------------------
// CLI args (parsed before loading config so --help works without config)
// ----------------------------------------------------------------------

$opts = getopt('', [
    'branch:',
    'date-from:',
    'date-to:',
    'project::',
    'report::',
    'alias::',
    'detail',
    'with-reverts-report',
    'make-charts',
    'force',
    'skip-aliases',
    'help',
]);

if (isset($opts['help'])) {
    // Build report list table dynamically from ReportDefinitions
    $keyWidth = max(
        strlen(ReportDefinitions::FULL),
        strlen(ReportDefinitions::ALL),
        max(array_map('strlen', ReportDefinitions::allKeys()))
    );

    $reportLines = [];
    $reportLines[] = sprintf(
        "    %-{$keyWidth}s   %s",
        ReportDefinitions::FULL,
        '(default) Combined report — commits-* and lines-* sections in one file'
    );
    $reportLines[] = sprintf(
        "    %-{$keyWidth}s   %s",
        ReportDefinitions::ALL,
        'Same as full-report PLUS each section as its own .md file'
    );
    $reportLines[] = '';
    foreach (ReportDefinitions::REPORTS as $key => $def) {
        $reportLines[] = sprintf("    %-{$keyWidth}s   %s", $key, $def['title']);
    }
    $reports = implode("\n", $reportLines);

    echo <<<HELP
Git Analytics — Report Generator
================================

This script ONLY reads the SQLite DB and renders markdown reports.
It does NOT collect data from git. Before running, you MUST import the
relevant period with bin/import.php (otherwise reports will fail with
"No data for the period").

Recommended workflow:
  1. php bin/import.php  --branch=develop --date-from=… --date-to=… [--fresh]
  2. php bin/report.php  --branch=develop --date-from=… --date-to=… [--report=<key>]

By default this script applies developer aliases automatically (idempotent —
same effect as running bin/apply-aliases.php before reports). Pass
--skip-aliases to disable this step if you have already applied them or
prefer to manage aliases manually.

Usage:
  php bin/report.php --branch=<name> --date-from=<YYYY-MM-DD> --date-to=<YYYY-MM-DD> [OPTIONS]

Required:
  --branch=<name>        Branch (e.g. develop)
  --date-from=<date>     Period start, YYYY-MM-DD (inclusive)
  --date-to=<date>       Period end,   YYYY-MM-DD (inclusive)

Optional:
  --project=<name>       Project key from the 'projects' map in config/config.php
                         (default: first project in the map)
  --report=<key>         Which report to generate. Default: full-report
   --alias=<value>        Filter reverts-* reports by a developer. Match against
                          email local-part, full email, author_name, or display.
                          Example: --alias=jane.smith
                          (ignored for non-reverts reports)
   --detail               Append a per-developer list of individual revert
                          commits (date, hash, ticket, subject) to reverts-*
                          reports. Combine with --alias to focus on one dev.
   --with-reverts-report  Include reverts-* sections when generating full-report
                          or all. By default these sections are omitted.
                          (Has no effect when --report=reverts-* is specified
                          directly — those are always generated.)
   --make-charts          Also generate an interactive HTML dashboard
                         (Chart.js) at reports/<period>/diagrams/index.html
  --force                Overwrite existing .md files without warning
  --skip-aliases         Do NOT run AliasApplier before generating reports
  --help                 Show this help

Charts:
  - Mermaid diagrams (top-N bar; line for pivot reports) are appended to
    every markdown report by default. GitHub/GitLab render them inline.
  - With --make-charts an interactive Chart.js dashboard is written to
    reports/<period>/diagrams/index.html (one section per report, with
    horizontal bars for simple reports and multi-line for pivots).

Available reports (--report=<key>):
{$reports}

Output:
  reports/dd.mm.YYYY-dd.mm.YYYY/<key>.md
  (e.g. reports/28.08.2023-25.04.2026/commits-full-period.md)

All reports honour developer aliases (developers.alias_id, set via
bin/apply-aliases.php). Duplicates of the same person are collapsed into
one canonical row.

Errors:
  - If no data exists in the DB for the (branch, period) the script aborts
    with a message instructing you to run bin/import.php first.

Examples:
  php bin/report.php --branch=develop --date-from=2023-08-28 --date-to=2026-04-25
  php bin/report.php --branch=develop --date-from=2023-08-28 --date-to=2026-04-25 --report=commits-full-period
  php bin/report.php --branch=develop --date-from=2023-08-28 --date-to=2026-04-25 --report=all --force
  php bin/report.php --branch=develop --date-from=2023-08-28 --date-to=2026-04-25 --report=all --with-reverts-report --force
  php bin/report.php --branch=develop --date-from=2025-12-01 --date-to=2025-12-31 \\
      --report=reverts-by-month --detail --alias=jsmith

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
if ($branch === '') {
    $errors[] = '--branch is required';
}

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
    : ReportDefinitions::FULL;

if (!ReportDefinitions::isValid($reportKey)) {
    $errors[] = sprintf(
        "--report=%s is unknown. Available: %s, %s, %s",
        $reportKey,
        ReportDefinitions::FULL,
        ReportDefinitions::ALL,
        implode(', ', ReportDefinitions::allKeys())
    );
}

if (!empty($errors)) {
    foreach ($errors as $e) {
        Logger::error($e);
    }
    echo PHP_EOL . 'Run with --help for usage.' . PHP_EOL;
    exit(1);
}

$force        = isset($opts['force']);
$skipAliases  = isset($opts['skip-aliases']);
$alias        = isset($opts['alias']) && $opts['alias'] !== '' ? trim((string) $opts['alias']) : null;
$detail       = isset($opts['detail']);
$makeCharts   = isset($opts['make-charts']);
$withReverts  = isset($opts['with-reverts-report']);

// --alias / --detail apply only to reverts-* reports. When the user picks a
// non-reverts single report, warn that the flags will be ignored there.
$reportsRevertsOnly = ($alias !== null || $detail);
$isFullOrAll        = in_array($reportKey, [ReportDefinitions::FULL, ReportDefinitions::ALL], true);
$selectedIsReverts  = str_starts_with($reportKey, 'reverts-')
    || ($isFullOrAll && $withReverts);
if ($reportsRevertsOnly && !$selectedIsReverts) {
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

Logger::info('Report generation started');
Logger::info("Project: {$projectName} | Branch: {$branch} | Period: {$dateFrom} – {$dateTo} | Report: {$reportKey}");

$reportsRoot = $baseDir . '/reports';

try {
    $repo          = new ReportRepository();
    $builder       = new MarkdownTableBuilder();
    $mermaid       = new MermaidChartBuilder();
    $htmlDashboard = new HtmlDashboardBuilder();
    $runner        = new ReportRunner(
        $repo, $builder, $baseDir, $reportsRoot, $mermaid, $htmlDashboard
    );

    $runner->run([
        'branch'        => $branch,
        'date_from'     => $dateFrom,
        'date_to'       => $dateTo,
        'report'        => $reportKey,
        'force'         => $force,
        'apply_aliases' => !$skipAliases,
        'alias'         => $alias,
        'detail'        => $detail,
        'make_charts'   => $makeCharts,
        'with_reverts'  => $withReverts,
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
