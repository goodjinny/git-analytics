<?php
declare(strict_types=1);

/**
 * Manages reverts table.
 * UNIQUE KEY = (revert_commit_id) — one revert record per revert commit.
 * ON DUPLICATE KEY updates attribution fields so re-imports can improve results.
 */
class RevertRepository
{
    /**
     * Insert or update a revert record.
     * $data must include all required fields (see INSERT below).
     * Returns revert id.
     */
    public function upsert(array $data): int
    {
        Db::execute(
            'INSERT INTO reverts (
                import_run_id, revert_commit_id, reverted_branch_name,
                reverted_target_branch, ticket_id, affected_developer_id,
                detected_by, matched_commit_id, confidence_score,
                detection_notes, revert_date, revert_year,
                revert_month, revert_year_month
            ) VALUES (
                :run_id, :commit_id, :branch,
                :target, :ticket, :dev,
                :by, :matched, :score,
                :notes, :date, :year,
                :month, :year_month
            ) ON CONFLICT(revert_commit_id) DO UPDATE SET
                ticket_id             = excluded.ticket_id,
                affected_developer_id = excluded.affected_developer_id,
                detected_by           = excluded.detected_by,
                matched_commit_id     = excluded.matched_commit_id,
                confidence_score      = excluded.confidence_score,
                detection_notes       = excluded.detection_notes',
            [
                ':run_id'     => $data['import_run_id'],
                ':commit_id'  => $data['revert_commit_id'],
                ':branch'     => $data['reverted_branch_name'],
                ':target'     => $data['reverted_target_branch'],
                ':ticket'     => $data['ticket_id'],
                ':dev'        => $data['affected_developer_id'],
                ':by'         => $data['detected_by'],
                ':matched'    => $data['matched_commit_id'],
                ':score'      => $data['confidence_score'],
                ':notes'      => $data['detection_notes'],
                ':date'       => $data['revert_date'],
                ':year'       => $data['revert_year'],
                ':month'      => $data['revert_month'],
                ':year_month' => $data['revert_year_month'],
            ]
        );

        $id = Db::lastInsertId();

        if ($id === 0) {
            $row = Db::fetchOne(
                'SELECT id FROM reverts WHERE revert_commit_id = :cid',
                [':cid' => $data['revert_commit_id']]
            );
            $id = $row ? (int) $row['id'] : 0;
        }

        return $id;
    }
}

