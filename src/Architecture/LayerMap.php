<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

/**
 * Assigns classes to the configured layers. The first matching layer wins.
 * Patterns are FQCN globs: `*` matches within one namespace segment, `**` across segments.
 */
final class LayerMap
{
    /** @var array<string, string> layer => regex */
    private array $regexes = [];

    /** @var array<string, string|null> */
    private array $cache = [];

    /**
     * @param array<string, string[]> $layers
     */
    public function __construct(array $layers)
    {
        foreach ($layers as $layer => $patterns) {
            $alternatives = array_map(self::globToRegex(...), $patterns);
            $this->regexes[$layer] = '/^(?:' . implode('|', $alternatives) . ')$/';
        }
    }

    public function layerOf(string $class): ?string
    {
        if (array_key_exists($class, $this->cache)) {
            return $this->cache[$class];
        }

        foreach ($this->regexes as $layer => $regex) {
            if (preg_match($regex, $class) === 1) {
                return $this->cache[$class] = $layer;
            }
        }

        return $this->cache[$class] = null;
    }

    private static function globToRegex(string $pattern): string
    {
        $parts = preg_split('/(\*\*|\*)/', ltrim($pattern, '\\'), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(
            static fn(string $part) => match ($part) {
                '**' => '.*',
                '*' => '[^\\\\]*',
                default => preg_quote($part, '/'),
            },
            $parts,
        ));
    }
}
