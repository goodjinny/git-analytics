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
 * Usage:
 *   php bin/apply-aliases.php
 *   php bin/apply-aliases.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.' . PHP_EOL);
}

$baseDir = dirname(__DIR__);

foreach (['Config', 'Db', 'Logger', 'AliasApplier'] as $class) {
    require_once $baseDir . '/src/' . $class . '.php';
}

Config::load($baseDir . '/config/config.php');
$outputPath = (string) Config::get('output.path', $baseDir . '/output');
Logger::init($outputPath);

$opts   = getopt('', ['dry-run', 'help']);
$dryRun = isset($opts['dry-run']);

if (isset($opts['help'])) {
    echo <<<HELP
apply-aliases — apply developer alias_id migration

Usage:
  php bin/apply-aliases.php [--dry-run]

Options:
  --dry-run   Show what would be changed without writing to DB
  --help      Show this help

Alias pairs are matched by (author_name, author_email) — independent of
primary-key ids. Safe to re-run after import.php --fresh.

Note: bin/report.php applies the same aliases automatically by default
(use --skip-aliases there to opt out). Running this script separately is
optional and useful mainly for --dry-run inspection.

HELP;
    exit(0);
}

Logger::info('apply-aliases started' . ($dryRun ? ' [DRY-RUN]' : ''));

try {
    $applier = new AliasApplier($baseDir);
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
