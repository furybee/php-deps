<?php

declare(strict_types=1);

namespace DepsGraph;

/**
 * The class being traversed. A null class means an anonymous class:
 * its members must not leak into the enclosing named class.
 */
final class ClassScope
{
    public function __construct(
        public readonly ?string $class,
        public readonly ?string $parent = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null);
    }

    public function isAnonymous(): bool
    {
        return $this->class === null;
    }
}
