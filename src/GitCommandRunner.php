<?php
declare(strict_types=1);

/**
 * Executes git commands inside a given repository directory.
 *
 * Uses proc_open (not shell_exec) so we get exit code and both stdout/stderr.
 * The caller passes only the git sub-command + args (without the leading "git").
 *
 * Example:
 *   $runner->run('log develop --oneline');
 */
class GitCommandRunner
{
    public function __construct(private readonly string $repoPath) {}

    /**
     * Run git command and return stdout. Throws RuntimeException on non-zero exit.
     */
    public function run(string $command): string
    {
        $exitCode = 0;
        $stderr   = '';
        $result = $this->exec($command, $exitCode, $stderr);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "git {$command} exited with code {$exitCode}. stderr: " . trim($stderr)
            );
        }

        return (string) $result;
    }

    /**
     * Run git command and return stdout, or null on error (no exception).
     */
    public function runSafe(string $command): ?string
    {
        $exitCode = 0;
        $stderr   = '';
        $result = $this->exec($command, $exitCode, $stderr);

        if ($exitCode !== 0) {
            Logger::warning("git {$command} exited with code {$exitCode}: " . trim($stderr));
            return null;
        }

        return (string) $result;
    }

    private function exec(string $command, int &$exitCode, string &$stderr): ?string
    {
        $fullCommand = 'git ' . $command;

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($fullCommand, $descriptors, $pipes, $this->repoPath);

        if (!is_resource($process)) {
            Logger::error("proc_open failed for: {$fullCommand}");
            $exitCode = -1;
            $stderr   = '';
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return $stdout !== false ? $stdout : '';
    }
}

