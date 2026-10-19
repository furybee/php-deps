<?php

declare(strict_types=1);

namespace DepsGraph\Architecture\Reporter;

use DepsGraph\Architecture\CheckResult;
use DepsGraph\Architecture\Issue;

/**
 * GitLab Code Quality report, to declare as `artifacts:reports:codequality`.
 */
final class GitlabReporter implements Reporter
{
    public function report(CheckResult $result): string
    {
        $issues = array_map(static fn(Issue $issue) => [
            'description' => $issue->message(),
            'check_name' => 'php-deps.architecture',
            'fingerprint' => md5($issue->key()),
            'severity' => 'major',
            'location' => [
                'path' => $issue->file(),
                'lines' => ['begin' => $issue->line()],
            ],
        ], $result->newIssues);

        return json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
