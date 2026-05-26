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

    // -------------------------------------------------------------------------
    // Project map (required).
    // Key   = project name used in --project=<name> and as report subfolder.
    // Value = absolute path to the git repository.
    // Default (when --project is omitted): the FIRST entry in this map.
    // -------------------------------------------------------------------------
    'git-projects' => [
        'my-project' => '/path/to/my-project',
        // 'another-project' => '/path/to/another-project',
    ],

    'output' => [
        'path' => dirname(__DIR__) . '/output',
    ],
];
