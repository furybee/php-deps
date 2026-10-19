<?php

declare(strict_types=1);

namespace DepsGraph\Architecture\Reporter;

use DepsGraph\Architecture\CheckResult;

final class TextReporter implements Reporter
{
    public function report(CheckResult $result): string
    {
        $output = '';

        $byTitle = [];
        foreach ($result->newIssues as $issue) {
            $byTitle[$issue->title()][] = $issue;
        }
        ksort($byTitle);

        foreach ($byTitle as $title => $issues) {
            $output .= "\n{$title} (" . count($issues) . ")\n";
            foreach ($issues as $issue) {
                $output .= "  {$issue->file()}:{$issue->line()}\n    {$issue->summary()}\n";
            }
        }

        $output .= "\n" . count($result->newIssues) . " violation(s)";
        if ($result->baselinedCount > 0) {
            $output .= ", {$result->baselinedCount} ignored by the baseline";
        }
        $output .= "\n";

        if ($result->staleBaselineKeys !== []) {
            $output .= count($result->staleBaselineKeys) . " baseline entries no longer occur, run --generate-baseline to drop them\n";
        }

        return $output;
    }
}
