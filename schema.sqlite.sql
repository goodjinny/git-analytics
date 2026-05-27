-- ============================================================================
-- Git analytics — SQLite schema
-- Source requirements: test/git-analytics/task.md
-- SQLite version: 3.24.0+ (ON CONFLICT upsert requires 3.24.0)
-- Note: foreign key enforcement needs PRAGMA foreign_keys = ON per connection
-- ============================================================================

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

-- ============================================================================
-- 1. Import runs
-- ============================================================================

CREATE TABLE IF NOT EXISTS import_runs (
    id                INTEGER  PRIMARY KEY AUTOINCREMENT,
    project_name      TEXT     NOT NULL DEFAULT '',
    source_repo       TEXT     NOT NULL,
    target_branch     TEXT     NOT NULL,
    report_date_from  TEXT     NOT NULL,  -- YYYY-MM-DD
    report_date_to    TEXT     NOT NULL,  -- YYYY-MM-DD
    started_at        TEXT     NOT NULL DEFAULT (datetime('now')),
    finished_at       TEXT     DEFAULT NULL,
    status            TEXT     NOT NULL DEFAULT 'running'
                               CHECK(status IN ('running','success','failed')),
    commits_found     INTEGER  NOT NULL DEFAULT 0,
    reverts_found     INTEGER  NOT NULL DEFAULT 0,
    error_message     TEXT     DEFAULT NULL,
    created_at        TEXT     NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_import_runs_branch_period
    ON import_runs(target_branch, report_date_from, report_date_to);

CREATE INDEX IF NOT EXISTS idx_import_runs_project
    ON import_runs(project_name);

CREATE INDEX IF NOT EXISTS idx_import_runs_project_branch_period
    ON import_runs(project_name, target_branch, report_date_from, report_date_to);

CREATE INDEX IF NOT EXISTS idx_import_runs_status
    ON import_runs(status);

CREATE INDEX IF NOT EXISTS idx_import_runs_started_at
    ON import_runs(started_at);

-- ============================================================================
-- 2. Developers
-- ============================================================================

CREATE TABLE IF NOT EXISTS developers (
    id               INTEGER  PRIMARY KEY AUTOINCREMENT,
    author_name      TEXT     NOT NULL,
    author_email     TEXT     DEFAULT NULL,
    author_display   TEXT     NOT NULL,
    normalized_name  TEXT     DEFAULT NULL,
    normalized_email TEXT     DEFAULT NULL,
    is_active        INTEGER  NOT NULL DEFAULT 1,
    alias_id         INTEGER  DEFAULT NULL REFERENCES developers(id) ON DELETE SET NULL,
    created_at       TEXT     NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT     NOT NULL DEFAULT (datetime('now'))
);

-- Unique key on (author_name, author_email).
-- NOTE: SQLite treats two NULLs as distinct in UNIQUE indexes,
-- matching the SELECT-first upsert logic in DeveloperRepository.
CREATE UNIQUE INDEX IF NOT EXISTS uq_developers_author_identity
    ON developers(author_name, author_email);

CREATE INDEX IF NOT EXISTS idx_developers_author_email
    ON developers(author_email);

CREATE INDEX IF NOT EXISTS idx_developers_normalized_name
    ON developers(normalized_name);

CREATE INDEX IF NOT EXISTS idx_developers_normalized_email
    ON developers(normalized_email);

CREATE INDEX IF NOT EXISTS idx_developers_alias_id
    ON developers(alias_id);

-- ============================================================================
-- 3. Tickets
-- ============================================================================

CREATE TABLE IF NOT EXISTS tickets (
    id           INTEGER  PRIMARY KEY AUTOINCREMENT,
    ticket_code  TEXT     NOT NULL,
    ticket_type  TEXT     NOT NULL DEFAULT 'RFC',
    numeric_part INTEGER  DEFAULT NULL,
    created_at   TEXT     NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_tickets_ticket_code ON tickets(ticket_code);
CREATE INDEX IF NOT EXISTS idx_tickets_ticket_type  ON tickets(ticket_type);
CREATE INDEX IF NOT EXISTS idx_tickets_numeric_part ON tickets(numeric_part);

-- ============================================================================
-- 4. Commits
-- ============================================================================

CREATE TABLE IF NOT EXISTS commits (
    id                  INTEGER  PRIMARY KEY AUTOINCREMENT,
    import_run_id       INTEGER  NOT NULL,
    developer_id        INTEGER  NOT NULL,
    commit_hash         TEXT     NOT NULL,  -- full SHA-1
    commit_hash_short   TEXT     NOT NULL,
    branch_name         TEXT     NOT NULL,
    commit_datetime     TEXT     NOT NULL,  -- YYYY-MM-DD HH:MM:SS
    commit_date         TEXT     NOT NULL,  -- YYYY-MM-DD
    commit_year         INTEGER  NOT NULL,
    commit_month        INTEGER  NOT NULL CHECK(commit_month BETWEEN 1 AND 12),
    commit_year_month   TEXT     NOT NULL,  -- YYYY-MM
    subject             TEXT     NOT NULL,
    body                TEXT     DEFAULT NULL,
    message_full        TEXT     NOT NULL,
    files_changed       INTEGER  NOT NULL DEFAULT 0,
    lines_added         INTEGER  NOT NULL DEFAULT 0,
    lines_deleted       INTEGER  NOT NULL DEFAULT 0,
    lines_changed_total INTEGER  NOT NULL DEFAULT 0,
    is_merge_commit     INTEGER  NOT NULL DEFAULT 0,  -- 0/1 boolean
    is_revert_commit    INTEGER  NOT NULL DEFAULT 0,  -- 0/1 boolean
    technical_commit    INTEGER  NOT NULL DEFAULT 0,  -- 1 for 'Merge branch...' or 'Revert...' commits
    parent_hashes       TEXT     DEFAULT NULL,
    raw_payload_json    TEXT     DEFAULT NULL,
    created_at          TEXT     NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (import_run_id) REFERENCES import_runs(id),
    FOREIGN KEY (developer_id)  REFERENCES developers(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_commits_hash_branch
    ON commits(commit_hash, branch_name);

CREATE INDEX IF NOT EXISTS idx_commits_import_run_id       ON commits(import_run_id);
CREATE INDEX IF NOT EXISTS idx_commits_developer_period    ON commits(developer_id, commit_date);
CREATE INDEX IF NOT EXISTS idx_commits_branch_period       ON commits(branch_name, commit_date);
CREATE INDEX IF NOT EXISTS idx_commits_commit_year         ON commits(commit_year);
CREATE INDEX IF NOT EXISTS idx_commits_commit_month        ON commits(commit_month);
CREATE INDEX IF NOT EXISTS idx_commits_commit_year_month   ON commits(commit_year_month);
CREATE INDEX IF NOT EXISTS idx_commits_is_revert_commit    ON commits(is_revert_commit);
CREATE INDEX IF NOT EXISTS idx_commits_is_merge_commit     ON commits(is_merge_commit);
CREATE INDEX IF NOT EXISTS idx_commits_technical_commit    ON commits(technical_commit);
CREATE INDEX IF NOT EXISTS idx_commits_hash_short          ON commits(commit_hash_short);

-- ============================================================================
-- 5. Commit-tickets (M:N)
-- ============================================================================

CREATE TABLE IF NOT EXISTS commit_tickets (
    id           INTEGER  PRIMARY KEY AUTOINCREMENT,
    commit_id    INTEGER  NOT NULL,
    ticket_id    INTEGER  NOT NULL,
    match_source TEXT     NOT NULL DEFAULT 'subject'
                          CHECK(match_source IN ('subject','body','branch','revert_message')),
    created_at   TEXT     NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (commit_id)  REFERENCES commits(id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_id)  REFERENCES tickets(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_commit_tickets
    ON commit_tickets(commit_id, ticket_id, match_source);

CREATE INDEX IF NOT EXISTS idx_commit_tickets_ticket_id    ON commit_tickets(ticket_id);
CREATE INDEX IF NOT EXISTS idx_commit_tickets_match_source ON commit_tickets(match_source);

-- ============================================================================
-- 6. Reverts
-- ============================================================================

CREATE TABLE IF NOT EXISTS reverts (
    id                     INTEGER  PRIMARY KEY AUTOINCREMENT,
    import_run_id          INTEGER  NOT NULL,
    revert_commit_id       INTEGER  NOT NULL,
    reverted_branch_name   TEXT     DEFAULT NULL,
    reverted_target_branch TEXT     DEFAULT NULL,
    ticket_id              INTEGER  DEFAULT NULL,
    affected_developer_id  INTEGER  DEFAULT NULL,
    detected_by            TEXT     NOT NULL DEFAULT 'unknown'
                                    CHECK(detected_by IN (
                                        'branch_author','ticket_commit_author',
                                        'message_match','manual','unknown'
                                    )),
    matched_commit_id      INTEGER  DEFAULT NULL,
    confidence_score       REAL     NOT NULL DEFAULT 0.0,
    detection_notes        TEXT     DEFAULT NULL,
    revert_date            TEXT     NOT NULL,  -- YYYY-MM-DD
    revert_year            INTEGER  NOT NULL,
    revert_month           INTEGER  NOT NULL CHECK(revert_month BETWEEN 1 AND 12),
    revert_year_month      TEXT     NOT NULL,  -- YYYY-MM
    created_at             TEXT     NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (import_run_id)         REFERENCES import_runs(id),
    FOREIGN KEY (revert_commit_id)      REFERENCES commits(id)    ON DELETE CASCADE,
    FOREIGN KEY (ticket_id)             REFERENCES tickets(id),
    FOREIGN KEY (affected_developer_id) REFERENCES developers(id),
    FOREIGN KEY (matched_commit_id)     REFERENCES commits(id)
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_reverts_revert_commit
    ON reverts(revert_commit_id);

CREATE INDEX IF NOT EXISTS idx_reverts_import_run_id              ON reverts(import_run_id);
CREATE INDEX IF NOT EXISTS idx_reverts_affected_developer_period  ON reverts(affected_developer_id, revert_date);
CREATE INDEX IF NOT EXISTS idx_reverts_ticket_id                  ON reverts(ticket_id);
CREATE INDEX IF NOT EXISTS idx_reverts_revert_year                ON reverts(revert_year);
CREATE INDEX IF NOT EXISTS idx_reverts_revert_month               ON reverts(revert_month);
CREATE INDEX IF NOT EXISTS idx_reverts_revert_year_month          ON reverts(revert_year_month);
CREATE INDEX IF NOT EXISTS idx_reverts_detected_by                ON reverts(detected_by);
CREATE INDEX IF NOT EXISTS idx_reverts_matched_commit_id          ON reverts(matched_commit_id);

-- ============================================================================
-- Helper views (DROP before CREATE to allow schema updates on existing DBs)
-- ============================================================================

DROP VIEW IF EXISTS vw_commit_facts;
CREATE VIEW vw_commit_facts AS
SELECT
    c.id,
    c.branch_name,
    c.commit_hash,
    c.commit_hash_short,
    c.commit_datetime,
    c.commit_date,
    c.commit_year,
    c.commit_month,
    c.commit_year_month,
    c.subject,
    c.message_full,
    c.files_changed,
    c.lines_added,
    c.lines_deleted,
    c.lines_changed_total,
    c.is_merge_commit,
    c.is_revert_commit,
    c.technical_commit,
    d.id                           AS developer_id,
    COALESCE(d.alias_id, d.id)     AS canonical_developer_id,
    d.author_name,
    d.author_email,
    d.author_display,
    cd.author_name                 AS canonical_author_name,
    cd.author_email                AS canonical_author_email,
    cd.author_display              AS canonical_author_display,
    ir.id                          AS import_run_id,
    ir.project_name,
    ir.target_branch,
    ir.report_date_from,
    ir.report_date_to
FROM commits c
INNER JOIN developers  d  ON d.id  = c.developer_id
LEFT  JOIN developers  cd ON cd.id = d.alias_id
INNER JOIN import_runs ir ON ir.id = c.import_run_id;

DROP VIEW IF EXISTS vw_developer_canonical;
CREATE VIEW vw_developer_canonical AS
SELECT
    d.id,
    d.author_name,
    d.author_email,
    d.author_display,
    d.alias_id,
    COALESCE(d.alias_id, d.id)  AS canonical_id,
    (d.alias_id IS NULL)        AS is_canonical
FROM developers d;

DROP VIEW IF EXISTS vw_revert_facts;
CREATE VIEW vw_revert_facts AS
SELECT
    r.id,
    r.revert_date,
    r.revert_year,
    r.revert_month,
    r.revert_year_month,
    r.reverted_branch_name,
    r.reverted_target_branch,
    r.detected_by,
    r.confidence_score,
    r.detection_notes,
    rc.commit_hash                AS revert_commit_hash,
    rc.commit_hash_short          AS revert_commit_hash_short,
    rc.commit_datetime            AS revert_commit_datetime,
    rc.subject                    AS revert_commit_subject,
    COALESCE(ad.alias_id, ad.id)  AS affected_canonical_developer_id,
    ad.id                         AS affected_developer_id,
    ad.author_name                AS affected_author_name,
    ad.author_email               AS affected_author_email,
    ad.author_display             AS affected_author_display,
    t.ticket_code,
    mc.commit_hash                AS matched_commit_hash,
    mc.subject                    AS matched_commit_subject,
    ir.project_name,
    ir.target_branch,
    ir.report_date_from,
    ir.report_date_to
FROM reverts r
INNER JOIN commits     rc ON rc.id = r.revert_commit_id
INNER JOIN import_runs ir ON ir.id = r.import_run_id
LEFT JOIN  developers  ad ON ad.id = r.affected_developer_id
LEFT JOIN  tickets     t  ON t.id  = r.ticket_id
LEFT JOIN  commits     mc ON mc.id = r.matched_commit_id;

-- ============================================================================
-- Migrations for existing databases
-- (Db::initSchema silently skips these if the column already exists)
-- ============================================================================

ALTER TABLE import_runs ADD COLUMN project_name TEXT NOT NULL DEFAULT '';
