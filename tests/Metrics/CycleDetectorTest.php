<?php

declare(strict_types=1);

namespace DepsGraph\Tests\Metrics;

use DepsGraph\Metrics\CycleDetector;
use DepsGraph\Metrics\DependencyMap;
use PHPUnit\Framework\TestCase;

final class CycleDetectorTest extends TestCase
{
    public function testFindsEachStronglyConnectedComponentLargestFirst(): void
    {
        $map = DependencyMap::fromGraph(GraphFactory::make(
            ['A' => 'class', 'B' => 'class', 'C' => 'class', 'D' => 'class', 'E' => 'class', 'F' => 'class'],
            [['A', 'B'], ['B', 'A'], ['C', 'D'], ['D', 'E'], ['E', 'C'], ['E', 'F']],
        ));

        $components = (new CycleDetector())->components($map);

        $this->assertSame([['C', 'D', 'E'], ['A', 'B']], $components);
    }

    public function testFindsNoCycleInADependencyChain(): void
    {
        $map = DependencyMap::fromGraph(GraphFactory::make(
            ['A' => 'class', 'B' => 'class', 'C' => 'class'],
            [['A', 'B'], ['B', 'C'], ['A', 'C']],
        ));

        $this->assertSame([], (new CycleDetector())->components($map));
    }

    public function testHandlesLongChainsWithoutRecursion(): void
    {
        $classes = [];
        $edges = [];
        for ($i = 0; $i < 20000; $i++) {
            $classes["C{$i}"] = 'class';
            $edges[] = ["C{$i}", 'C' . ($i + 1)];
        }
        $classes['C20000'] = 'class';
        $edges[] = ['C20000', 'C0'];

        $components = (new CycleDetector())->components(DependencyMap::fromGraph(GraphFactory::make($classes, $edges)));

        $this->assertCount(1, $components);
        $this->assertCount(20001, $components[0]);
    }

    public function testReturnsTheShortestCycleThroughANode(): void
    {
        $map = DependencyMap::fromGraph(GraphFactory::make(
            ['A' => 'class', 'B' => 'class', 'C' => 'class', 'D' => 'class'],
            [['A', 'B'], ['B', 'C'], ['C', 'D'], ['D', 'A'], ['B', 'A']],
        ));

        $this->assertSame(['A', 'B'], (new CycleDetector())->shortestCycle($map, ['A', 'B', 'C', 'D'], 'A'));
    }
}
