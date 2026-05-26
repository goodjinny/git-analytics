<?php
declare(strict_types=1);

/**
 * Resolves the active project (name + repo path) from configuration and the
 * optional --project CLI argument.
 *
 * Required config format:
 *
 *   'git-projects' => [
 *       'awesome-project' => '/path/to/awesome-project',
 *       'some-mvp'        => '/path/to/mvp-project',
 *   ]
 *
 * Resolution order:
 *   1. If --project=<name> is given → use that entry from the map.
 *   2. If --project is omitted → use the first entry in the map.
 *   3. If 'git-projects' key is missing or empty → throw RuntimeException.
 */
final class ProjectResolver
{
    /**
     * Resolve the active project.
     *
     * @param  string|null $projectArg  Value of --project=<name> CLI argument (null if not given).
     * @return array{name: string, path: string}
     * @throws RuntimeException          When 'git-projects' is not configured.
     * @throws InvalidArgumentException  When --project=<name> is not found in the map.
     */
    public static function resolve(?string $projectArg): array
    {
        $projects = Config::get('git-projects', []);

        if (empty($projects) || !is_array($projects)) {
            throw new RuntimeException(
                "'git-projects' is not configured in config/config.php.\n" .
                "Add a 'git-projects' map with at least one entry:\n" .
                "  'git-projects' => [\n" .
                "      'my-project' => '/path/to/my-project',\n" .
                "  ]"
            );
        }

        if ($projectArg !== null && $projectArg !== '') {
            if (!array_key_exists($projectArg, $projects)) {
                $available = implode(', ', array_keys($projects));
                throw new InvalidArgumentException(
                    "--project={$projectArg} is not defined in config.php.\n" .
                    "Available projects: {$available}"
                );
            }
            return [
                'name' => $projectArg,
                'path' => (string) $projects[$projectArg],
            ];
        }

        // Default: first project in map.
        $firstName = (string) array_key_first($projects);
        return [
            'name' => $firstName,
            'path' => (string) $projects[$firstName],
        ];
    }

    /**
     * Return all configured project names (used for --help and validation messages).
     * Safe to call only after Config::load().
     *
     * @return string[]
     * @throws RuntimeException When 'git-projects' is not configured.
     */
    public static function allNames(): array
    {
        $projects = Config::get('git-projects', []);

        if (empty($projects) || !is_array($projects)) {
            throw new RuntimeException("'git-projects' is not configured in config/config.php.");
        }

        return array_keys($projects);
    }

    public static function sanitize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $value) ?? '';
        $value = trim($value, '-.');
        return $value !== '' ? $value : 'unknown-project';
    }
}
