<?php

declare(strict_types=1);

namespace DepsGraph\Tests\Metrics;

use DepsGraph\Metrics\DependencyMap;

/**
 * @phpstan-import-type MinimalGraph from DependencyMap
 */
final class GraphFactory
{
    /**
     * @param array<string, string> $classes FQCN => classType
     * @param list<array{0: string, 1: string, 2?: string}> $edges [source, target, type = calls]
     * @return MinimalGraph
     */
    public static function make(array $classes, array $edges): array
    {
        $nodes = [];
        foreach ($classes as $id => $classType) {
            $nodes[] = ['id' => $id, 'type' => 'class', 'classType' => $classType, 'file' => str_replace('\\', '/', $id) . '.php'];
        }

        return [
            'nodes' => $nodes,
            'edges' => array_map(
                static fn(array $edge, int $line) => ['source' => $edge[0], 'target' => $edge[1], 'type' => $edge[2] ?? 'calls', 'line' => $line + 1],
                $edges,
                array_keys($edges),
            ),
        ];
    }
}
