<?php
declare(strict_types=1);

/**
 * SQL queries for all reports.
 *
 * All queries use `vw_commit_facts` / `vw_revert_facts` so developer aliases
 * (developers.alias_id, applied by bin/apply-aliases.php) are honoured —
 * duplicate developers are collapsed into one canonical row.
 *
 * Each method returns array of associative rows with at least:
 *   - dev_id   : canonical developer id
 *   - dev_name : canonical author display
 *   - value    : the metric (commits count / lines / reverts count)
 * Pivot methods additionally return `period` (year as int or YYYY-MM).
 */
final class ReportRepository
{
    // ------------------------------------------------------------------
    // Commits
    // ------------------------------------------------------------------

    public function commitsFullPeriod(string $branch, string $from, string $to): array
    {
        return Db::fetchAll(
            'SELECT
                canonical_developer_id        AS dev_id,
                COALESCE(canonical_author_display, author_display) AS dev_name,
                COUNT(*)                      AS value
             FROM vw_commit_facts
             WHERE target_branch = :branch
               AND commit_date BETWEEN :from AND :to
               AND technical_commit = 0
               AND is_merge_commit  = 0
               AND is_revert_commit = 0
             GROUP BY canonical_developer_id
             ORDER BY value DESC, dev_name ASC',
            [':branch' => $branch, ':from' => $from, ':to' => $to]
        );
    }

    public function commitsByYear(string $branch, string $from, string $to): array
    {
        return Db::fetchAll(
            'SELECT
                canonical_developer_id        AS dev_id,
                COALESCE(canonical_author_display, author_display) AS dev_name,
                CAST(commit_year AS TEXT)     AS period,
                COUNT(*)                      AS value
             FROM vw_commit_facts
             WHERE target_branch = :branch
               AND commit_date BETWEEN :from AND :to
               AND technical_commit = 0
               AND is_merge_commit  = 0
               AND is_revert_commit = 0
             GROUP BY canonical_developer_id, commit_year
             ORDER BY commit_year ASC, value DESC',
            [':branch' => $branch, ':from' => $from, ':to' => $to]
        );
    }

    public function commitsByMonth(string $branch, string $from, string $to): array
    {
        return Db::fetchAll(
            'SELECT
                canonical_developer_id        AS dev_id,
                COALESCE(canonical_author_display, author_display) AS dev_name,
                commit_year_month             AS period,
                COUNT(*)                      AS value
             FROM vw_commit_facts
             WHERE target_branch = :branch
               AND commit_date BETWEEN :from AND :to
               AND technical_commit = 0
               AND is_merge_commit  = 0
               AND is_revert_commit = 0
             GROUP BY canonical_developer_id, commit_year_month
             ORDER BY commit_year_month ASC, value DESC',
            [':branch' => $branch, ':from' => $from, ':to' => $to]
        );
    }

    // ------------------------------------------------------------------
    // Lines changed
    // ------------------------------------------------------------------

    public function linesFullPeriod(string $branch, string $from, string $to): array
    {
        return Db::fetchAll(
            'SELECT
                canonical_developer_id        AS dev_id,
                COALESCE(canonical_author_display, author_display) AS dev_name,
                COALESCE(SUM(lines_changed_total), 0) AS value
             FROM vw_commit_facts
             WHERE target_branch = :branch
               AND commit_date BETWEEN :from AND :to
               AND technical_commit = 0
               AND is_merge_commit  = 0
               AND is_revert_commit = 0
             GROUP BY canonical_developer_id
             ORDER BY value DESC, dev_name ASC',
            [':branch' => $branch, ':from' => $from, ':to' => $to]
        );
    }

    public function linesByYear(string $branch, string $from, string $to): array
    {
        return Db::fetchAll(
            'SELECT
                canonical_developer_id        AS dev_id,
                COALESCE(canonical_author_display, author_display) AS dev_name,
                CAST(commit_year AS TEXT)     AS period,
                COALESCE(SUM(lines_changed_total), 0) AS value
             FROM vw_commit_facts
             WHERE target_branch = :branch
               AND commit_date BETWEEN :from AND :to
               AND technical_commit = 0
               AND is_merge_commit  = 0
               AND is_revert_commit = 0
             GROUP BY canonical_developer_id, commit_year
             ORDER BY commit_year ASC, value DESC',
            [':branch' => $branch, ':from' => $from, ':to' => $to]
        );
    }

