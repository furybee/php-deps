<?php

declare(strict_types=1);

namespace DepsGraph\Tests\Metrics;

use DepsGraph\Metrics\CycleDetector;
use DepsGraph\Metrics\DependencyMap;
use PHPUnit\Framework\TestCase;

final class DependencyMapTest extends TestCase
{
    public function testInlinesTraitDependenciesIntoTheirHostClasses(): void
    {
        $map = DependencyMap::fromGraph(GraphFactory::make(
            ['App\User' => 'class', 'App\HasRoles' => 'trait', 'App\Role' => 'class'],
            [['App\User', 'App\HasRoles', 'uses_trait'], ['App\HasRoles::roles', 'App\Role'], ['App\HasRoles::roles', 'App\User', 'references']],
        ));

        $this->assertSame(['App\Role' => true], $map->dependencies['App\User']);
        $this->assertSame([], (new CycleDetector())->components($map));
    }

    public function testGroupsClassesOnTheirNamespaceUpToTheGivenDepth(): void
    {
        $graph = GraphFactory::make(
            ['App\Billing\Domain\Invoice' => 'class', 'App\Billing\Infrastructure\Pdf' => 'class', 'App\Shipping\Parcel' => 'interface'],
            [['App\Billing\Domain\Invoice::render', 'App\Billing\Infrastructure\Pdf'], ['App\Billing\Infrastructure\Pdf', 'App\Shipping\Parcel']],
        );

        $full = DependencyMap::fromGraph($graph, 0);
        $twoSegments = DependencyMap::fromGraph($graph, 2);

        $this->assertSame(['App\Billing\Infrastructure' => true], $full->dependencies['App\Billing\Domain']);
        $this->assertSame(['App\Billing' => 2, 'App\Shipping' => 1], $twoSegments->sizes);
        $this->assertSame(['App\Shipping' => true], $twoSegments->dependencies['App\Billing']);
    }

    public function testKeepsTheFirstEdgeBehindEachDependency(): void
    {
        $map = DependencyMap::fromGraph(GraphFactory::make(
            ['A' => 'class', 'B' => 'class'],
            [['A::first', 'B::run'], ['A::second', 'B']],
        ));

        $this->assertSame(['source' => 'A::first', 'target' => 'B::run', 'type' => 'calls', 'line' => 1], $map->evidence('A', 'B'));
    }

    public function testLeavesOutClassesRejectedByTheFilter(): void
    {
        $map = DependencyMap::fromGraph(
            GraphFactory::make(['A' => 'class', 'Provider' => 'class'], [['Provider', 'A'], ['A', 'Provider']]),
            null,
            static fn(string $class) => $class !== 'Provider',
        );

        $this->assertSame(['A' => []], $map->dependencies);
    }
}
