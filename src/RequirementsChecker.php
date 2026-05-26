<?php
declare(strict_types=1);

/**
 * Checks system requirements for running the git analytics import.
 *
 * Each check returns an entry: ['label' => string, 'ok' => bool, 'detail' => string]
 */
class RequirementsChecker
{
    /** Minimum PHP version required. */
    private const MIN_PHP = '8.2.0';

    /** PDO drivers required for SQLite. */
    private const REQUIRED_EXTENSIONS = ['pdo', 'pdo_sqlite', 'json', 'mbstring'];

    /**
     * Run all checks and return list of results.
     *
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    public function check(): array
    {
        $results = [];

        $results[] = $this->checkPhpVersion();

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $results[] = $this->checkExtension($ext);
        }

        $results[] = $this->checkGitBinary();
        $results[] = $this->checkGitVersion();
        $results[] = $this->checkConfigFile();
        $results[] = $this->checkDbDirectory();
        $results[] = $this->checkOutputDirectory();
        $results[] = $this->checkRepoPath();

        return $results;
    }

    /**
     * Returns true only if all checks passed.
     */
    public function allPassed(array $results): bool
    {
        foreach ($results as $r) {
            if (!$r['ok']) {
                return false;
            }
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Individual checks
    // -------------------------------------------------------------------------

    private function checkPhpVersion(): array
    {
        $ok = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        return [
            'label'  => 'PHP version >= ' . self::MIN_PHP,
            'ok'     => $ok,
            'detail' => 'Current: ' . PHP_VERSION,
        ];
    }

    private function checkExtension(string $ext): array
    {
        $ok = extension_loaded($ext);
        return [
            'label'  => "PHP extension: {$ext}",
            'ok'     => $ok,
            'detail' => $ok ? 'loaded' : "NOT loaded — install php-{$ext} or enable in php.ini",
        ];
    }

    private function checkGitBinary(): array
    {
        $output   = [];
        $exitCode = 0;
        exec('which git 2>/dev/null', $output, $exitCode);
        $ok   = $exitCode === 0 && !empty($output);
        $path = $ok ? trim($output[0]) : '';
        return [
            'label'  => 'git binary in PATH',
            'ok'     => $ok,
            'detail' => $ok ? $path : 'git not found — install git',
        ];
    }

    private function checkGitVersion(): array
    {
        $output   = [];
        $exitCode = 0;
        exec('git --version 2>/dev/null', $output, $exitCode);
        $ok      = $exitCode === 0 && !empty($output);
        $version = $ok ? trim($output[0]) : 'n/a';
        return [
            'label'  => 'git version',
            'ok'     => $ok,
            'detail' => $version,
        ];
    }

    private function checkConfigFile(): array
    {
        $path = dirname(__DIR__) . '/config/config.php';
        $ok   = file_exists($path) && is_readable($path);
        return [
            'label'  => 'config/config.php exists and readable',
            'ok'     => $ok,
            'detail' => $ok ? $path : "Not found: {$path}",
        ];
    }

    private function checkDbDirectory(): array
    {
        $dbPath = (string) Config::get('db.path', '');
        if ($dbPath === '') {
            return [
                'label'  => 'DB directory writable',
                'ok'     => false,
                'detail' => 'db.path not set in config/config.php',
            ];
        }

        $dir = dirname($dbPath);
        $ok  = is_dir($dir) ? is_writable($dir) : @mkdir($dir, 0755, true);
        return [
            'label'  => 'DB directory writable',
            'ok'     => (bool) $ok,
            'detail' => $ok ? $dir : "Not writable or cannot create: {$dir}",
        ];
    }

    private function checkOutputDirectory(): array
    {
        $outPath = (string) Config::get('output.path', '');
        if ($outPath === '') {
            return [
                'label'  => 'Output directory writable',
                'ok'     => false,
                'detail' => 'output.path not set in config/config.php',
            ];
        }

        $ok = is_dir($outPath) ? is_writable($outPath) : @mkdir($outPath, 0755, true);
        return [
            'label'  => 'Output directory writable',
            'ok'     => (bool) $ok,
            'detail' => $ok ? $outPath : "Not writable or cannot create: {$outPath}",
        ];
    }

    private function checkRepoPath(): array
    {
        $projects = Config::get('git-projects', []);
        if (empty($projects) || !is_array($projects)) {
            return [
                'label'  => 'Git repository path configured',
                'ok'     => false,
                'detail' => 'git-projects paths not set in config/config.php',
            ];
        }

        // Validate every project path in the map.
        foreach ($projects as $name => $repoPath) {
            $repoPath = (string) $repoPath;
            if (!is_dir($repoPath)) {
                return [
                    'label'  => 'Git repository path configured',
                    'ok'     => false,
                    'detail' => "Project '{$name}': directory does not exist: {$repoPath}",
                ];
            }
            if (!is_dir($repoPath . '/.git')) {
                return [
                    'label'  => 'Git repository path configured',
                    'ok'     => false,
                    'detail' => "Project '{$name}': no .git directory found in: {$repoPath}",
                ];
            }
        }

        $summary = implode(', ', array_keys($projects));
        return [
            'label'  => 'Git repository path configured',
            'ok'     => true,
            'detail' => "Projects: {$summary}",
        ];
    }
}

