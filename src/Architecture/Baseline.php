<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

/**
 * Known issues, accepted until fixed.
 */
final class Baseline
{
    /**
     * @param array<string, true> $keys
     */
    private function __construct(private readonly array $keys)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            return self::empty();
        }

        $keys = json_decode((string) file_get_contents($file), true);
        if (!is_array($keys)) {
            throw new InvalidConfigException("Invalid baseline file: {$file}");
        }

        return new self(array_fill_keys(ConfigValue::stringList($keys, 'baseline'), true));
    }

    /**
     * @param Issue[] $issues
     */
    public static function write(string $file, array $issues): void
    {
        $keys = array_values(array_unique(array_map(static fn(Issue $issue) => $issue->key(), $issues)));
        sort($keys);

        file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public function contains(Issue $issue): bool
    {
        return isset($this->keys[$issue->key()]);
    }

    /**
     * Baselined issues that no longer occur: the baseline can shrink.
     *
     * @param Issue[] $issues
     * @return string[]
     */
    public function staleKeys(array $issues): array
    {
        $current = array_fill_keys(array_map(static fn(Issue $issue) => $issue->key(), $issues), true);

        return array_keys(array_diff_key($this->keys, $current));
    }
}
