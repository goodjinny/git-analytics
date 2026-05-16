<?php
declare(strict_types=1);

/**
 * Simple logger: writes to stdout and optionally to a file.
 *
 * Format: [YYYY-MM-DD HH:MM:SS] [LEVEL] message
 */
class Logger
{
    private static ?string $logFile = null;

    public static function init(string $outputPath): void
    {
        $logDir = $outputPath . '/logs';
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            // Could not create dir — log to stdout only
            return;
        }
        self::$logFile = $logDir . '/import_' . date('Ymd_His') . '.log';
    }

    public static function info(string $message): void
    {
        self::write('INFO', $message);
    }

    public static function warning(string $message): void
    {
        self::write('WARN', $message);
    }

    public static function error(string $message): void
    {
        self::write('ERROR', $message);
    }

    private static function write(string $level, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message;
        echo $line . PHP_EOL;

        if (self::$logFile !== null) {
            file_put_contents(self::$logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

