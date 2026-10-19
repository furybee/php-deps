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
        public readonly ?CycleRule $cycles = null,
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
            layers: ConfigValue::listMap($config['layers'] ?? [], 'layers'),
            rules: ConfigValue::listMap($config['rules'] ?? [], 'rules'),
            paths: ConfigValue::stringList($config['paths'] ?? [], 'paths'),
            exclude: isset($config['exclude']) ? ConfigValue::stringList($config['exclude'], 'exclude') : null,
            baseline: is_string($baseline) ? $baseline : null,
            cycles: self::cycleRule($config['cycles'] ?? null),
        );
    }

    public function hasRules(): bool
    {
        return $this->rules !== [] || $this->cycles !== null;
    }

    private static function cycleRule(mixed $value): ?CycleRule
    {
        return match (true) {
            $value === null, $value === false => null,
            $value === true => new CycleRule(),
            is_array($value) => CycleRule::fromArray($value),
            default => throw new InvalidConfigException('"cycles" must be a boolean or an array'),
        };
    }
}
