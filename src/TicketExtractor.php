<?php
declare(strict_types=1);

/**
 * Extracts ticket codes (e.g. RFC-120069) from arbitrary text.
 *
 * Handles:
 *   - Plain text: "RFC-120069 - SomeFeature"
 *   - URL-embedded: "RFC-113843?atlOrigin=..." → extracts only "RFC-113843"
 *   - Multiple tickets in one string
 *   - Duplicates (returns unique set)
 *   - Case-insensitive matching (result is always uppercase)
 */
class TicketExtractor
{
    /** Matches RFC-NNNNN where N is 5–9 digits. Word boundary prevents partial matches. */
    private const PATTERN = '/(RFC-\d{5,9})(?=[^0-9]|$)/i';

    /**
     * Extract all unique ticket codes from a text string.
     *
     * @return string[] e.g. ['RFC-120069', 'RFC-120070']
     */
    public function extract(string $text): array
    {
        if (!preg_match_all(self::PATTERN, $text, $matches)) {
            return [];
        }

        return array_values(
            array_unique(array_map('strtoupper', $matches[1]))
        );
    }

    /**
     * Extract ticket codes from a git branch name.
     * Calls extract() internally — branch names are treated as plain text.
     *
     * @return string[]
     */
    public function extractFromBranch(string $branchName): array
    {
        return $this->extract($branchName);
    }
}

