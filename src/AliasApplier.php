<?php
declare(strict_types=1);

/**
 * Applies developer alias mapping to the SQLite DB.
 *
 * Idempotent and safe to re-run after `import.php --fresh`:
 *   - alias pairs are matched by (author_name, author_email), not by primary-key id
 *   - all existing aliases are reset before reapplying
 *   - missing alias/canonical rows are silently skipped with a warning
 *
 * Used by both:
 *   - bin/apply-aliases.php  (CLI entrypoint)
 *   - bin/report.php         (automatic step before report generation, unless --skip-aliases)
 *
 * Order of operations (all idempotent):
 *   0. Add commits.technical_commit column if missing
 *   1. Add developers.alias_id column if missing
 *   2. Reset existing aliases and reassign by (name, email) pairs
 *   3. Reassign commits.developer_id to canonical
 *   4. Reassign reverts.affected_developer_id to canonical
 *   5. Recreate analytics views from schema.sqlite.sql
 */
final class AliasApplier
{
    /** Primary config (gitignored — may contain real names). */
    private const CONFIG_FILE = 'config/aliases.json';

    /** Public template — fallback so the tool runs out-of-the-box on a fresh clone. */
    private const CONFIG_EXAMPLE_FILE = 'config/aliases.example.json';

    /**
     * @var array<int, array{
     *     alias_name:string, alias_email:string,
     *     canon_name:string, canon_email:string,
     *     note?:string
     * }>
     */
    private array $pairs = [];

    /**
     * Domain equivalence groups: emails with the same local-part across any
     * domains in one group are treated as the same person. FIRST domain in
     * each group is the preferred canonical domain.
     *
     * @var array<int, array<int, string>>
     */
    private array $equivalentDomains = [];

    /** Absolute path to the alias config file actually loaded (for diagnostics). */
    private string $loadedConfigPath = '';

    public function __construct(private readonly string $baseDir)
    {
        $this->loadConfig();
    }

    /**
     * Read pairs + equivalent_domains from config/aliases.json
     * (or config/aliases.example.json as fallback). Validates structure.
     */
    private function loadConfig(): void
    {
        $primary  = $this->baseDir . '/' . self::CONFIG_FILE;
        $fallback = $this->baseDir . '/' . self::CONFIG_EXAMPLE_FILE;

        if (is_file($primary)) {
            $path = $primary;
        } elseif (is_file($fallback)) {
            $path = $fallback;
            Logger::warning(sprintf(
                'Using example alias config: %s. Copy it to %s and customise for real data.',
                self::CONFIG_EXAMPLE_FILE,
                self::CONFIG_FILE
            ));
        } else {
            throw new RuntimeException(sprintf(
                'Alias config not found. Expected one of:%s  - %s%s  - %s',
                PHP_EOL, $primary, PHP_EOL, $fallback
            ));
        }

        $this->loadedConfigPath = $path;

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read alias config: {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in {$path}: " . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new RuntimeException("Alias config root must be an object: {$path}");
        }

        $this->pairs             = $this->validatePairs($data['pairs'] ?? [], $path);
        $this->equivalentDomains = $this->validateDomains($data['equivalent_domains'] ?? [], $path);
    }

