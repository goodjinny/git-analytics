<?php
declare(strict_types=1);

/** Manages the import_runs table lifecycle. */
class ImportRunRepository
{
    /**
     * Create a new import run record with status 'running'.
     * Returns the new id.
     */
    public function create(
        string $sourceRepo,
        string $branch,
        string $dateFrom,
        string $dateTo
    ): int {
        Db::execute(
            "INSERT INTO import_runs
                (source_repo, target_branch, report_date_from, report_date_to, started_at, status, commits_found, reverts_found)
             VALUES
                (:repo, :branch, :from, :to, CURRENT_TIMESTAMP, 'running', 0, 0)",
            [
                ':repo'   => $sourceRepo,
                ':branch' => $branch,
                ':from'   => $dateFrom,
                ':to'     => $dateTo,
            ]
        );

        return Db::lastInsertId();
    }

    public function markSuccess(int $id, int $commitsFound, int $revertsFound): void
    {
        Db::execute(
            "UPDATE import_runs
             SET status = 'success', finished_at = CURRENT_TIMESTAMP, commits_found = :c, reverts_found = :r
             WHERE id = :id",
            [':c' => $commitsFound, ':r' => $revertsFound, ':id' => $id]
        );
    }

    public function markFailed(int $id, string $errorMessage): void
    {
        Db::execute(
            "UPDATE import_runs
             SET status = 'failed', finished_at = CURRENT_TIMESTAMP, error_message = :msg
             WHERE id = :id",
            [':msg' => mb_substr($errorMessage, 0, 65535), ':id' => $id]
        );
    }
}

