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

    /**
     * Only emitted when no other edge already links the two classes,
     * otherwise every `Foo::class` next to `new Foo()` would double the edge.
     */
    private const WEAK_EDGE_TYPES = ['references', 'uses_type'];

    /** @var array<string, true> */
    private array $edgeKeys = [];

    /** @var array<string, true> "SourceClass|TargetClass" */
    private array $linkedClasses = [];

    /** @var array<array{source: string, target: string, type: string, line: int}> */
    private array $graphEdges = [];

    /**
     * @param array<string, array<string, mixed>> $classes keyed by FQCN
     * @param array<array{source: string, target: string, type: string, line: int}> $rawEdges
     * @return array{nodes: array<mixed>, edges: array<mixed>, stats: array<string, mixed>}
     */
    public function build(array $classes, array $rawEdges, ClassHierarchy $hierarchy): array
    {
        $this->edgeKeys = [];
        $this->linkedClasses = [];
        $this->graphEdges = [];
        $nodes = [];

        foreach ($classes as $fqcn => $data) {
            $nodes[] = $this->classNode($fqcn, $data);
            foreach ($data['methods'] as $methodName => $methodData) {
                $nodes[] = $this->methodNode($fqcn, (string) $methodName, $methodData, $data['layer']);
            }
            $this->addClassLevelEdges($fqcn, $data, $hierarchy);
        }

        $collapsedCalls = 0;
        $weakEdges = $this->signatureEdges($classes);
        foreach ($rawEdges as $edge) {
            if (in_array($edge['type'], self::WEAK_EDGE_TYPES, true)) {
                $weakEdges[] = $edge;
                continue;
            }

            $target = $this->resolveTarget($edge['target'], $hierarchy, $collapsedCalls);
            if ($target !== null) {
                $this->addEdge($edge['source'], $target, $edge['type'], $edge['line']);
            }
        }

        foreach ($weakEdges as $edge) {
            $target = $this->resolveTarget($edge['target'], $hierarchy, $collapsedCalls);
            $pair = self::classOf($edge['source']) . '|' . ($target === null ? '' : self::classOf($target));
            if ($target !== null && !isset($this->linkedClasses[$pair]) && self::classOf($edge['source']) !== self::classOf($target)) {
                $this->addEdge($edge['source'], $target, $edge['type'], $edge['line']);
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $this->graphEdges,
            'stats' => $this->stats($classes, $nodes, $collapsedCalls),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function addClassLevelEdges(string $fqcn, array $data, ClassHierarchy $hierarchy): void
    {
        foreach (self::CLASS_LEVEL_EDGES as $field => $type) {
            foreach ($data[$field] as $target) {
                if ($hierarchy->has($target)) {
                    $this->addEdge($fqcn, $target, $type, $data['line']);
                }
            }
        }

        $constructorLine = $data['methods']['__construct']['line'] ?? $data['line'];
        foreach ($data['constructorParams'] as $paramType) {
            if ($paramType !== null && $paramType !== $fqcn && $hierarchy->has($paramType)) {
                $this->addEdge($fqcn, $paramType, 'depends_on', $constructorLine);
            }
        }
    }

    /**
     * Types used in method signatures and property declarations.
     *
     * @param array<string, array<string, mixed>> $classes
     * @return array<array{source: string, target: string, type: string, line: int}>
     */
    private function signatureEdges(array $classes): array
    {
        $edges = [];
        foreach ($classes as $fqcn => $data) {
            foreach ($data['properties'] as $type) {
                $edges[] = ['source' => $fqcn, 'target' => $type, 'type' => 'uses_type', 'line' => $data['line']];
            }

            foreach ($data['methods'] as $methodName => $method) {
                $types = array_filter([...array_values($method['params']), $method['returnType']]);
                foreach ($types as $type) {
                    $edges[] = ['source' => $fqcn . '::' . $methodName, 'target' => $type, 'type' => 'uses_type', 'line' => $method['line']];
                }
            }
        }

        return $edges;
    }

    /**
     * Inherited methods point to their declaring class; methods declared nowhere
     * in the scanned code (magic, vendor parent) collapse onto the class node.
     */
    private function resolveTarget(string $target, ClassHierarchy $hierarchy, int &$collapsedCalls): ?string
    {
        [$targetClass, $targetMethod] = array_pad(explode('::', $target, 2), 2, null);
        if (!$hierarchy->has($targetClass)) {
            return null;
        }

        if ($targetMethod === null) {
            return $targetClass;
        }

        $declaring = $hierarchy->findMethod($targetClass, $targetMethod);
        if ($declaring === null) {
            $collapsedCalls++;
            return $targetClass;
        }

        return $declaring[0] . '::' . $declaring[1];
    }

    private static function classOf(string $nodeId): string
    {
        return explode('::', $nodeId, 2)[0];
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

    private function addEdge(string $source, string $target, string $type, int $line): void
    {
        $key = $source . '|' . $type . '|' . $target;
        if (isset($this->edgeKeys[$key])) {
            return;
        }

        $this->edgeKeys[$key] = true;
        $this->linkedClasses[self::classOf($source) . '|' . self::classOf($target)] = true;
        $this->graphEdges[] = ['source' => $source, 'target' => $target, 'type' => $type, 'line' => $line];
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
