<?php
declare(strict_types=1);

/**
 * Static registry of report definitions.
 *
 * Each entry describes:
 *   - title       : human-readable Ukrainian title (used in markdown header)
 *   - method      : ReportRepository method name returning rows
 *   - renderer    : 'simple' (dev × single metric) or 'pivot' (dev × period × metric)
 *   - metric      : column name in row that holds the numeric value
 *   - metricLabel : column header in markdown for 'simple' renderer
 *   - pivotKey    : (pivot only) column name that holds the period label (year / YYYY-MM)
 *   - pivotLabel  : (pivot only) header for "Всього" column
 */
final class ReportDefinitions
{
    public const FULL = 'full-report';
    public const ALL  = 'all';

    public const REPORTS = [
        'commits-full-period' => [
            'title'       => 'Загальна кількість комітів по розробникам за звітний період',
            'method'      => 'commitsFullPeriod',
            'renderer'    => 'simple',
            'metric'      => 'value',
            'metricLabel' => 'Комітів',
        ],
        'commits-by-year' => [
            'title'      => 'Кількість комітів по розробникам за роками',
            'method'     => 'commitsByYear',
            'renderer'   => 'pivot',
            'metric'     => 'value',
            'pivotKey'   => 'period',
            'pivotLabel' => 'Всього',
        ],
        'commits-by-month' => [
            'title'      => 'Кількість комітів по розробникам за місяцями',
            'method'     => 'commitsByMonth',
            'renderer'   => 'pivot',
            'metric'     => 'value',
            'pivotKey'   => 'period',
            'pivotLabel' => 'Всього',
        ],
        'lines-full-period' => [
            'title'       => 'Кількість змінених рядків по розробникам за звітний період',
            'method'      => 'linesFullPeriod',
            'renderer'    => 'simple',
            'metric'      => 'value',
            'metricLabel' => 'Рядків',
        ],
        'lines-by-year' => [
            'title'      => 'Кількість змінених рядків по розробникам за роками',
            'method'     => 'linesByYear',
            'renderer'   => 'pivot',
            'metric'     => 'value',
            'pivotKey'   => 'period',
            'pivotLabel' => 'Всього',
        ],
        'lines-by-month' => [
            'title'      => 'Кількість змінених рядків по розробникам за місяцями',
            'method'     => 'linesByMonth',
            'renderer'   => 'pivot',
            'metric'     => 'value',
            'pivotKey'   => 'period',
            'pivotLabel' => 'Всього',
        ],
        'reverts-full-period' => [
            'title'       => 'Кількість відкатів по розробникам за звітний період',
            'method'      => 'revertsFullPeriod',
            'renderer'    => 'simple',
            'metric'      => 'value',
            'metricLabel' => 'Відкатів',
        ],
        'reverts-by-year' => [
            'title'      => 'Кількість відкатів по розробникам за роками',
            'method'     => 'revertsByYear',
            'renderer'   => 'pivot',
            'metric'     => 'value',
            'pivotKey'   => 'period',
            'pivotLabel' => 'Всього',
        ],
        'reverts-by-month' => [
            'title'      => 'Кількість відкатів по розробникам за місяцями',
            'method'     => 'revertsByMonth',
            'renderer'   => 'pivot',
            'metric'     => 'value',
            'pivotKey'   => 'period',
            'pivotLabel' => 'Всього',
        ],
    ];

    /** Keys of individual reports (excluding full-report and 'all'). */
    public static function allKeys(): array
    {
        return array_keys(self::REPORTS);
    }

    /** Validate a --report value; returns canonical key or null. */
    public static function isValid(string $key): bool
    {
        return $key === self::FULL || $key === self::ALL || isset(self::REPORTS[$key]);
    }

    public static function get(string $key): array
    {
        if (!isset(self::REPORTS[$key])) {
            throw new InvalidArgumentException("Unknown report key: {$key}");
        }
        return self::REPORTS[$key];
    }
}