    /**
     * @return array<int, array{alias_name:string, alias_email:string, canon_name:string, canon_email:string, note?:string}>
     */
    private function validatePairs(mixed $raw, string $path): array
    {
        if (!is_array($raw)) {
            throw new RuntimeException("pairs must be an array in {$path}");
        }

        $result = [];
        foreach ($raw as $i => $p) {
            if (!is_array($p)) {
                throw new RuntimeException("pairs[{$i}] must be an object in {$path}");
            }
            foreach (['alias_name', 'alias_email', 'canon_name', 'canon_email'] as $req) {
                if (!isset($p[$req]) || !is_string($p[$req]) || $p[$req] === '') {
                    throw new RuntimeException("pairs[{$i}].{$req} is required (non-empty string) in {$path}");
                }
            }
            $entry = [
                'alias_name'  => (string) $p['alias_name'],
                'alias_email' => (string) $p['alias_email'],
                'canon_name'  => (string) $p['canon_name'],
                'canon_email' => (string) $p['canon_email'],
            ];
            if (isset($p['note']) && is_string($p['note']) && $p['note'] !== '') {
                $entry['note'] = (string) $p['note'];
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function validateDomains(mixed $raw, string $path): array
    {
        if (!is_array($raw)) {
            throw new RuntimeException("equivalent_domains must be an array in {$path}");
        }

        $result = [];
        foreach ($raw as $i => $group) {
            if (!is_array($group) || count($group) < 2) {
                throw new RuntimeException("equivalent_domains[{$i}] must be a list of ≥2 domain strings in {$path}");
            }
            $clean = [];
            foreach ($group as $j => $d) {
                if (!is_string($d) || $d === '') {
                    throw new RuntimeException("equivalent_domains[{$i}][{$j}] must be a non-empty string in {$path}");
                }
                $clean[] = $d;
            }
            $result[] = $clean;
        }
        return $result;
    }

    public function getLoadedConfigPath(): string
    {
        return $this->loadedConfigPath;
    }

    /**
     * Run all steps.
     *
     * @param bool $dryRun When true — no writes; only log what would happen.
     * @param bool $quiet  When true — suppress per-pair INFO logs; keep summary + warnings.
     *
     * @return array{applied:int, skipped:int, commits_reassigned:int, reverts_reassigned:int, total_pairs:int, alias_records:int}
     */
    public function apply(bool $dryRun = false, bool $quiet = false): array
    {
        $pdo = Db::getInstance();

        $this->ensureCommitsColumns($pdo, $dryRun, $quiet);
        $hasCol = $this->ensureAliasColumn($pdo, $dryRun, $quiet);

        [$applied, $skipped] = $this->assignAliases($pdo, $dryRun, $hasCol, $quiet);
        $commitsReassigned   = $this->reassignCommits($pdo, $dryRun, $hasCol, $quiet);
        $revertsReassigned   = $this->reassignReverts($pdo, $dryRun, $hasCol, $quiet);

        $this->recreateViews($pdo, $dryRun, $quiet);

        $aliasRecords = ($hasCol && !$dryRun)
            ? (int) $pdo->query('SELECT COUNT(*) FROM developers WHERE alias_id IS NOT NULL')->fetchColumn()
            : $applied;

        return [
            'applied'             => $applied,
            'skipped'             => $skipped,
            'commits_reassigned'  => $commitsReassigned,
            'reverts_reassigned'  => $revertsReassigned,
            'total_pairs'         => count($this->pairs),
            'alias_records'       => $aliasRecords,
        ];
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    private function ensureCommitsColumns(PDO $pdo, bool $dryRun, bool $quiet): void
    {
        $cols = array_column(
            $pdo->query('PRAGMA table_info(commits)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        if (in_array('technical_commit', $cols, true)) {
            return;
        }

        $stmt = 'ALTER TABLE commits ADD COLUMN technical_commit INTEGER NOT NULL DEFAULT 0';
        $this->log("Adding column commits.technical_commit", $quiet);
        if (!$dryRun) {
            $pdo->exec($stmt);
        }
    }

    private function ensureAliasColumn(PDO $pdo, bool $dryRun, bool $quiet): bool
    {
        $cols   = $pdo->query('PRAGMA table_info(developers)')->fetchAll(PDO::FETCH_ASSOC);
        $hasCol = in_array('alias_id', array_column($cols, 'name'), true);

        if ($hasCol) {
            return true;
        }

        $this->log('Adding column developers.alias_id', $quiet);
        if ($dryRun) {
            return false;
        }

        $pdo->exec('ALTER TABLE developers ADD COLUMN alias_id INTEGER DEFAULT NULL REFERENCES developers(id) ON DELETE SET NULL');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_developers_alias_id ON developers(alias_id)');
        return true;
    }

    /** @return array{0:int,1:int} [applied, skipped] */
    private function assignAliases(PDO $pdo, bool $dryRun, bool $hasCol, bool $quiet): array
    {
        if ($hasCol && !$dryRun) {
            $pdo->exec('UPDATE developers SET alias_id = NULL WHERE alias_id IS NOT NULL');
        }

        $resolve = static function (PDO $pdo, string $n, string $e): ?int {
            $st = $pdo->prepare('SELECT id FROM developers WHERE author_name = :n AND author_email = :e LIMIT 1');
            $st->execute([':n' => $n, ':e' => $e]);
            $id = $st->fetchColumn();
            return $id === false ? null : (int) $id;
        };

        $applied      = 0;
        $skipped      = 0;
        $handledIds   = [];   // ids already in a manual alias→canonical relation

        // ---- 1) Manual pairs (always take precedence) ----
        foreach ($this->pairs as $pair) {
            $aliasId = $resolve($pdo, $pair['alias_name'], $pair['alias_email']);
            $canonId = $resolve($pdo, $pair['canon_name'], $pair['canon_email']);

            $aliasLabel = "{$pair['alias_name']} <{$pair['alias_email']}>";
            $canonLabel = "{$pair['canon_name']} <{$pair['canon_email']}>";
            $note       = isset($pair['note']) ? " ({$pair['note']})" : '';

            if ($aliasId === null) {
                Logger::warning("Alias skip — alias not in DB: {$aliasLabel}{$note}");
                $skipped++;
                continue;
            }
            if ($canonId === null) {
                Logger::warning("Alias skip — canonical not in DB: {$canonLabel} (for alias {$aliasLabel}){$note}");
                $skipped++;
                continue;
            }
            if ($aliasId === $canonId) {
                Logger::warning("Alias skip — same id #{$aliasId}: {$aliasLabel}{$note}");
                $skipped++;
                continue;
            }

            $this->log("Set #{$aliasId} ({$aliasLabel}) → alias of #{$canonId} ({$canonLabel}){$note}", $quiet);

            if (!$dryRun && $hasCol) {
                $st = $pdo->prepare('UPDATE developers SET alias_id = :c WHERE id = :a');
                $st->execute([':c' => $canonId, ':a' => $aliasId]);
            }

            $handledIds[$aliasId] = true;
            $handledIds[$canonId] = true;
            $applied++;
        }

        // ---- 2) Auto-discovered pairs from EQUIVALENT_DOMAINS ----
        $autoPairs = $this->discoverAutoPairs($pdo);

        foreach ($autoPairs as $pair) {
            $aliasId = $pair['alias_id'];
            $canonId = $pair['canon_id'];

            $aliasLabel = "{$pair['alias_name']} <{$pair['alias_email']}>";
            $canonLabel = "{$pair['canon_name']} <{$pair['canon_email']}>";
            $note       = " (auto: {$pair['reason']})";

            // Manual pairs win — do not contradict them.
            if (isset($handledIds[$aliasId]) || isset($handledIds[$canonId])) {
                $this->log("Auto-pair skip — id already handled by manual map: #{$aliasId} / #{$canonId}{$note}", $quiet);
                continue;
            }

            $this->log("Set #{$aliasId} ({$aliasLabel}) → alias of #{$canonId} ({$canonLabel}){$note}", $quiet);

            if (!$dryRun && $hasCol) {
                $st = $pdo->prepare('UPDATE developers SET alias_id = :c WHERE id = :a');
                $st->execute([':c' => $canonId, ':a' => $aliasId]);
            }

            $handledIds[$aliasId] = true;
            $handledIds[$canonId] = true;
            $applied++;
        }

        return [$applied, $skipped];
    }

    /**
     * Discover alias pairs by equivalent-domain rule.
     * Groups developers with the same lowercase local-part across any of the
     * domains listed in $this->equivalentDomains (from aliases.json), then
     * picks the "best" record per group as canonical and emits the rest as
     * aliases.
     *
     * Canonical selection priority:
     *   1. full-name record (author_name != local-part)
     *   2. preferred domain (first in the group)
     *   3. smallest id
     *
     * @return array<int, array{
     *     alias_id:int, alias_name:string, alias_email:string,
     *     canon_id:int, canon_name:string, canon_email:string,
     *     reason:string
     * }>
     */
    private function discoverAutoPairs(PDO $pdo): array
    {
        $pairs = [];

        foreach ($this->equivalentDomains as $group) {
            $preferred = strtolower($group[0]);
            $groupSet  = array_map('strtolower', $group);

            // Fetch all developers with any email in this domain group.
            $placeholders = implode(',', array_fill(0, count($groupSet), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, author_name, author_email FROM developers
                 WHERE LOWER(SUBSTR(author_email, INSTR(author_email, '@') + 1)) IN ({$placeholders})
                   AND author_email IS NOT NULL
                   AND INSTR(author_email, '@') > 1"
            );
            $stmt->execute($groupSet);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by lowercase local-part.
            $byLocal = [];
            foreach ($rows as $r) {
                $local = strtolower((string) strstr((string) $r['author_email'], '@', true));
                if ($local === '') {
                    continue;
                }
                $byLocal[$local][] = $r;
            }

            foreach ($byLocal as $local => $candidates) {
                if (count($candidates) < 2) {
                    continue;
                }

                // Confirm at least two distinct domains in this local group.
                $domains = array_unique(array_map(
                    static fn(array $r): string => strtolower(substr((string) $r['author_email'], strpos((string) $r['author_email'], '@') + 1)),
                    $candidates
                ));
                if (count($domains) < 2) {
                    continue;
                }

                // Pick canonical:  full-name > preferred-domain > smallest id
                usort($candidates, static function (array $a, array $b) use ($local, $preferred): int {
                    $aFull = (strtolower((string) $a['author_name']) !== $local) ? 1 : 0;
                    $bFull = (strtolower((string) $b['author_name']) !== $local) ? 1 : 0;
                    if ($aFull !== $bFull) {
                        return $bFull <=> $aFull; // full-name first
                    }
                    $aPref = (strtolower(substr((string) $a['author_email'], strpos((string) $a['author_email'], '@') + 1)) === $preferred) ? 1 : 0;
                    $bPref = (strtolower(substr((string) $b['author_email'], strpos((string) $b['author_email'], '@') + 1)) === $preferred) ? 1 : 0;
                    if ($aPref !== $bPref) {
                        return $bPref <=> $aPref; // preferred-domain first
                    }
                    return ((int) $a['id']) <=> ((int) $b['id']); // smaller id first
                });

                $canon = array_shift($candidates);
                $reason = sprintf(
                    'same local-part "%s" across [%s]',
                    $local,
                    implode(', ', $group)
                );

                foreach ($candidates as $alias) {
                    if ((int) $alias['id'] === (int) $canon['id']) {
                        continue;
                    }
                    $pairs[] = [
                        'alias_id'    => (int) $alias['id'],
                        'alias_name'  => (string) $alias['author_name'],
                        'alias_email' => (string) $alias['author_email'],
                        'canon_id'    => (int) $canon['id'],
                        'canon_name'  => (string) $canon['author_name'],
                        'canon_email' => (string) $canon['author_email'],
                        'reason'      => $reason,
                    ];
                }
            }
        }

        return $pairs;
    }

    private function reassignCommits(PDO $pdo, bool $dryRun, bool $hasCol, bool $quiet): int
    {
        if (!$hasCol) {
            return 0;
        }

        $cnt = (int) $pdo->query(
            'SELECT COUNT(*) FROM commits c
             INNER JOIN developers d ON d.id = c.developer_id
             WHERE d.alias_id IS NOT NULL'
        )->fetchColumn();

        if ($cnt === 0) {
            return 0;
        }

        $this->log("Reassigning commits.developer_id → canonical: {$cnt}", $quiet);

        if (!$dryRun) {
            $pdo->exec(
                'UPDATE commits
                 SET developer_id = (
                     SELECT alias_id FROM developers
                     WHERE id = commits.developer_id AND alias_id IS NOT NULL
                 )
                 WHERE developer_id IN (SELECT id FROM developers WHERE alias_id IS NOT NULL)'
            );
        }

        return $cnt;
    }

    private function reassignReverts(PDO $pdo, bool $dryRun, bool $hasCol, bool $quiet): int
    {
        if (!$hasCol) {
            return 0;
        }

        $cnt = (int) $pdo->query(
            'SELECT COUNT(*) FROM reverts r
             INNER JOIN developers d ON d.id = r.affected_developer_id
             WHERE d.alias_id IS NOT NULL'
        )->fetchColumn();

        if ($cnt === 0) {
            return 0;
        }

        $this->log("Reassigning reverts.affected_developer_id → canonical: {$cnt}", $quiet);

        if (!$dryRun) {
            $pdo->exec(
                'UPDATE reverts
                 SET affected_developer_id = (
                     SELECT alias_id FROM developers
                     WHERE id = reverts.affected_developer_id AND alias_id IS NOT NULL
                 )
                 WHERE affected_developer_id IN (SELECT id FROM developers WHERE alias_id IS NOT NULL)'
            );
        }

        return $cnt;
    }

    private function recreateViews(PDO $pdo, bool $dryRun, bool $quiet): void
    {
        if ($dryRun) {
            return;
        }

        $schemaFile = $this->baseDir . '/schema.sqlite.sql';
        $sql        = (string) file_get_contents($schemaFile);

        preg_match_all(
            '/(?:DROP\s+VIEW\s+IF\s+EXISTS\s+\w+\s*;|CREATE\s+VIEW\s+\w+\s+AS\s.+?;)/si',
            $sql,
            $matches
        );

        foreach ($matches[0] as $stmt) {
            $pdo->exec(trim($stmt));
        }

        $this->log('Analytics views recreated.', $quiet);
    }

    private function log(string $msg, bool $quiet): void
    {
        if (!$quiet) {
            Logger::info($msg);
        }
    }
}
