<?php
declare(strict_types=1);

/**
 * PDO singleton for the SQLite git_analytics database.
 *
 * - DB file path is read from Config::get('db.path').
 * - The file and its parent directory are created automatically if absent.
 * - PRAGMA foreign_keys is enabled on every new connection.
 * - PRAGMA journal_mode=WAL is set for safer concurrent reads.
 * - initSchema() applies the DDL file on first run (idempotent: uses IF NOT EXISTS).
 */
class Db
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $path = (string) Config::get('db.path');

            // Ensure the data directory exists
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create DB directory: {$dir}");
            }

            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // Required for FK enforcement in SQLite
            $pdo->exec('PRAGMA foreign_keys = ON');
            // Write-Ahead Logging – better durability & read concurrency
            $pdo->exec('PRAGMA journal_mode = WAL');
            // Slightly faster writes; acceptable for analytics use
            $pdo->exec('PRAGMA synchronous = NORMAL');

            self::$instance = $pdo;
        }

        return self::$instance;
    }

    /**
     * Apply DDL from $schemaFile to initialise/migrate the database.
     * All statements use CREATE TABLE/INDEX IF NOT EXISTS, so this is
     * safe to call on every run.
     */
    public static function initSchema(string $schemaFile): void
    {
        if (!file_exists($schemaFile)) {
            throw new RuntimeException("Schema file not found: {$schemaFile}");
        }

        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException("Cannot read schema file: {$schemaFile}");
        }

        // Strip all SQL line comments (-- ...) before splitting.
        // This prevents fragments that start with a comment block (followed by real SQL)
        // from being silently skipped by the empty-check filter.
        $sql = (string) preg_replace('/--[^\n]*\n?/', "\n", $sql);

        // Split on semicolons; skip blank fragments
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn(string $s): bool => $s !== ''
        );

        $pdo = self::getInstance();
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
    }

    public static function execute(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::execute($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::execute($sql, $params)->fetch();
        return $row !== false ? $row : null;
    }

    public static function lastInsertId(): int
    {
        return (int) self::getInstance()->lastInsertId();
    }

    /** Force reconnect on next call (useful for tests / schema re-init). */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

