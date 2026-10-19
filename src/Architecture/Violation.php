<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

final class Violation implements Issue
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

    public function key(): string
    {
        return $this->sourceClass . ' -> ' . $this->targetClass;
    }

    public function title(): string
    {
        return "{$this->sourceLayer} -> {$this->targetLayer}";
    }

    public function summary(): string
    {
        return "{$this->sourceClass} -> {$this->targetClass} ({$this->edgeType})";
    }

    public function file(): string
    {
        return $this->file;
    }

    public function line(): int
    {
        return $this->line;
    }

    public function message(): string
    {
        return "{$this->sourceClass} ({$this->sourceLayer}) must not depend on {$this->targetClass} ({$this->targetLayer}), found: {$this->edgeType}";
    }
}
