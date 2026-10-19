<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

use DepsGraph\Metrics\CycleDetector;
use DepsGraph\Metrics\DependencyMap;

final class CycleRuleChecker
{
    public function __construct(
        private readonly CycleRule $rule,
        private readonly CycleDetector $detector = new CycleDetector(),
    ) {
    }

    /**
     * @param array{nodes: array<array<string, mixed>>, edges: array<array{source: string, target: string, type: string, line?: int}>} $graph
     * @return CycleViolation[]
     */
    public function check(array $graph): array
    {
        $scope = new ClassPattern($this->rule->scope);
        $ignored = new ClassPattern($this->rule->ignore);
        $map = DependencyMap::fromGraph(
            $graph,
            $this->rule->namespaceDepth(),
            static fn(string $class) => $scope->matches($class) && !$ignored->matches($class),
        );

        $files = [];
        foreach ($graph['nodes'] as $node) {
            if ($node['type'] === 'class') {
                $files[$node['id']] = (string) ($node['file'] ?? '');
            }
        }

        $violations = [];
        foreach ($this->detector->components($map) as $members) {
            $path = $this->detector->shortestCycle($map, $members, $members[0]);
            $closingEdge = $map->evidence((string) end($path), $path[0]);
            if ($closingEdge === null) {
                continue;
            }

            $sourceClass = explode('::', $closingEdge['source'], 2)[0];
            $violations[] = new CycleViolation($this->rule->level, $members, $path, $closingEdge, $files[$sourceClass] ?? '');
        }

        return $violations;
    }
}
