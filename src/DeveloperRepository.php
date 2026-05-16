<?php
declare(strict_types=1);

/**
 * Manages developers table — SELECT-first approach handles NULL emails correctly.
 *
 * Note: MySQL UNIQUE KEY treats two NULL values as non-equal, so
 * ON DUPLICATE KEY would not fire for (name, NULL) vs (name, NULL).
 * We avoid this by always checking existence before inserting.
 *
 * Alias resolution: if a developer record has alias_id set, upsert() returns
 * alias_id (the canonical developer id) so all commits are attributed to one record.
 */
class DeveloperRepository
{
    /** @var array<string, int> in-memory cache: "name|email" → canonical developer_id */
    private array $cache = [];

    /**
     * Find or create a developer record.
     * If the record has alias_id set, returns alias_id (canonical developer).
     * Returns developer_id (0 on unexpected failure).
     */
    public function upsert(string $name, ?string $email): int
    {
        $cacheKey = $name . '|' . ($email ?? '');

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Check existence first (handles NULL correctly)
        $id = $this->findId($name, $email);

        if ($id !== null) {
            $canonical = $this->resolveCanonicalId($id);
            $this->cache[$cacheKey] = $canonical;
            return $canonical;
        }

        // Insert new developer
        $display = $email ? "{$name} <{$email}>" : $name;

        try {
            Db::execute(
                'INSERT INTO developers (author_name, author_email, author_display)
                 VALUES (:name, :email, :display)',
                [':name' => $name, ':email' => $email, ':display' => $display]
            );
            $id = Db::lastInsertId();
        } catch (PDOException) {
            // Race condition — re-fetch
            $id = $this->findId($name, $email) ?? 0;
        }

        if ($id > 0) {
            $canonical = $this->resolveCanonicalId($id);
            $this->cache[$cacheKey] = $canonical;
            return $canonical;
        }

        return 0;
    }

    public function getIdByIdentity(string $name, ?string $email): ?int
    {
        $cacheKey = $name . '|' . ($email ?? '');

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $id = $this->findId($name, $email);

        if ($id !== null) {
            $canonical = $this->resolveCanonicalId($id);
            $this->cache[$cacheKey] = $canonical;
            return $canonical;
        }

        return null;
    }

    /**
     * Set alias: marks $aliasId as a duplicate pointing to $canonicalId.
     * Clears in-memory cache so next upsert re-checks.
     */
    public function setAlias(int $aliasId, int $canonicalId): void
    {
        Db::execute(
            'UPDATE developers SET alias_id = :canonical WHERE id = :alias',
            [':canonical' => $canonicalId, ':alias' => $aliasId]
        );
        $this->cache = [];
    }

    /**
     * Follow alias_id chain and return the canonical (non-alias) developer id.
     * Stops after one hop — alias chains deeper than 1 are not supported.
     */
    public function resolveCanonicalId(int $id): int
    {
        $row = Db::fetchOne(
            'SELECT alias_id FROM developers WHERE id = :id',
            [':id' => $id]
        );

        if ($row && !empty($row['alias_id'])) {
            return (int) $row['alias_id'];
        }

        return $id;
    }

    private function findId(string $name, ?string $email): ?int
    {
        if ($email !== null) {
            $row = Db::fetchOne(
                'SELECT id FROM developers WHERE author_name = :name AND author_email = :email',
                [':name' => $name, ':email' => $email]
            );
        } else {
            $row = Db::fetchOne(
                'SELECT id FROM developers WHERE author_name = :name AND author_email IS NULL',
                [':name' => $name]
            );
        }

        return $row ? (int) $row['id'] : null;
    }
}

