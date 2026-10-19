<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

final class CheckResult
{
    /**
     * @param Issue[] $newIssues
     * @param string[] $staleBaselineKeys
     */
    public function __construct(
        public readonly array $newIssues,
        public readonly int $baselinedCount,
        public readonly array $staleBaselineKeys,
    ) {
    }

    /**
     * @param Issue[] $issues
     */
    public static function from(array $issues, Baseline $baseline): self
    {
        $new = array_values(array_filter($issues, static fn(Issue $issue) => !$baseline->contains($issue)));

        return new self($new, count($issues) - count($new), $baseline->staleKeys($issues));
    }

    public function isSuccessful(): bool
    {
        return $this->newIssues === [];
    }
}
