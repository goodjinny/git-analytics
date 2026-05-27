<?php
declare(strict_types=1);

/**
 * CLI wrapper around AliasApplier.
 *
 * Applies developer alias_id mapping to the SQLite DB. Alias pairs are matched
 * by (author_name, author_email), not by primary-key id — safe to run after
 * import.php --fresh, on any DB snapshot, or on any machine.
 *
 * Note: bin/report.php also runs AliasApplier automatically (unless
 * --skip-aliases). This CLI is still useful for standalone runs and --dry-run.
 *
 * Alias file lookup order:
 *   1. config/<project-name>.aliases.json  (project-specific, gitignored)
 *   2. config/aliases.json                 (global fallback, gitignored)
 *   3. config/aliases.example.json         (template — warns user)
 *
 * Usage:
 *   php bin/apply-aliases.php
 *   php bin/apply-aliases.php --project=my-project
 *   php bin/apply-aliases.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.' . PHP_EOL);
}

$baseDir = dirname(__DIR__);

foreach (['Config', 'Db', 'Logger', 'ProjectResolver', 'AliasApplier'] as $class) {
    require_once $baseDir . '/src/' . $class . '.php';
}

// Parse args before loading config so --help works without config
$opts   = getopt('', ['project::', 'dry-run', 'help']);
$dryRun = isset($opts['dry-run']);

if (isset($opts['help'])) {
    echo <<<HELP
apply-aliases — apply developer alias_id migration

Usage:
  php bin/apply-aliases.php [--project=<name>] [--dry-run]

Options:
  --project=<name>   Project key from git-projects in config.php
                     (default: first project in the map).
                     Determines which alias file to load:
                       config/<project-name>.aliases.json
  --dry-run          Show what would be changed without writing to DB
  --help             Show this help

Alias file lookup order (first found wins):
  1. config/<project-name>.aliases.json  (project-specific, gitignored)
  2. config/aliases.json                 (global fallback, gitignored)
  3. config/aliases.example.json         (template — warns and proceeds)

Alias pairs are matched by (author_name, author_email) — independent of
primary-key ids. Safe to re-run after import.php --fresh.

Note: bin/report.php applies the same aliases automatically by default
(use --skip-aliases there to opt out). Running this script separately is
optional and useful mainly for --dry-run inspection.

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

Logger::info('apply-aliases started' . ($dryRun ? ' [DRY-RUN]' : '') . " [project: {$projectName}]");

try {
    $applier = new AliasApplier($baseDir, $projectName);
    $stats   = $applier->apply($dryRun, quiet: false);

    Logger::info('');
    Logger::info(sprintf(
        'Summary: applied=%d, skipped=%d, total_pairs=%d, alias_records_in_DB=%d, commits_reassigned=%d, reverts_reassigned=%d',
        $stats['applied'],
        $stats['skipped'],
        $stats['total_pairs'],
        $stats['alias_records'],
        $stats['commits_reassigned'],
        $stats['reverts_reassigned']
    ));

    if ($dryRun) {
        Logger::info('[DRY-RUN] No changes were written to the database.');
    }

    exit(0);
} catch (Throwable $e) {
    Logger::error('Fatal: ' . $e->getMessage());
    exit(1);
}
