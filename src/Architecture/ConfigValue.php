<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

/**
 * Validates values read from php-deps.php, whose content is user input.
 */
final class ConfigValue
{
    /**
     * @return list<string>
     */
    public static function stringList(mixed $value, string $key): array
    {
        $values = is_array($value) ? array_values($value) : [$value];
        foreach ($values as $item) {
            if (!is_string($item)) {
                throw new InvalidConfigException("\"{$key}\" must be a string or a list of strings");
            }
        }

        /** @var list<string> $values */
        return $values;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function listMap(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw new InvalidConfigException("\"{$key}\" must be an array of lists");
        }

        $map = [];
        foreach ($value as $name => $items) {
            $map[(string) $name] = self::stringList($items, "{$key}.{$name}");
        }

        return $map;
    }

    public static function string(mixed $value, string $key): string
    {
        if (!is_string($value)) {
            throw new InvalidConfigException("\"{$key}\" must be a string");
        }

        return $value;
    }

    public static function int(mixed $value, string $key): int
    {
        if (!is_int($value)) {
            throw new InvalidConfigException("\"{$key}\" must be an integer");
        }

        return $value;
    }
}
