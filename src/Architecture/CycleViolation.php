<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

final class CycleViolation implements Issue
{
    /**
     * @param string[] $members every class or namespace of the cycle, sorted
     * @param string[] $path shortest cycle through the first member, the last node depends on the first
     * @param array{source: string, target: string, type: string, line: int} $closingEdge edge from the last node of $path to the first
     */
    public function __construct(
        public readonly string $level,
        public readonly array $members,
        public readonly array $path,
        public readonly array $closingEdge,
        private readonly string $file,
    ) {
    }

    /**
     * A cycle that grows is a new issue: the baseline only accepts the cycle as it was.
     */
    public function key(): string
    {
        return "{$this->level} cycle: " . implode(', ', $this->members);
    }

    public function title(): string
    {
        return ucfirst($this->level) . ' cycles';
    }

    public function summary(): string
    {
        $size = count($this->members) > count($this->path) ? ' (' . count($this->members) . " {$this->level}s involved)" : '';

        return implode(' -> ', [...$this->path, $this->path[0]]) . $size;
    }

    public function message(): string
    {
        return "Dependency cycle between " . count($this->members) . " {$this->level}s: " . implode(' -> ', [...$this->path, $this->path[0]])
            . ". It closes at {$this->closingEdge['source']} -> {$this->closingEdge['target']} ({$this->closingEdge['type']})";
    }

    public function file(): string
    {
        return $this->file;
    }

    public function line(): int
    {
        return $this->closingEdge['line'];
    }
}
