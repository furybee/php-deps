<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

final class Violation
{
    public function __construct(
        public readonly string $sourceClass,
        public readonly string $targetClass,
        public readonly string $sourceLayer,
        public readonly string $targetLayer,
        public readonly string $edgeType,
        public readonly string $file,
        public readonly int $line,
    ) {
    }

    /**
     * Stable across unrelated edits: no line number.
     */
    public function key(): string
    {
        return $this->sourceClass . ' -> ' . $this->targetClass;
    }

    public function message(): string
    {
        return "{$this->sourceClass} ({$this->sourceLayer}) must not depend on {$this->targetClass} ({$this->targetLayer}), found: {$this->edgeType}";
    }
}
