<?php

declare(strict_types=1);

namespace DepsGraph;

/**
 * Builds the graph JSON structure. Only classes found in the scanned
 * directories become nodes: edges towards anything else are dropped.
 */
final class GraphBuilder
{
    private const CLASS_LEVEL_EDGES = [
        'extends' => 'extends',
        'implements' => 'implements',
        'traits' => 'uses_trait',
    ];

    /** @var array<string, true> */
    private array $edgeKeys = [];

    /** @var array<array{source: string, target: string, type: string}> */
    private array $graphEdges = [];

    /**
     * @param array<string, array<string, mixed>> $classes keyed by FQCN
     * @param array<array{source: string, target: string, type: string}> $rawEdges
     * @return array{nodes: array<mixed>, edges: array<mixed>, stats: array<string, mixed>}
     */
    public function build(array $classes, array $rawEdges, ClassHierarchy $hierarchy): array
    {
        $this->edgeKeys = [];
        $this->graphEdges = [];
        $nodes = [];

        foreach ($classes as $fqcn => $data) {
            $nodes[] = $this->classNode($fqcn, $data);
            foreach ($data['methods'] as $methodName => $methodData) {
                $nodes[] = $this->methodNode($fqcn, (string) $methodName, $methodData, $data['layer']);
            }

            foreach (self::CLASS_LEVEL_EDGES as $field => $type) {
                foreach ($data[$field] as $target) {
                    if ($hierarchy->has($target)) {
                        $this->addEdge($fqcn, $target, $type);
                    }
                }
            }

            foreach ($data['constructorParams'] as $paramType) {
                if ($paramType !== null && $paramType !== $fqcn && $hierarchy->has($paramType)) {
                    $this->addEdge($fqcn, $paramType, 'depends_on');
                }
            }
        }

        $collapsedCalls = 0;
        foreach ($rawEdges as $edge) {
            [$targetClass, $targetMethod] = array_pad(explode('::', $edge['target'], 2), 2, null);
            if (!$hierarchy->has($targetClass)) {
                continue;
            }

            if ($targetMethod === null) {
                $this->addEdge($edge['source'], $targetClass, $edge['type']);
                continue;
            }

            $declaring = $hierarchy->findMethod($targetClass, $targetMethod);
            if ($declaring === null) {
                $collapsedCalls++;
            }
            $target = $declaring === null ? $targetClass : $declaring[0] . '::' . $declaring[1];
            $this->addEdge($edge['source'], $target, $edge['type']);
        }

        return [
            'nodes' => $nodes,
            'edges' => $this->graphEdges,
            'stats' => $this->stats($classes, $nodes, $collapsedCalls),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function classNode(string $fqcn, array $data): array
    {
        return [
            'id' => $fqcn,
            'type' => 'class',
            'label' => $data['name'],
            'namespace' => $data['namespace'],
            'layer' => $data['layer'],
            'classType' => $data['type'],
            'file' => $data['file'] ?? null,
            'line' => $data['line'],
            'methods' => array_keys($data['methods']),
            'methodCount' => count($data['methods']),
        ];
    }

    /**
     * @param array<string, mixed> $methodData
     * @return array<string, mixed>
     */
    private function methodNode(string $fqcn, string $methodName, array $methodData, string $layer): array
    {
        return [
            'id' => $fqcn . '::' . $methodName,
            'type' => 'method',
            'label' => $methodName,
            'class' => $fqcn,
            'visibility' => $methodData['visibility'],
            'static' => $methodData['static'],
            'line' => $methodData['line'],
            'layer' => $layer,
        ];
    }

    private function addEdge(string $source, string $target, string $type): void
    {
        $key = $source . '|' . $type . '|' . $target;
        if (isset($this->edgeKeys[$key])) {
            return;
        }

        $this->edgeKeys[$key] = true;
        $this->graphEdges[] = ['source' => $source, 'target' => $target, 'type' => $type];
    }

    /**
     * @param array<string, array<string, mixed>> $classes
     * @param array<array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function stats(array $classes, array $nodes, int $collapsedCalls): array
    {
        $layerCounts = [];
        $classTypeCounts = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'class') {
                $layerCounts[$node['layer']] = ($layerCounts[$node['layer']] ?? 0) + 1;
                $classTypeCounts[$node['classType']] = ($classTypeCounts[$node['classType']] ?? 0) + 1;
            }
        }
        arsort($layerCounts);
        arsort($classTypeCounts);

        $edgeTypeCounts = [];
        foreach ($this->graphEdges as $edge) {
            $edgeTypeCounts[$edge['type']] = ($edgeTypeCounts[$edge['type']] ?? 0) + 1;
        }

        return [
            'totalClasses' => count($classes),
            'totalMethods' => array_sum(array_map(fn(array $c) => count($c['methods']), $classes)),
            'totalEdges' => count($this->graphEdges),
            'callsCollapsedToClass' => $collapsedCalls,
            'layerCounts' => $layerCounts,
            'edgeTypeCounts' => $edgeTypeCounts,
            'classTypeCounts' => $classTypeCounts,
        ];
    }
}
