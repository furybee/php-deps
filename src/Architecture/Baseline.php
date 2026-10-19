<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

/**
 * Known violations, accepted until fixed. Keyed by class pair so that moving code around does not resurface them.
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

        return new self(array_fill_keys(array_map('strval', $keys), true));
    }

    /**
     * @param Violation[] $violations
     */
    public static function write(string $file, array $violations): void
    {
        $keys = array_values(array_unique(array_map(static fn(Violation $v) => $v->key(), $violations)));
        sort($keys);

        file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    public function contains(Violation $violation): bool
    {
        return isset($this->keys[$violation->key()]);
    }

    /**
     * Baselined violations that no longer occur: the baseline can shrink.
     *
     * @param Violation[] $violations
     * @return string[]
     */
    public function staleKeys(array $violations): array
    {
        $current = array_fill_keys(array_map(static fn(Violation $v) => $v->key(), $violations), true);

        return array_keys(array_diff_key($this->keys, $current));
    }
}
