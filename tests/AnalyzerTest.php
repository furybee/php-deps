<?php

declare(strict_types=1);

namespace DepsGraph\Tests;

use DepsGraph\Analyzer;
use DepsGraph\GraphBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Graph from GraphBuilder
 * @phpstan-import-type ClassNode from GraphBuilder
 */
final class AnalyzerTest extends TestCase
{
    private const SERVICE = 'Acme\Services\UserService';

    /** @var Graph */
    private static array $graph;

    public static function setUpBeforeClass(): void
    {
        self::$graph = (new Analyzer(__DIR__ . '/Fixtures/Basic'))->analyze();
    }

    public function testResolvesUseStatementsGroupUsesAndFullyQualifiedNames(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Contracts\Repository::find', 'calls');
        $this->assertNoEdgeTo('RuntimeException');
        $this->assertNodeMissing('Acme\Services\RuntimeException');
    }

    public function testKeepsEdgesBetweenScannedClassesWhateverTheirNamespace(): void
    {
        $this->assertEdge(self::SERVICE, 'Acme\Contracts\Repository', 'depends_on');
        $this->assertEdge(self::SERVICE, 'Acme\Services\Mailer', 'depends_on');
        $this->assertEdge('Acme\Models\User', 'Acme\Models\Model', 'extends');
        $this->assertEdge('Acme\Models\User', 'Acme\Models\HasProfile', 'uses_trait');
    }

    public function testDropsEdgesTowardsClassesOutsideTheScannedDirectories(): void
    {
        $this->assertNoEdgeTo('Illuminate\Database\Eloquent\Model');
    }

    public function testResolvesParentCallsToTheParentClass(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Services\BaseService::boot', 'calls');
    }

    public function testPointsInheritedAndTraitMethodsToTheirDeclaringClass(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Models\HasProfile::profile', 'calls');
    }

    public function testCollapsesUndeclaredMethodsToTheClassNode(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Models\User', 'calls');
        $this->assertGreaterThan(0, self::$graph['stats']['callsCollapsedToClass']);
    }

    public function testFollowsStaticReturnTypesAsTheCallingClass(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Models\User::notify', 'calls');
    }

    public function testInfersTypesFromNullsafeCallsDocblocksAndPropertyChains(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Services\Mailer::send', 'calls');
        $this->assertEdge(self::SERVICE . '::documented', 'Acme\Models\User::notify', 'calls');
        $this->assertEdge(self::SERVICE . '::documented', 'Acme\Models\HasProfile::profile', 'calls');
    }

    public function testDetectsContainerResolutions(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Services\Mailer', 'resolves');
    }

    public function testKeepsAnonymousClassesOutOfTheEnclosingClass(): void
    {
        $methods = $this->classNode(self::SERVICE)['methods'];

        $this->assertSame(['__construct', 'handle', 'documented', 'afterAnonymousClass'], $methods);
        $this->assertEdge(self::SERVICE . '::handle', self::SERVICE . '::afterAnonymousClass', 'calls');
        $this->assertEdge(self::SERVICE . '::afterAnonymousClass', 'Acme\Models\User', 'instantiates');
    }

    public function testRecordsClassReferencesThatAreNotCalls(): void
    {
        $this->assertEdge('Acme\Services\Notifier::notify', 'Acme\Services\MailerException', 'references');
        $this->assertEdge('Acme\Services\Notifier::notify', 'Acme\Models\Status', 'references');
    }

    public function testRecordsTypesUsedOnlyInSignatures(): void
    {
        $this->assertEdge('Acme\Contracts\Repository::report', 'Acme\Models\Report', 'uses_type');
    }

    public function testSkipsWeakEdgesBetweenClassesAlreadyLinked(): void
    {
        $this->assertEdge(self::SERVICE . '::handle', 'Acme\Services\Mailer', 'resolves');
        $this->assertNotContains(
            [self::SERVICE . '::handle', 'Acme\Services\Mailer', 'references'],
            array_map(fn(array $edge) => [$edge['source'], $edge['target'], $edge['type']], self::$graph['edges']),
        );
        $this->assertNotContains(
            ['Acme\Services\Notifier::notify', 'Acme\Services\Mailer', 'uses_type'],
            array_map(fn(array $edge) => [$edge['source'], $edge['target'], $edge['type']], self::$graph['edges']),
        );
    }

    public function testRecordsTheLineOfEachEdge(): void
    {
        $lines = [];
        foreach (self::$graph['edges'] as $edge) {
            $lines[$edge['source'] . '>' . $edge['target']] = $edge['line'];
        }

        $this->assertSame(20, $lines[self::SERVICE . '::handle>Acme\Contracts\Repository::find']);
    }

    public function testNeverEmitsEdgesTowardsUnknownNodes(): void
    {
        $ids = array_flip(array_column(self::$graph['nodes'], 'id'));

        foreach (self::$graph['edges'] as $edge) {
            $this->assertArrayHasKey($edge['source'], $ids, "Unknown source {$edge['source']}");
            $this->assertArrayHasKey($edge['target'], $ids, "Unknown target {$edge['target']}");
        }
    }

    public function testExcludesBareNamesAtAnyDepthAndPathsFromTheRoot(): void
    {
        $graph = (new Analyzer(__DIR__ . '/Fixtures/Excluded', ['.'], ['vendor', 'src/cache']))->analyze();

        $this->assertSame(['Excluded\Kept'], array_column($graph['nodes'], 'id'));
    }

    private function assertEdge(string $source, string $target, string $type): void
    {
        $this->assertContains(
            ['source' => $source, 'target' => $target, 'type' => $type],
            array_map(fn(array $edge) => array_diff_key($edge, ['line' => true]), self::$graph['edges']),
            "Missing {$type} edge {$source} -> {$target}",
        );
    }

    private function assertNoEdgeTo(string $target): void
    {
        $this->assertNotContains($target, array_column(self::$graph['edges'], 'target'));
    }

    private function assertNodeMissing(string $id): void
    {
        $this->assertNotContains($id, array_column(self::$graph['nodes'], 'id'));
    }

    /**
     * @return ClassNode
     */
    private function classNode(string $id): array
    {
        foreach (self::$graph['nodes'] as $node) {
            if ($node['type'] === 'class' && $node['id'] === $id) {
                return $node;
            }
        }

        $this->fail("Class node {$id} not found");
    }
}
