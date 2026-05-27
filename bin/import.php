<?php
declare(strict_types=1);

/**
 * Git analytics — main CLI import entrypoint.
 *
 * Usage:
 *   php bin/import.php --date-from=2023-08-28 --date-to=2026-04-25
 *   php bin/import.php --branch=master --date-from=2023-08-28 --date-to=2026-04-25 --dry-run
 *   php bin/import.php --check-requirements
 *   php bin/import.php --help
 *
 * Options:
 *   --branch=<name>        Branch to analyze (optional; auto-detects master/main if omitted)
 *   --date-from=<date>     Start date YYYY-MM-DD (required)
 *   --date-to=<date>       End date YYYY-MM-DD (required)
 *   --repo-path=<path>     Path to git repository (optional, overrides config/config.php)
 *   --dry-run              Parse and log without writing to DB
 *   --fresh                Wipe DB file and recreate schema before import
 *   --check-requirements   Check system requirements and exit
 *   --verbose              Reserved for future verbose output
 *   --help                 Show help
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.' . PHP_EOL);
}

ini_set('memory_limit', '512M');

$baseDir = dirname(__DIR__);

// Load all source classes (simple require — no Composer in this standalone module)
foreach ([
    'Config', 'Db', 'Logger',
    'ProjectResolver',
    'RequirementsChecker',
    'GitCommandRunner',
    'CommitCollector',
    'TicketExtractor',
    'RevertDetector',
    'ImportRunRepository',
    'DeveloperRepository',
    'CommitRepository',
    'TicketRepository',
    'CommitTicketRepository',
    'RevertRepository',
    'ImportPipeline',
] as $class) {
    require_once $baseDir . '/src/' . $class . '.php';
}

// ---- Parse CLI arguments (before loading config so --help works without config) ----

$opts = getopt('', [
    'branch::',
    'date-from:',
    'date-to:',
    'project::',
    'repo-path::',
    'dry-run',
    'fresh',
    'check-requirements',
    'verbose',
    'help',
]);

if (isset($opts['help'])) {
    echo <<<HELP
Git Analytics Import
====================

Usage:
  php bin/import.php --date-from=<YYYY-MM-DD> --date-to=<YYYY-MM-DD> [OPTIONS]

Required:
  --date-from=<date>     Start date (inclusive), format: YYYY-MM-DD
  --date-to=<date>       End date (inclusive), format: YYYY-MM-DD

Optional:
  --branch=<name>        Branch to analyze (default: auto-detects "master" or "main")
  --project=<name>       Project key from the 'projects' map in config/config.php
                         (default: first project in the map)
  --repo-path=<path>     Absolute path to git repository — overrides both
                         --project and config values
  --dry-run              Run full pipeline without writing to DB
  --fresh                Wipe DB file and recreate schema before import
                         (use to avoid FK constraint errors from stale data)
  --check-requirements   Check system requirements and exit
  --verbose              Verbose output (reserved)
  --help                 Show this help

Examples:
  php bin/import.php \
      --date-from=2023-08-28 \
      --date-to=2026-04-25

  php bin/import.php \
      --branch=master \
      --date-from=2023-08-28 \
      --date-to=2026-04-25

  php bin/import.php \
      --project=awesome-project \
      --branch=master \
      --date-from=2023-08-28 \
      --date-to=2026-04-25 \
      --fresh

  php bin/import.php --check-requirements

HELP;
    exit(0);
}

// ---- Requirements check (always runs; exits early if --check-requirements flag set) ----

/**
 * Print requirements check results and return whether all passed.
 */
function runRequirementsCheck(bool $verbose): bool
{
    $checker = new RequirementsChecker();
    $results = $checker->check();
    $passed  = $checker->allPassed($results);

    $colWidth = 45;
    echo PHP_EOL . 'Requirements check:' . PHP_EOL;
    echo str_repeat('-', 65) . PHP_EOL;

    foreach ($results as $r) {
        $status = $r['ok'] ? '  OK  ' : ' FAIL ';
        $label  = str_pad($r['label'], $colWidth);
        echo "[{$status}] {$label}";
        if (!$r['ok'] || $verbose) {
            echo '  ' . $r['detail'];
        }
        echo PHP_EOL;
    }

    echo str_repeat('-', 65) . PHP_EOL;
    echo ($passed ? 'All requirements met.' : 'Some requirements are NOT met.') . PHP_EOL . PHP_EOL;

    return $passed;
}

$configPath = $baseDir . '/config/config.php';

// --check-requirements: load config if present so that db/output/repo checks have values.
// If config is missing the requirements checker will report it as FAIL — that is correct.
if (isset($opts['check-requirements'])) {
    if (file_exists($configPath)) {
        Config::load($configPath);
    }
    $outputPath = (string) Config::get('output.path', $baseDir . '/output');
    Logger::init($outputPath);

    $passed = runRequirementsCheck(verbose: true);
    exit($passed ? 0 : 1);
}

// ---- Ensure config file exists (required for all other operations) ----

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

// Load configuration
Config::load($configPath);

// Init logger (after config is loaded)
$outputPath = (string) Config::get('output.path', $baseDir . '/output');
Logger::init($outputPath);

