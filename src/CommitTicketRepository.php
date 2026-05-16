<?php
declare(strict_types=1);

/**
 * Manages commit_tickets — M:N link between commits and tickets.
 *
 * UNIQUE KEY is on (commit_id, ticket_id, match_source) so the same ticket
 * can appear in multiple sources (subject, body, revert_message) of the same commit.
 */
class CommitTicketRepository
{
    public function upsert(int $commitId, int $ticketId, string $matchSource): void
    {
        Db::execute(
            'INSERT OR IGNORE INTO commit_tickets (commit_id, ticket_id, match_source)
             VALUES (:commit_id, :ticket_id, :source)',
            [
                ':commit_id' => $commitId,
                ':ticket_id' => $ticketId,
                ':source'    => $matchSource,
            ]
        );
    }
}

