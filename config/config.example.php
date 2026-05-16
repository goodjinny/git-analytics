<?php

/**
 * Git analytics configuration.
 *
 * DB credentials and paths for the standalone git_analytics database.
 * This file is outside the host project / parent framework — do not use Config::get() from libraries/.
 */
return [
    'db' => [
        // SQLite file path — created automatically on first import run.
        // Paths are resolved relative to the project root (dirname(__DIR__)).
        'path' => dirname(__DIR__) . '/data/git_analytics.db',
    ],
    'git' => [
        'repo_path' => '/path/to/repo',
    ],
    'output' => [
        'path' => dirname(__DIR__) . '/output',
    ],
];

