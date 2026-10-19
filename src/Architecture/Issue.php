<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

interface Issue
{
    /**
     * Identifies the issue in the baseline: must stay stable across unrelated edits, so no line number.
     */
    public function key(): string;

    /**
     * Groups issues of the same kind, e.g. "Domain -> Infrastructure".
     */
    public function title(): string;

    public function summary(): string;

    public function message(): string;

    public function file(): string;

    public function line(): int;
}