// Auto-check on every run — abort if requirements are not met.
$passed = runRequirementsCheck(verbose: false);
if (!$passed) {
    Logger::error('System requirements not met. Run with --check-requirements for details.');
    exit(1);
}

// ---- Validate required arguments ----

$errors = [];


if (empty($opts['date-from'])) {
    $errors[] = '--date-from is required';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $opts['date-from'])) {
    $errors[] = '--date-from must be in YYYY-MM-DD format';
}

if (empty($opts['date-to'])) {
    $errors[] = '--date-to is required';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $opts['date-to'])) {
    $errors[] = '--date-to must be in YYYY-MM-DD format';
}

if (empty($errors) && isset($opts['date-from'], $opts['date-to'])) {
    if ($opts['date-from'] > $opts['date-to']) {
        $errors[] = '--date-from must be earlier than or equal to --date-to';
    }
}

if (!empty($errors)) {
    foreach ($errors as $e) {
        Logger::error($e);
    }
    echo PHP_EOL . 'Run with --help for usage.' . PHP_EOL;
    exit(1);
}

$branch   = isset($opts['branch']) ? trim((string) $opts['branch']) : '';
$dateFrom = (string) $opts['date-from'];
$dateTo   = (string) $opts['date-to'];
$dryRun   = isset($opts['dry-run']);
$fresh    = isset($opts['fresh']);

// ---- Resolve project / repo path ----
// --repo-path overrides everything; otherwise use --project or config default.

if (isset($opts['repo-path']) && $opts['repo-path'] !== '') {
    $repoPath    = (string) $opts['repo-path'];
    $projectName = ProjectResolver::sanitize(basename(rtrim($repoPath, DIRECTORY_SEPARATOR . '/')));
} else {
    $projectArg = isset($opts['project']) && $opts['project'] !== ''
        ? (string) $opts['project']
        : null;
    try {
        $project     = ProjectResolver::resolve($projectArg);
        $projectName = $project['name'];
        $repoPath    = $project['path'];
    } catch (InvalidArgumentException $e) {
        Logger::error($e->getMessage());
        exit(1);
    }
}

// ---- Validate repository path ----

if (!is_dir($repoPath)) {
    Logger::error("Repository path does not exist: {$repoPath}");
    if ($repoPath === '/path/to/repo' || $repoPath === '/path/to/my-project') {
        Logger::error('This appears to be the default placeholder value from config.example.php.');
        Logger::error("Please update the 'git-projects' map in config/config.php with your actual repository path.");
    }
    exit(1);
}

if (!is_dir($repoPath . '/.git')) {
    Logger::error("Not a git repository (no .git directory): {$repoPath}");
    exit(1);
}

// ---- Validate branch / auto-detect if omitted ----

$gitRunner = new GitCommandRunner($repoPath);

if ($branch === '') {
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
} elseif (!$gitRunner->branchExists($branch)) {
    Logger::error("Branch '{$branch}' does not exist in repository: {$repoPath}");
    $available = $gitRunner->listBranches();
    if (!empty($available)) {
        Logger::error('Available local branches: ' . implode(', ', $available));
    }
    exit(1);
}

// ---- Start ----

Logger::info('Import started');
Logger::info("Project: {$projectName} | Branch: {$branch} | Period: {$dateFrom} – {$dateTo}");
Logger::info("Repo: {$repoPath}");

if ($dryRun) {
    Logger::info('[DRY-RUN] No data will be written to DB');
}

// ---- Fresh DB reset (optional) ----

if ($fresh && !$dryRun) {
    Db::reset();
    $dbPath = (string) Config::get('db.path');
    foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
        if (file_exists($file) && !unlink($file)) {
            Logger::error("Cannot delete DB file: {$file}");
            exit(1);
        }
    }
    Logger::info('[FRESH] DB wiped: ' . $dbPath);
}

// ---- Ensure schema exists (idempotent: CREATE IF NOT EXISTS) ----

if (!$dryRun) {
    $schemaFile = $baseDir . '/schema.sqlite.sql';
    Db::initSchema($schemaFile);
    if ($fresh) {
        Logger::info('[FRESH] Schema recreated from ' . basename($schemaFile));
    }
}

// ---- Wire dependencies ----

$ticketExtractor  = new TicketExtractor();
$commitCollector  = new CommitCollector($gitRunner);
$importRunRepo    = new ImportRunRepository();
$developerRepo    = new DeveloperRepository();
$commitRepo       = new CommitRepository();
$ticketRepo       = new TicketRepository();
$commitTicketRepo = new CommitTicketRepository();
$revertRepo       = new RevertRepository();
$revertDetector   = new RevertDetector($ticketExtractor, $commitRepo);

$pipeline = new ImportPipeline(
    $commitCollector,
    $ticketExtractor,
    $revertDetector,
    $importRunRepo,
    $developerRepo,
    $commitRepo,
    $ticketRepo,
    $commitTicketRepo,
    $revertRepo
);

// ---- Run pipeline ----

try {
    $pipeline->run([
        'project_name' => $projectName,
        'branch'       => $branch,
        'date_from'    => $dateFrom,
        'date_to'      => $dateTo,
        'repo_path'    => $repoPath,
        'dry_run'      => $dryRun,
    ]);
    exit(0);
} catch (Throwable $e) {
    Logger::error('Fatal: ' . $e->getMessage());
    exit(1);
}
