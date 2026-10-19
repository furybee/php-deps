<?php

declare(strict_types=1);

namespace DepsGraph\Tests\Architecture;

use DepsGraph\Architecture\ArchitectureConfig;
use DepsGraph\Architecture\DependencyRuleChecker;
use DepsGraph\Architecture\InvalidConfigException;
use DepsGraph\Architecture\Violation;
use PHPUnit\Framework\TestCase;

final class DependencyRuleCheckerTest extends TestCase
{
    private const CONFIG_LAYERS = [
        'Domain' => ['App\Domain\**'],
        'Infrastructure' => ['App\Infrastructure\**'],
        'Presentation' => ['App\Presentation\**'],
    ];

    public function testReportsDependenciesOnLayersNotAllowed(): void
    {
        $violations = $this->check([['App\Domain\Order::total', 'App\Infrastructure\Db::query', 'calls', 12]]);

        $this->assertEquals(
            [new Violation('App\Domain\Order', 'App\Infrastructure\Db', 'Domain', 'Infrastructure', 'calls', 'src/Domain/Order.php', 12)],
            $violations,
        );
    }

    public function testAllowsSameLayerListedLayersAndUnconstrainedLayers(): void
    {
        $violations = $this->check([
            ['App\Domain\Order', 'App\Domain\Line', 'depends_on', 1],
            ['App\Infrastructure\Db', 'App\Domain\Order', 'uses_type', 1],
            ['App\Presentation\OrderController', 'App\Infrastructure\Db', 'calls', 1],
        ]);

        $this->assertSame([], $violations);
    }

    public function testIgnoresClassesOutsideEveryLayer(): void
    {
        $this->assertSame([], $this->check([['App\Domain\Order', 'App\Support\Str', 'calls', 1]]));
    }

    public function testKeepsTheFirstOccurrenceOfEachClassPair(): void
    {
        $violations = $this->check([
            ['App\Domain\Order::a', 'App\Infrastructure\Db', 'calls', 30],
            ['App\Domain\Order::b', 'App\Infrastructure\Db', 'references', 8],
        ]);

        $this->assertCount(1, $violations);
        $this->assertSame(8, $violations[0]->line);
    }

    public function testRejectsRulesOnUndefinedLayers(): void
    {
        $this->expectException(InvalidConfigException::class);

        new ArchitectureConfig(self::CONFIG_LAYERS, ['Domain' => ['Persistence']]);
    }

    /**
     * @param array<array{string, string, string, int}> $edges
     * @return Violation[]
     */
    private function check(array $edges): array
    {
        $config = new ArchitectureConfig(self::CONFIG_LAYERS, ['Domain' => [], 'Infrastructure' => ['Domain']]);
        $graph = [
            'nodes' => [['id' => 'App\Domain\Order', 'type' => 'class', 'file' => 'src/Domain/Order.php']],
            'edges' => array_map(
                fn(array $edge) => ['source' => $edge[0], 'target' => $edge[1], 'type' => $edge[2], 'line' => $edge[3]],
                $edges,
            ),
        ];

        return (new DependencyRuleChecker($config))->check($graph);
    }
}
