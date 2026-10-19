<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

final class CheckResult
{
    /**
     * @param Violation[] $newViolations
     * @param string[] $staleBaselineKeys
     */
    public function __construct(
        public readonly array $newViolations,
        public readonly int $baselinedCount,
        public readonly array $staleBaselineKeys,
    ) {
    }

    /**
     * @param Violation[] $violations
     */
    public static function from(array $violations, Baseline $baseline): self
    {
        $new = array_values(array_filter($violations, static fn(Violation $v) => !$baseline->contains($v)));

        return new self($new, count($violations) - count($new), $baseline->staleKeys($violations));
    }

    public function isSuccessful(): bool
    {
        return $this->newViolations === [];
    }
}
