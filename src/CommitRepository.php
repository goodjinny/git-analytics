<?php
declare(strict_types=1);

/**
 * Manages commits table — upsert on (commit_hash, branch_name) unique key.
 *
 * ON DUPLICATE KEY updates only the numeric stats and import_run_id so that
 * reimporting the same period keeps the latest data while preserving identity fields.
 */
class CommitRepository
{
    /**
     * Insert or update a commit.
     * $commitData must contain all fields expected by the INSERT.
     * Returns commit_id (db row id).
     */
    public function upsert(array $commitData): int
    {
        Db::execute(
            'INSERT INTO commits (
                import_run_id, developer_id, commit_hash, commit_hash_short,
                branch_name, commit_datetime, commit_date, commit_year,
                commit_month, commit_year_month, subject, body, message_full,
                files_changed, lines_added, lines_deleted, lines_changed_total,
                is_merge_commit, is_revert_commit, technical_commit, parent_hashes
            ) VALUES (
                :run_id, :dev_id, :hash, :short,
                :branch, :datetime, :date, :year,
                :month, :year_month, :subject, :body, :message,
                :files, :added, :deleted, :total,
                :merge, :revert, :technical, :parents
            ) ON CONFLICT(commit_hash, branch_name) DO UPDATE SET
                import_run_id       = excluded.import_run_id,
                lines_added         = excluded.lines_added,
                lines_deleted       = excluded.lines_deleted,
                lines_changed_total = excluded.lines_changed_total,
                files_changed       = excluded.files_changed',
            [
                ':run_id'     => $commitData['import_run_id'],
                ':dev_id'     => $commitData['developer_id'],
                ':hash'       => $commitData['commit_hash'],
                ':short'      => $commitData['commit_hash_short'],
                ':branch'     => $commitData['branch_name'],
                ':datetime'   => $commitData['commit_datetime'],
                ':date'       => $commitData['commit_date'],
                ':year'       => $commitData['commit_year'],
                ':month'      => $commitData['commit_month'],
                ':year_month' => $commitData['commit_year_month'],
                ':subject'    => mb_substr((string) $commitData['subject'], 0, 1000),
                ':body'       => $commitData['body'],
                ':message'    => $commitData['message_full'],
                ':files'      => $commitData['files_changed'],
                ':added'      => $commitData['lines_added'],
                ':deleted'    => $commitData['lines_deleted'],
                ':total'      => $commitData['lines_changed_total'],
                ':merge'      => $commitData['is_merge_commit'] ? 1 : 0,
                ':revert'     => $commitData['is_revert_commit'] ? 1 : 0,
                ':technical'  => $commitData['technical_commit'] ? 1 : 0,
                ':parents'    => $commitData['parent_hashes'],
            ]
        );

        $id = Db::lastInsertId();

        if ($id === 0) {
            // Was an UPDATE (duplicate) — fetch existing id
            $row = Db::fetchOne(
                'SELECT id FROM commits WHERE commit_hash = :h AND branch_name = :b',
                [':h' => $commitData['commit_hash'], ':b' => $commitData['branch_name']]
            );
            $id = $row ? (int) $row['id'] : 0;
        }

        return $id;
    }

    public function findByHash(string $hash, string $branch): ?array
    {
        return Db::fetchOne(
            'SELECT * FROM commits WHERE commit_hash = :hash AND branch_name = :branch',
            [':hash' => $hash, ':branch' => $branch]
        );
    }

    /**
     * Return all revert commits created in a specific import run.
     */
    public function findRevertCommits(int $importRunId): array
    {
        return Db::fetchAll(
            'SELECT * FROM commits WHERE import_run_id = :run_id AND is_revert_commit = 1',
            [':run_id' => $importRunId]
        );
    }

    /**
     * Variant A attribution: find earliest non-revert commit on $branch that references $ticketCode.
     * Returns rows with developer_id and matched_commit_id (= id).
     */
    public function findByTicket(string $ticketCode, string $branch): array
    {
        return Db::fetchAll(
            'SELECT c.developer_id, c.id AS matched_commit_id
             FROM commits c
             INNER JOIN commit_tickets ct ON ct.commit_id  = c.id
             INNER JOIN tickets        t  ON t.id          = ct.ticket_id
             WHERE t.ticket_code    = :code
               AND c.is_revert_commit = 0
               AND c.branch_name    = :branch
             ORDER BY c.commit_datetime ASC
             LIMIT 1',
            [':code' => strtoupper($ticketCode), ':branch' => $branch]
        );
    }

    /**
     * Variant B attribution: find earliest non-revert commit whose subject/body matches $pattern (LIKE).
     */
    public function findByMessagePattern(string $pattern, string $branch): array
    {
        return Db::fetchAll(
            'SELECT developer_id, id AS matched_commit_id
             FROM commits
             WHERE is_revert_commit = 0
               AND branch_name      = :branch
               AND (subject LIKE :pattern OR body LIKE :pattern)
             ORDER BY commit_datetime ASC
             LIMIT 1',
            [':branch' => $branch, ':pattern' => $pattern]
        );
    }
}

