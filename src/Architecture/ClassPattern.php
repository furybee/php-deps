<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

/**
 * FQCN globs: `*` matches within one namespace segment, `**` across segments.
 */
final class ClassPattern
{
    private readonly string $regex;

    /**
     * @param string[] $patterns matching any of them is enough
     */
    public function __construct(array $patterns)
    {
        $alternatives = array_map(self::globToRegex(...), $patterns);
        $this->regex = $alternatives === [] ? '/(?!)/' : '/^(?:' . implode('|', $alternatives) . ')$/';
    }

    public function matches(string $class): bool
    {
        return preg_match($this->regex, $class) === 1;
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
