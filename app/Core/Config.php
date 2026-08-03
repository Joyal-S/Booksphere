<?php

declare(strict_types=1);

namespace BookSphere\App\Core;

/**
 * Config
 *
 * Loads and holds the application configuration. Every file in
 * the config/ directory returns an array, and each file becomes
 * one top-level key:
 *
 *     config/app.php      -> the "app" group
 *     config/database.php -> the "database" group
 *
 * Values are read with dot notation:
 *
 *     $config->get('app.debug')        -> config/app.php  ['debug']
 *     $config->get('database.path')    -> config/database.php ['path']
 *
 * It exists so the rest of the application never touches a raw
 * array: one small class knows how the configuration is organized,
 * and every other class can ask for exactly the value it needs.
 */
final class Config
{
    /**
     * @param array<string, array<string, mixed>> $items All loaded config groups
     */
    private function __construct(private readonly array $items) {}

    /**
     * Load every PHP file inside a config directory.
     *
     * The file name becomes the group name, e.g. "app.php"
     * becomes the "app" group. Files are loaded in alphabetical
     * order, which is stable and predictable.
     */
    public static function loadFromDirectory(string $directory): self
    {
        $items = [];

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $group = basename($file, '.php');
            $items[$group] = require $file;
        }

        return new self($items);
    }

    /**
     * Read a configuration value using dot notation.
     *
     * @param string $key     e.g. "app.debug" or "database.path"
     * @param mixed  $default Returned when the key does not exist
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
