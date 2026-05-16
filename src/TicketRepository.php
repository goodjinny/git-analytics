<?php
declare(strict_types=1);

/**
 * Manages tickets table — SELECT-first upsert pattern with in-memory cache.
 */
class TicketRepository
{
    /** @var array<string, int> ticket_code (uppercase) → ticket_id */
    private array $cache = [];

    /**
     * Find or create a ticket by code.
     * Returns ticket_id (0 on failure).
     */
    public function upsert(string $code): int
    {
        $code = strtoupper($code);

        if (isset($this->cache[$code])) {
            return $this->cache[$code];
        }

        // Check existence first
        $existing = Db::fetchOne('SELECT id FROM tickets WHERE ticket_code = :code', [':code' => $code]);
        if ($existing) {
            $id = (int) $existing['id'];
            $this->cache[$code] = $id;
            return $id;
        }

        // Parse code parts
        [$type, $numPart] = $this->parseCode($code);

        try {
            Db::execute(
                'INSERT INTO tickets (ticket_code, ticket_type, numeric_part) VALUES (:code, :type, :num)',
                [':code' => $code, ':type' => $type, ':num' => $numPart]
            );
            $id = Db::lastInsertId();
        } catch (PDOException) {
            // Race condition
            $row = Db::fetchOne('SELECT id FROM tickets WHERE ticket_code = :code', [':code' => $code]);
            $id  = $row ? (int) $row['id'] : 0;
        }

        if ($id > 0) {
            $this->cache[$code] = $id;
        }

        return $id;
    }

    public function getIdByCode(string $code): ?int
    {
        $code = strtoupper($code);

        if (isset($this->cache[$code])) {
            return $this->cache[$code];
        }

        $row = Db::fetchOne('SELECT id FROM tickets WHERE ticket_code = :code', [':code' => $code]);

        if ($row) {
            $this->cache[$code] = (int) $row['id'];
            return (int) $row['id'];
        }

        return null;
    }

    /** @return array{0: string, 1: int|null} [type, numericPart] */
    private function parseCode(string $code): array
    {
        $parts = explode('-', $code, 2);
        $type  = $parts[0] ?? 'RFC';
        $num   = (isset($parts[1]) && ctype_digit($parts[1])) ? (int) $parts[1] : null;
        return [$type, $num];
    }
}