    public function linesByMonth(string $branch, string $from, string $to): array
    {
        return Db::fetchAll(
            'SELECT
                canonical_developer_id        AS dev_id,
                COALESCE(canonical_author_display, author_display) AS dev_name,
                commit_year_month             AS period,
                COALESCE(SUM(lines_changed_total), 0) AS value
             FROM vw_commit_facts
             WHERE target_branch = :branch
               AND commit_date BETWEEN :from AND :to
               AND technical_commit = 0
               AND is_merge_commit  = 0
               AND is_revert_commit = 0
             GROUP BY canonical_developer_id, commit_year_month
             ORDER BY commit_year_month ASC, value DESC',
            [':branch' => $branch, ':from' => $from, ':to' => $to]
        );
    }

    // ------------------------------------------------------------------
    // Reverts (affected developer = author of branch/ticket being reverted)
    // ------------------------------------------------------------------

    public function revertsFullPeriod(string $branch, string $from, string $to, ?string $alias = null): array
    {
        [$aliasSql, $aliasParams] = $this->aliasFilterSql($alias);

        return Db::fetchAll(
            'SELECT
                affected_canonical_developer_id AS dev_id,
                COALESCE(affected_author_display, "(невідомо)") AS dev_name,
                COUNT(*)                        AS value
             FROM vw_revert_facts
             WHERE target_branch = :branch
               AND revert_date BETWEEN :from AND :to
               ' . $aliasSql . '
             GROUP BY affected_canonical_developer_id, dev_name
             ORDER BY value DESC, dev_name ASC',
            array_merge([':branch' => $branch, ':from' => $from, ':to' => $to], $aliasParams)
        );
    }

    public function revertsByYear(string $branch, string $from, string $to, ?string $alias = null): array
    {
        [$aliasSql, $aliasParams] = $this->aliasFilterSql($alias);

        return Db::fetchAll(
            'SELECT
                affected_canonical_developer_id AS dev_id,
                COALESCE(affected_author_display, "(невідомо)") AS dev_name,
                CAST(revert_year AS TEXT)       AS period,
                COUNT(*)                        AS value
             FROM vw_revert_facts
             WHERE target_branch = :branch
               AND revert_date BETWEEN :from AND :to
               ' . $aliasSql . '
             GROUP BY affected_canonical_developer_id, dev_name, revert_year
             ORDER BY revert_year ASC, value DESC',
            array_merge([':branch' => $branch, ':from' => $from, ':to' => $to], $aliasParams)
        );
    }

    public function revertsByMonth(string $branch, string $from, string $to, ?string $alias = null): array
    {
        [$aliasSql, $aliasParams] = $this->aliasFilterSql($alias);

        return Db::fetchAll(
            'SELECT
                affected_canonical_developer_id AS dev_id,
                COALESCE(affected_author_display, "(невідомо)") AS dev_name,
                revert_year_month               AS period,
                COUNT(*)                        AS value
             FROM vw_revert_facts
             WHERE target_branch = :branch
               AND revert_date BETWEEN :from AND :to
               ' . $aliasSql . '
             GROUP BY affected_canonical_developer_id, dev_name, revert_year_month
             ORDER BY revert_year_month ASC, value DESC',
            array_merge([':branch' => $branch, ':from' => $from, ':to' => $to], $aliasParams)
        );
    }

    /**
     * List individual revert commits (used by --detail).
     * Returns rows: dev_id, dev_name, revert_date, commit_hash_short, subject, ticket_code.
     */
    public function revertDetails(string $branch, string $from, string $to, ?string $alias = null): array
    {
        [$aliasSql, $aliasParams] = $this->aliasFilterSql($alias);

        return Db::fetchAll(
            'SELECT
                affected_canonical_developer_id      AS dev_id,
                COALESCE(affected_author_display, "(невідомо)") AS dev_name,
                revert_date                          AS revert_date,
                revert_commit_datetime               AS revert_datetime,
                revert_commit_hash                   AS commit_hash,
                revert_commit_hash_short             AS commit_hash_short,
                revert_commit_subject                AS subject,
                COALESCE(ticket_code, "")            AS ticket_code,
                COALESCE(matched_commit_hash, "")    AS matched_commit_hash,
                COALESCE(matched_commit_subject, "") AS matched_commit_subject,
                detected_by                          AS detected_by
             FROM vw_revert_facts
             WHERE target_branch = :branch
               AND revert_date BETWEEN :from AND :to
               ' . $aliasSql . '
             ORDER BY dev_name ASC, revert_datetime DESC',
            array_merge([':branch' => $branch, ':from' => $from, ':to' => $to], $aliasParams)
        );
    }

