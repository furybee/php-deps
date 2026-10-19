<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

final class CycleRule
{
    public const LEVEL_CLASS = 'class';
    public const LEVEL_NAMESPACE = 'namespace';

    /**
     * @param int $depth namespace level only: first N segments, 0 for the full namespace
     * @param string[] $scope FQCN globs of the classes checked
     * @param string[] $ignore FQCN globs of classes left out, typically composition roots such as service providers
     */
    public function __construct(
        public readonly string $level = self::LEVEL_NAMESPACE,
        public readonly int $depth = 0,
        public readonly array $scope = ['**'],
        public readonly array $ignore = [],
    ) {
        if (!in_array($level, [self::LEVEL_CLASS, self::LEVEL_NAMESPACE], true)) {
            throw new InvalidConfigException("cycles.level must be \"class\" or \"namespace\", got \"{$level}\"");
        }
        if ($depth < 0) {
            throw new InvalidConfigException('cycles.depth must be 0 or more');
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            level: (string) ($config['level'] ?? self::LEVEL_NAMESPACE),
            depth: (int) ($config['depth'] ?? 0),
            scope: array_values(array_map('strval', (array) ($config['scope'] ?? ['**']))),
            ignore: array_values(array_map('strval', (array) ($config['ignore'] ?? []))),
        );
    }

    public function namespaceDepth(): ?int
    {
        return $this->level === self::LEVEL_CLASS ? null : $this->depth;
    }
}
