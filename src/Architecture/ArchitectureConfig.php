<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

final class ArchitectureConfig
{
    /**
     * @param array<string, string[]> $layers layer => FQCN glob patterns
     * @param array<string, string[]> $rules layer => layers it may depend on (besides itself)
     * @param string[] $paths
     * @param string[]|null $exclude
     */
    public function __construct(
        public readonly array $layers = [],
        public readonly array $rules = [],
        public readonly array $paths = [],
        public readonly ?array $exclude = null,
        public readonly ?string $baseline = null,
    ) {
        foreach ($rules as $layer => $allowed) {
            foreach ([$layer, ...$allowed] as $name) {
                if (!isset($layers[$name])) {
                    throw new InvalidConfigException("Rule references undefined layer \"{$name}\"");
                }
            }
        }
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new InvalidConfigException("Config file not found: {$file}");
        }

        $config = require $file;
        if (!is_array($config)) {
            throw new InvalidConfigException("{$file} must return an array");
        }

        $baseline = $config['baseline'] ?? null;
        if (is_string($baseline) && !str_starts_with($baseline, '/')) {
            $baseline = dirname($file) . '/' . $baseline;
        }

        return new self(
            layers: self::listMap($config['layers'] ?? [], 'layers'),
            rules: self::listMap($config['rules'] ?? [], 'rules'),
            paths: array_values((array) ($config['paths'] ?? [])),
            exclude: isset($config['exclude']) ? array_values((array) $config['exclude']) : null,
            baseline: is_string($baseline) ? $baseline : null,
        );
    }

    public function hasRules(): bool
    {
        return $this->rules !== [];
    }

    /**
     * @return array<string, string[]>
     */
    private static function listMap(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw new InvalidConfigException("\"{$key}\" must be an array of lists");
        }

        $map = [];
        foreach ($value as $name => $items) {
            $map[(string) $name] = array_values(array_map('strval', (array) $items));
        }

        return $map;
    }
}
