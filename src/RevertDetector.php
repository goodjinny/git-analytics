<?php
declare(strict_types=1);

/**
 * Detects revert commits and resolves the affected developer.
 *
 * A revert commit is identified by subject matching:
 *   Revert "Merge branch '<branch>' into '<target>'"
 *
 * Attribution chain (first match wins):
 *   A) ticket_commit_author  — find commit on same branch that references the same ticket (confidence 90)
 *   B) message_match         — find commit on same branch whose subject/body matches part of reverted branch (confidence 60)
 *   C) unknown               — could not resolve (confidence 0, affected_developer_id = null)
 */
class RevertDetector
{
    public function __construct(
        private readonly TicketExtractor  $extractor,
        private readonly CommitRepository $commitRepo
    ) {}

    /**
     * Parse a commit DTO and return revert attribution data, or null if not a revert commit.
     *
     * @param array  $commitDto   Commit DTO as returned by CommitCollector or fetched from commits table.
     * @param string $targetBranch Branch context used for attribution lookup.
     * @return array|null Revert data array on success, null if not a revert commit.
     */
    public function parse(array $commitDto, string $targetBranch): ?array
    {
        $subject = (string) ($commitDto['subject'] ?? '');

        // Only process revert commits
        if (!str_starts_with($subject, 'Revert "')) {
            return null;
        }

        // Extract reverted branch and target branch from subject
        [$revertedBranch, $revertedTarget] = $this->parseRevertSubject($subject);

        // Extract ticket: first try branch name, then full subject/message
        $ticketCode = null;

        if ($revertedBranch !== null) {
            $tickets = $this->extractor->extractFromBranch($revertedBranch);
            if (!empty($tickets)) {
                $ticketCode = $tickets[0];
            }
        }

        if ($ticketCode === null) {
            $tickets = $this->extractor->extract($subject);
            if (!empty($tickets)) {
                $ticketCode = $tickets[0];
            }
        }

        // ----- Attribution chain -----

        $affectedDeveloperId = null;
        $detectedBy          = 'unknown';
        $matchedCommitId     = null;
        $confidenceScore     = 0.00;
        $detectionNotes      = null;

        // Variant A: lookup by ticket code in commit_tickets
        if ($ticketCode !== null) {
            $rows = $this->commitRepo->findByTicket($ticketCode, $targetBranch);
            if (!empty($rows)) {
                $affectedDeveloperId = (int) $rows[0]['developer_id'];
                $matchedCommitId     = (int) $rows[0]['matched_commit_id'];
                $detectedBy          = 'ticket_commit_author';
                $confidenceScore     = 90.00;
            }
        }

        // Variant B: message match using meaningful segment of reverted branch name
        if ($affectedDeveloperId === null && $revertedBranch !== null) {
            $pattern = $this->buildSearchPattern($revertedBranch);
            if ($pattern !== null) {
                $rows = $this->commitRepo->findByMessagePattern($pattern, $targetBranch);
                if (!empty($rows)) {
                    $affectedDeveloperId = (int) $rows[0]['developer_id'];
                    $matchedCommitId     = (int) $rows[0]['matched_commit_id'];
                    $detectedBy          = 'message_match';
                    $confidenceScore     = 60.00;
                }
            }
        }

        // Variant C: unknown
        if ($affectedDeveloperId === null) {
            $detectionNotes = sprintf(
                'Could not resolve affected developer. Reverted branch: %s, Ticket: %s',
                $revertedBranch ?? 'n/a',
                $ticketCode     ?? 'n/a'
            );
        }

        // Date fields from commit
        try {
            $dt = new DateTimeImmutable((string) ($commitDto['commit_datetime'] ?? 'now'));
        } catch (Throwable) {
            $dt = new DateTimeImmutable();
        }

        return [
            'reverted_branch_name'   => $revertedBranch,
            'reverted_target_branch' => $revertedTarget,
            'ticket_code'            => $ticketCode,
            'affected_developer_id'  => $affectedDeveloperId,
            'detected_by'            => $detectedBy,
            'matched_commit_id'      => $matchedCommitId,
            'confidence_score'       => $confidenceScore,
            'detection_notes'        => $detectionNotes,
            'revert_date'            => $dt->format('Y-m-d'),
            'revert_year'            => (int) $dt->format('Y'),
            'revert_month'           => (int) $dt->format('n'),
            'revert_year_month'      => $dt->format('Y-m'),
        ];
    }

    /**
     * Parse "Revert "Merge branch '<branch>' into '<target>'"" subject.
     *
     * @return array{0: string|null, 1: string|null}  [branchName, targetBranch]
     */
    private function parseRevertSubject(string $subject): array
    {
        // Standard GitLab format: Revert "Merge branch 'name' into 'target'"
        if (preg_match("/^Revert \"Merge branch '([^']+)' into '([^']+)'\"/", $subject, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        // Without quotes on target: Revert "Merge branch 'name' into target"
        if (preg_match("/^Revert \"Merge branch '([^']+)' into ([^\"]+)\"/", $subject, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        // Fallback: could not parse — log will be in detection_notes
        return [null, null];
    }

    /**
     * Build a LIKE search pattern from a branch name segment.
     * Returns null if no meaningful segment found.
     */
    private function buildSearchPattern(string $branchName): ?string
    {
        // Split branch name on common separators and pick the longest meaningful segment
        $segments = preg_split('/[\/\-_]/', $branchName, -1, PREG_SPLIT_NO_EMPTY);
        if ($segments === false || empty($segments)) {
            return null;
        }

        // Filter out very short or common prefixes (feature, fix, hotfix, etc.)
        $ignored  = ['feature', 'feat', 'fix', 'hotfix', 'bugfix', 'release', 'refactor', 'chore'];
        $filtered = array_filter($segments, static fn($s) => strlen($s) >= 4 && !in_array(strtolower($s), $ignored, true));

        if (empty($filtered)) {
            return null;
        }

        // Use the longest segment as the search anchor
        usort($filtered, static fn($a, $b) => strlen($b) <=> strlen($a));
        return '%' . reset($filtered) . '%';
    }
}

