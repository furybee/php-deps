<?php

declare(strict_types=1);

namespace DepsGraph\Architecture\Reporter;

use DepsGraph\Architecture\CheckResult;

/**
 * GitHub Actions workflow commands: violations show up as annotations on the pull request diff.
 */
final class GithubReporter implements Reporter
{
    public function report(CheckResult $result): string
    {
        $output = '';
        foreach ($result->newViolations as $violation) {
            $output .= sprintf(
                "::error file=%s,line=%d,title=%s::%s\n",
                $violation->file,
                $violation->line,
                self::escapeProperty("Architecture: {$violation->sourceLayer} -> {$violation->targetLayer}"),
                self::escapeData($violation->message()),
            );
        }

        return $output;
    }

    private static function escapeData(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private static function escapeProperty(string $value): string
    {
        return str_replace([':', ','], ['%3A', '%2C'], self::escapeData($value));
    }
}
