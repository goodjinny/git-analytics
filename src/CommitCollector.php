<?php
declare(strict_types=1);

/**
 * Collects and parses commits from git log for a given branch and date range.
 *
 * Uses TWO git-log passes to avoid multiline-body parsing ambiguity:
 *   Pass 1 — metadata (hash, author, date, subject, body, parents)
 *   Pass 2 — numstat (files changed, lines added/deleted per commit)
 *
 * Both passes use the same branch/period filter and are merged by full commit hash.
 *
 * Field separator in git format: %x1f (ASCII 0x1F — Unit Separator)
 * Record terminator:              %x1e (ASCII 0x1E — Record Separator)
 */
class CommitCollector
{
    /** ASCII field separator — used in git format string */
    private const FS = "\x1f";
    /** ASCII record separator — used as end-of-record marker */
    private const RS = "\x1e";

    public function __construct(private readonly GitCommandRunner $runner) {}

    /**
     * Collect commits for a branch between dateFrom and dateTo (inclusive).
     *
     * @return array<int, array> List of commit DTOs
     */
    public function collect(string $branch, string $dateFrom, string $dateTo): array
    {
        $metadata = $this->collectMetadata($branch, $dateFrom, $dateTo);
        $numstats = $this->collectNumstat($branch, $dateFrom, $dateTo);

        $commits = [];
        foreach ($metadata as $hash => $meta) {
            $s = $numstats[$hash] ?? ['files_changed' => 0, 'lines_added' => 0, 'lines_deleted' => 0];
            $commits[] = array_merge($meta, [
                'files_changed'       => $s['files_changed'],
                'lines_added'         => $s['lines_added'],
                'lines_deleted'       => $s['lines_deleted'],
                'lines_changed_total' => $s['lines_added'] + $s['lines_deleted'],
            ]);
        }

        return $commits;
    }

    // -------------------------------------------------------------------------
    // Pass 1: Metadata
    // -------------------------------------------------------------------------

    private function collectMetadata(string $branch, string $dateFrom, string $dateTo): array
    {
        // Each record: fields separated by \x1f, terminated by \x1e.
        // %b (body) may be multiline — it will be the last field before \x1e.
        $format  = '%H%x1f%h%x1f%an%x1f%ae%x1f%aI%x1f%P%x1f%s%x1f%b%x1e';

        $command = 'log '
            . escapeshellarg($branch)
            . ' --since=' . escapeshellarg($dateFrom . ' 00:00:00')
            . ' --until=' . escapeshellarg($dateTo   . ' 23:59:59')
            . ' --format=' . escapeshellarg($format);

        $output = $this->runner->run($command);

        return $this->parseMetadata($output);
    }

    private function parseMetadata(string $output): array
    {
        $results = [];

        // Split on record separator; trailing output after last \x1e may be empty — skip it.
        $records = explode(self::RS, $output);

        foreach ($records as $record) {
            // Strip leading/trailing newlines left by git's tformat behaviour
            $record = trim($record, "\n");
            if ($record === '') {
                continue;
            }

            $parts = explode(self::FS, $record);

            // Need at least 8 fields (body may be empty but field must exist)
            if (count($parts) < 8) {
                Logger::warning('CommitCollector: skipped malformed record (fields: ' . count($parts) . ')');
                continue;
            }

            $hash    = trim($parts[0]);
            $short   = trim($parts[1]);
            $name    = trim($parts[2]);
            $email   = trim($parts[3]);
            $dateRaw = trim($parts[4]);
            $parents = trim($parts[5]);
            $subject = trim($parts[6]);
            // body is everything from field 7 onward (in case body itself contained \x1f — extremely unlikely)
            $body    = trim(implode(self::FS, array_slice($parts, 7)));

            // Validate hash
            if (strlen($hash) !== 40 || !ctype_xdigit($hash)) {
                Logger::warning("CommitCollector: invalid hash '{$hash}', skipping record.");
                continue;
            }

            // Parse ISO 8601 date from git (%aI)
            try {
                $dt = new DateTimeImmutable($dateRaw);
            } catch (Throwable) {
                Logger::warning("CommitCollector: cannot parse date '{$dateRaw}' for {$hash}");
                continue;
            }

            $parentList = array_values(array_filter(explode(' ', $parents)));

            $results[$hash] = [
                'commit_hash'       => $hash,
                'commit_hash_short' => $short,
                'author_name'       => $name !== '' ? $name : 'unknown',
                'author_email'      => $email !== '' ? $email : null,
                'commit_datetime'   => $dt->format('Y-m-d H:i:s'),
                'commit_date'       => $dt->format('Y-m-d'),
                'commit_year'       => (int) $dt->format('Y'),
                'commit_month'      => (int) $dt->format('n'),
                'commit_year_month' => $dt->format('Y-m'),
                'subject'           => $subject,
                'body'              => $body !== '' ? $body : null,
                'message_full'      => $body !== '' ? $subject . "\n\n" . $body : $subject,
                'parent_hashes'     => $parents !== '' ? $parents : null,
                'is_merge_commit'    => count($parentList) > 1,
                'is_revert_commit'  => str_starts_with($subject, 'Revert "'),
                'technical_commit'  => str_starts_with($subject, 'Merge branch ') || str_starts_with($subject, 'Revert '),
            ];
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Pass 2: Numstat
    // -------------------------------------------------------------------------

    private function collectNumstat(string $branch, string $dateFrom, string $dateTo): array
    {
        // Use a unique marker line per commit so we can split the numstat output reliably.
        // git --format outputs the marker line, then a blank line, then numstat lines for that commit.
        $marker  = '>>HASH:%H<<';
        $command = 'log '
            . escapeshellarg($branch)
            . ' --since=' . escapeshellarg($dateFrom . ' 00:00:00')
            . ' --until=' . escapeshellarg($dateTo   . ' 23:59:59')
            . ' --format=' . escapeshellarg($marker)
            . ' --numstat';

        $output = $this->runner->run($command);

        return $this->parseNumstat($output);
    }

    private function parseNumstat(string $output): array
    {
        $results     = [];
        $currentHash = null;
        $files       = 0;
        $added       = 0;
        $deleted     = 0;

        foreach (explode("\n", $output) as $line) {
            // Detect commit marker: >>HASH:<40-hex><<
            if (preg_match('/^>>HASH:([0-9a-f]{40})<<$/', $line, $m)) {
                // Save stats for the PRECEDING commit before resetting
                if ($currentHash !== null) {
                    $results[$currentHash] = [
                        'files_changed' => $files,
                        'lines_added'   => $added,
                        'lines_deleted' => $deleted,
                    ];
                }
                $currentHash = $m[1];
                $files       = 0;
                $added       = 0;
                $deleted     = 0;
                continue;
            }

            if ($currentHash === null) {
                continue;
            }

            // Parse numstat line: <added>\t<deleted>\t<filename>
            // Binary files use '-' instead of a number — skip those lines for line counts.
            if (preg_match('/^(\S+)\t(\S+)\t/', $line, $m)) {
                $files++;
                if ($m[1] !== '-' && $m[2] !== '-') {
                    $added   += (int) $m[1];
                    $deleted += (int) $m[2];
                }
            }
        }

        // Save the last commit's stats after the loop ends
        if ($currentHash !== null) {
            $results[$currentHash] = [
                'files_changed' => $files,
                'lines_added'   => $added,
                'lines_deleted' => $deleted,
            ];
        }

        return $results;
    }
}

