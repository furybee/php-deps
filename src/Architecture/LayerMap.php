<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

/**
 * Assigns classes to the configured layers. The first matching layer wins.
 */
final class LayerMap
{
    /** @var array<string, ClassPattern> */
    private array $patterns = [];

    /** @var array<string, string|null> */
    private array $cache = [];

    /**
     * @param array<string, string[]> $layers layer => FQCN globs
     */
    public function __construct(array $layers)
    {
        foreach ($layers as $layer => $patterns) {
            $this->patterns[$layer] = new ClassPattern($patterns);
        }
    }

    public function layerOf(string $class): ?string
    {
        if (array_key_exists($class, $this->cache)) {
            return $this->cache[$class];
        }

        foreach ($this->patterns as $layer => $pattern) {
            if ($pattern->matches($class)) {
                return $this->cache[$class] = $layer;
            }
        }

        return $this->cache[$class] = null;
    }
}
