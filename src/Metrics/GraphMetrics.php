<?php

declare(strict_types=1);

namespace DepsGraph\Metrics;

/**
 * Adds coupling metrics and cycles to a built graph:
 * - class nodes get `metrics` {ca, ce, instability} and `cycle`, the index of their class cycle or null
 * - `cycles` lists class cycles, largest first, with a shortest path through each
 * - `namespaces` lists namespace metrics and namespace cycles
 */
final class GraphMetrics
{
    public function __construct(
        private readonly CycleDetector $detector = new CycleDetector(),
        private readonly CouplingMetrics $coupling = new CouplingMetrics(),
    ) {
    }

    /**
     * @param array{nodes: array<array<string, mixed>>, edges: array<mixed>, stats: array<string, mixed>} $graph
     * @return array{nodes: array<array<string, mixed>>, edges: array<mixed>, stats: array<string, mixed>, cycles: array<mixed>, namespaces: array<mixed>, namespaceCycles: array<mixed>}
     */
    public function enrich(array $graph, int $namespaceDepth = 0): array
    {
        $classMap = DependencyMap::fromGraph($graph);
        $classMetrics = $this->coupling->compute($classMap);
        $classCycles = $this->cycles($classMap);

        $cycleOf = [];
        foreach ($classCycles as $index => $cycle) {
            foreach ($cycle['members'] as $member) {
                $cycleOf[$member] = $index;
            }
        }

        foreach ($graph['nodes'] as &$node) {
            if ($node['type'] !== 'class') {
                continue;
            }
            $metrics = $classMetrics[$node['id']];
            $node['metrics'] = ['ca' => $metrics['ca'], 'ce' => $metrics['ce'], 'instability' => $metrics['instability']];
            $node['cycle'] = $cycleOf[$node['id']] ?? null;
        }
        unset($node);

        $namespaceMap = DependencyMap::fromGraph($graph, $namespaceDepth);
        $namespaces = [];
        foreach ($this->coupling->compute($namespaceMap) as $namespace => $metrics) {
            $namespaces[] = ['id' => $namespace] + $metrics;
        }
        $namespaceCycles = $this->cycles($namespaceMap);

        $graph['cycles'] = $classCycles;
        $graph['namespaces'] = $namespaces;
        $graph['namespaceCycles'] = $namespaceCycles;
        $graph['stats']['classCycles'] = count($classCycles);
        $graph['stats']['namespaceCycles'] = count($namespaceCycles);

        return $graph;
    }

    /**
     * @return array<array{members: string[], path: string[]}>
     */
    private function cycles(DependencyMap $map): array
    {
        return array_map(
            fn(array $members) => ['members' => $members, 'path' => $this->detector->shortestCycle($map, $members, $members[0])],
            $this->detector->components($map),
        );
    }
}