    /**
     * Build a SQL fragment + params for filtering vw_revert_facts by alias.
     * Match priority on $alias:
     *   1. exact lowercase match against full email (e.g. jane.smith@example.com)
     *   2. lowercase local-part match (everything before "@" in affected_author_email)
     *   3. case-insensitive substring of affected_author_name OR affected_author_display
     *
     * @return array{0:string, 1:array<string,string>}
     */
    private function aliasFilterSql(?string $alias): array
    {
        if ($alias === null || trim($alias) === '') {
            return ['', []];
        }
        $a = strtolower(trim($alias));

        $sql = ' AND (
            LOWER(affected_author_email) = :alias_full
            OR LOWER(SUBSTR(affected_author_email, 1, INSTR(affected_author_email, "@") - 1)) = :alias_local
            OR LOWER(affected_author_name)    LIKE :alias_like
            OR LOWER(affected_author_display) LIKE :alias_like
        )';

        return [$sql, [
            ':alias_full'  => $a,
            ':alias_local' => $a,
            ':alias_like'  => '%' . $a . '%',
        ]];
    }

    /**
     * List canonical developers that had at least one commit on the branch
     * within the period. Used by reverts-* reports to include zero-revert
     * rows for active contributors.
     *
     * Same alias filter semantics as for revert queries — when $alias is set,
     * matches against canonical author email/name/display of the commit author.
     *
     * @return array<int, array{dev_id:int, dev_name:string}>
     */
    public function commitDevelopersInPeriod(string $branch, string $from, string $to, ?string $alias = null): array
    {
        $aliasSql    = '';
        $aliasParams = [];

        if ($alias !== null && trim($alias) !== '') {
            $a = strtolower(trim($alias));
            $aliasSql = ' AND (
                LOWER(canonical_author_email) = :alias_full
                OR LOWER(SUBSTR(canonical_author_email, 1, INSTR(canonical_author_email, "@") - 1)) = :alias_local
                OR LOWER(COALESCE(canonical_author_name, author_name))       LIKE :alias_like
                OR LOWER(COALESCE(canonical_author_display, author_display)) LIKE :alias_like
            )';
            $aliasParams = [
                ':alias_full'  => $a,
                ':alias_local' => $a,
                ':alias_like'  => '%' . $a . '%',
            ];
        }

        $rows = Db::fetchAll(
            'SELECT
                canonical_developer_id                                AS dev_id,
                COALESCE(canonical_author_display, author_display)    AS dev_name
             FROM vw_commit_facts
             WHERE target_branch = :branch
               AND commit_date BETWEEN :from AND :to
               ' . $aliasSql . '
             GROUP BY canonical_developer_id, dev_name
             ORDER BY dev_name ASC',
            array_merge([':branch' => $branch, ':from' => $from, ':to' => $to], $aliasParams)
        );

        return array_map(static fn(array $r): array => [
            'dev_id'   => (int) $r['dev_id'],
            'dev_name' => (string) $r['dev_name'],
        ], $rows);
    }

    // ------------------------------------------------------------------
    // Diagnostics
    // ------------------------------------------------------------------

    public function commitsCountInPeriod(string $branch, string $from, string $to): int
    {
        $row = Db::fetchOne(
            'SELECT COUNT(*) AS cnt
             FROM commits c
             INNER JOIN import_runs ir ON ir.id = c.import_run_id
             WHERE ir.target_branch = :branch
               AND c.commit_date BETWEEN :from AND :to',
            [':branch' => $branch, ':from' => $from, ':to' => $to]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    public function aliasedDevelopersCount(): int
    {
        $row = Db::fetchOne('SELECT COUNT(*) AS cnt FROM developers WHERE alias_id IS NOT NULL');
        return (int) ($row['cnt'] ?? 0);
    }

    public function hasView(string $viewName): bool
    {
        $row = Db::fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'view' AND name = :n",
            [':n' => $viewName]
        );
        return $row !== null;
    }
}
