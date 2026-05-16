<?php
declare(strict_types=1);

/**
 * Static configuration loader with dot-notation access.
 * Standalone class — does not depend on the host project / parent framework.
 */
class Config
{
    private static array $data = [];

    public static function load(string $path): void
    {
        self::$data = require $path;
    }

    /**
     * Get config value by dot-notation key, e.g. Config::get('db.host').
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key);
        $value = self::$data;

        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}

