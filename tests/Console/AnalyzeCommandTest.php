<?php

declare(strict_types=1);

namespace DepsGraph\Tests\Console;

use DepsGraph\Console\AnalyzeCommand;
use PHPUnit\Framework\TestCase;

final class AnalyzeCommandTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../Fixtures/Architecture';

    private string $workDir;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/php-deps-' . bin2hex(random_bytes(4));
        mkdir($this->workDir);
        foreach (['php-deps.php', 'src'] as $entry) {
            exec('cp -R ' . escapeshellarg(self::FIXTURE . '/' . $entry) . ' ' . escapeshellarg($this->workDir));
        }
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workDir));
    }

    public function testFailsOnViolationsAndReportsThem(): void
    {
        $exitCode = $this->execute('--check', '--quiet');

        $this->assertSame(AnalyzeCommand::VIOLATIONS, $exitCode);
        $this->assertStringContainsString('Domain -> Infrastructure (1)', $this->stdout());
        $this->assertStringContainsString('src/Billing/Domain/Invoice.php:14', $this->stdout());
        $this->assertStringNotContainsString('Legacy', $this->stdout());
    }

    public function testPassesOnceViolationsAreBaselined(): void
    {
        $this->assertSame(AnalyzeCommand::SUCCESS, $this->execute('--generate-baseline', '--quiet'));

        $exitCode = $this->execute('--check', '--quiet');

        $this->assertSame(AnalyzeCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('0 violation(s), 1 ignored by the baseline', $this->stdout());
        $this->assertSame(
            ['Shop\Billing\Domain\Invoice -> Shop\Billing\Infrastructure\PdfRenderer'],
            json_decode((string) file_get_contents($this->workDir . '/baseline.json'), true),
        );
    }

    public function testReportsStaleBaselineEntries(): void
    {
        file_put_contents($this->workDir . '/baseline.json', json_encode(['Shop\Gone -> Shop\AlsoGone']));

        $this->execute('--check', '--quiet');

        $this->assertStringContainsString('1 baseline entries no longer occur', $this->stdout());
    }

    public function testWritesAGitlabCodeQualityReport(): void
    {
        $this->execute('--check', '--quiet', '--format=gitlab');

        $report = json_decode($this->stdout(), true);
        $this->assertSame('src/Billing/Domain/Invoice.php', $report[0]['location']['path']);
        $this->assertSame(14, $report[0]['location']['lines']['begin']);
    }

    public function testWritesGithubAnnotations(): void
    {
        $this->execute('--check', '--quiet', '--format=github');

        $this->assertStringStartsWith('::error file=src/Billing/Domain/Invoice.php,line=14,title=Architecture%3A Domain -> Infrastructure::', $this->stdout());
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->assertSame(AnalyzeCommand::INVALID_USAGE, $this->execute('--chek'));
    }

    public function testRequiresRulesToCheck(): void
    {
        unlink($this->workDir . '/php-deps.php');

        $this->assertSame(AnalyzeCommand::INVALID_USAGE, $this->execute('--check', '--quiet'));
    }

    public function testWritesTheGraphWhenNotChecking(): void
    {
        $exitCode = $this->execute('--quiet', '--output=graph.json');

        $this->assertSame(AnalyzeCommand::SUCCESS, $exitCode);
        $graph = json_decode((string) file_get_contents($this->workDir . '/graph.json'), true);
        $this->assertSame(4, $graph['stats']['totalClasses']);
    }

    private function execute(string ...$arguments): int
    {
        $command = new AnalyzeCommand($this->workDir . '/default-graph.json', $this->stdout, $this->stderr);

        return $command->run($arguments, $this->workDir);
    }

    private function stdout(): string
    {
        rewind($this->stdout);

        return (string) stream_get_contents($this->stdout);
    }
}
