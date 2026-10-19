<?php

declare(strict_types=1);

namespace DepsGraph\Architecture\Reporter;

use DepsGraph\Architecture\CheckResult;

final class TextReporter implements Reporter
{
    public function report(CheckResult $result): string
    {
        $output = '';

        $byLayers = [];
        foreach ($result->newViolations as $violation) {
            $byLayers["{$violation->sourceLayer} -> {$violation->targetLayer}"][] = $violation;
        }
        ksort($byLayers);

        foreach ($byLayers as $layers => $violations) {
            $output .= "\n{$layers} (" . count($violations) . ")\n";
            foreach ($violations as $violation) {
                $output .= "  {$violation->file}:{$violation->line}\n"
                    . "    {$violation->sourceClass} -> {$violation->targetClass} ({$violation->edgeType})\n";
            }
        }

        $output .= "\n" . count($result->newViolations) . " violation(s)";
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
