<?php
declare(strict_types=1);

/**
 * Orchestrates the full ETL pipeline for git analytics import.
 *
 * Execution order (must match FK dependencies in schema.sql):
 *   1.  import_runs    — create running record
 *   2.  developers     — upsert per commit author
 *   3.  commits        — upsert all commits
 *   4.  tickets        — upsert tickets found in commit messages
 *   5.  commit_tickets — link commits ↔ tickets
 *   6.  reverts        — detect revert commits, resolve affected developer
 *   7.  import_runs    — mark success/failed
 */
class ImportPipeline
{
    public function __construct(
        private readonly CommitCollector        $commitCollector,
        private readonly TicketExtractor        $ticketExtractor,
        private readonly RevertDetector         $revertDetector,
        private readonly ImportRunRepository    $importRunRepo,
        private readonly DeveloperRepository    $developerRepo,
        private readonly CommitRepository       $commitRepo,
        private readonly TicketRepository       $ticketRepo,
        private readonly CommitTicketRepository $commitTicketRepo,
        private readonly RevertRepository       $revertRepo
    ) {}

    /**
     * Run the full import.
     *
     * $params keys:
     *   project_name string  — key from git-projects config map
     *   branch       string
     *   date_from    string  YYYY-MM-DD
     *   date_to      string  YYYY-MM-DD
     *   repo_path    string
     *   dry_run      bool    optional — if true, no DB writes
     */
    public function run(array $params): void
    {
        $projectName = (string) ($params['project_name'] ?? '');
        $branch      = $params['branch'];
        $dateFrom    = $params['date_from'];
        $dateTo      = $params['date_to'];
        $repoPath    = $params['repo_path'];
        $dryRun      = (bool) ($params['dry_run'] ?? false);

        $outputPath   = (string) Config::get('output.path');
        $importRunId  = 0;
        $commitsCount = 0;
        $revertsCount = 0;

        // Step 1 — Create import_runs record
        if (!$dryRun) {
            $importRunId = $this->importRunRepo->create($projectName, $repoPath, $branch, $dateFrom, $dateTo);
            Logger::info("import_run_id: {$importRunId}");
        }

        try {
            // Step 2 — Collect commits from git
            Logger::info('Collecting commits from git...');
            $commits = $this->commitCollector->collect($branch, $dateFrom, $dateTo);
            Logger::info('Commits collected: ' . count($commits));

            if (empty($commits)) {
                Logger::warning('No commits found for the given branch and period.');
                if (!$dryRun) {
                    $this->importRunRepo->markSuccess($importRunId, 0, 0);
                }
                return;
            }

            // Save raw commits snapshot before DB writes
            $this->saveSnapshot($outputPath . '/commits', $branch, $commits, $dryRun);

            // Step 3 — Process each commit: developers → commits → tickets → commit_tickets
            Logger::info('Processing commits...');

            /** @var array<string, int> hash → db commit id */
            $commitIdMap = [];

            foreach ($commits as $commitDto) {
                // 3.1  Developer upsert
                $devId = 0;
                if (!$dryRun) {
                    $devId = $this->developerRepo->upsert(
                        $commitDto['author_name'],
                        $commitDto['author_email']
                    );
                }

                // 3.2  Commit upsert
                if (!$dryRun) {
                    $commitId = $this->commitRepo->upsert(array_merge($commitDto, [
                        'import_run_id' => $importRunId,
                        'developer_id'  => $devId,
                        'branch_name'   => $branch,
                    ]));
                    $commitIdMap[$commitDto['commit_hash']] = $commitId;
                    $commitsCount++;
                }

                // 3.3  Extract and store tickets from subject and body
                if (!$dryRun && isset($commitId) && $commitId > 0) {
                    $subjectTickets = $this->ticketExtractor->extract($commitDto['subject']);
                    foreach ($subjectTickets as $code) {
                        $ticketId = $this->ticketRepo->upsert($code);
                        $this->commitTicketRepo->upsert($commitId, $ticketId, 'subject');
                    }

                    if ($commitDto['body'] !== null) {
                        // body may repeat subject tickets — same match_source won't create duplicate (UNIQUE KEY)
                        $bodyTickets = $this->ticketExtractor->extract($commitDto['body']);
                        foreach ($bodyTickets as $code) {
                            $ticketId = $this->ticketRepo->upsert($code);
                            $this->commitTicketRepo->upsert($commitId, $ticketId, 'body');
                        }
                    }
                }
            }

            if (!$dryRun) {
                Logger::info("Commits inserted/updated: {$commitsCount}");
            }

            // Step 4 — Fetch revert commits (from DB in normal mode, from memory in dry-run)
            Logger::info('Detecting reverts...');

            $revertCommits = $dryRun
                ? array_values(array_filter($commits, static fn($c) => $c['is_revert_commit']))
                : $this->commitRepo->findRevertCommits($importRunId);

            Logger::info('Revert commits found: ' . count($revertCommits));

            // Step 5 — Process reverts: detect attribution, save to DB
            $revertSnapshots = [];

            foreach ($revertCommits as $revertRow) {
                // Normalise: DB rows use same keys as DTOs
                $dto = $revertRow;
                if (!isset($dto['is_revert_commit'])) {
                    $dto['is_revert_commit'] = true;
                }

                $revertData = $this->revertDetector->parse($dto, $branch);
                if ($revertData === null) {
                    continue;
                }

                if ($revertData['affected_developer_id'] === null) {
                    Logger::warning(sprintf(
                        'Affected developer not resolved for: %s (%s)',
                        substr((string) ($dto['commit_hash'] ?? ''), 0, 12),
                        $dto['subject'] ?? ''
                    ));
                }

                if (!$dryRun) {
                    // Upsert the ticket for the revert message
                    $ticketId = null;
                    if ($revertData['ticket_code'] !== null) {
                        $ticketId = $this->ticketRepo->upsert($revertData['ticket_code']);

                        // Link ticket to revert commit with match_source='revert_message'
                        $dbCommitId = $this->resolveCommitId($dto, $branch, $commitIdMap);
                        if ($dbCommitId > 0 && $ticketId > 0) {
                            $this->commitTicketRepo->upsert($dbCommitId, $ticketId, 'revert_message');
                        }
                    }

                    $dbCommitId = $this->resolveCommitId($dto, $branch, $commitIdMap);

                    if ($dbCommitId > 0) {
                        $this->revertRepo->upsert(array_merge($revertData, [
                            'import_run_id'    => $importRunId,
                            'revert_commit_id' => $dbCommitId,
                            'ticket_id'        => $ticketId,
                        ]));
                        $revertsCount++;
                    }
                }

                $revertSnapshots[] = array_merge($revertData, [
                    'commit_hash' => $dto['commit_hash'] ?? '',
                    'subject'     => $dto['subject']    ?? '',
                ]);
            }

            // Save raw revert snapshot
            if (!empty($revertSnapshots)) {
                $this->saveSnapshot($outputPath . '/reverts', $branch, $revertSnapshots, $dryRun);
            }

            // Step 6 — Finalise import_runs
            if (!$dryRun) {
                Logger::info("Reverts saved: {$revertsCount}");
                Logger::info('Raw snapshots saved to output/');
                $this->importRunRepo->markSuccess($importRunId, $commitsCount, $revertsCount);
                Logger::info('Import finished. Status: success');
            } else {
                Logger::info(sprintf(
                    '[DRY-RUN] Would import: %d commits, %d reverts. No data written.',
                    count($commits),
                    count($revertSnapshots)
                ));
            }

        } catch (Throwable $e) {
            Logger::error('Pipeline error: ' . $e->getMessage());
            if (!$dryRun && $importRunId > 0) {
                $trace = $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
                $this->importRunRepo->markFailed(
                    $importRunId,
                    $e->getMessage() . "\n" . $trace
                );
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve DB commit_id from in-memory map or fall back to a SELECT.
     */
    private function resolveCommitId(array $dto, string $branch, array $commitIdMap): int
    {
        $hash = (string) ($dto['commit_hash'] ?? '');

        if (isset($commitIdMap[$hash])) {
            return $commitIdMap[$hash];
        }

        $row = Db::fetchOne(
            'SELECT id FROM commits WHERE commit_hash = :h AND branch_name = :b',
            [':h' => $hash, ':b' => $branch]
        );

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Persist a raw data snapshot as JSON to the given directory.
     * Skipped in dry-run mode.
     */
    private function saveSnapshot(string $dir, string $branch, array $data, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            Logger::warning("Could not create snapshot directory: {$dir}");
            return;
        }

        $safeBranch = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $branch);
        $filename   = $dir . '/' . date('Ymd_His') . '_' . $safeBranch . '.json';

        file_put_contents(
            $filename,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}

