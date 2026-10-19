<?php

declare(strict_types=1);

namespace DepsGraph;

/**
 * Builds the graph JSON structure. Only classes found in the scanned
 * directories become nodes: edges towards anything else are dropped.
 *
 * @phpstan-import-type ClassData from ClassVisitor
 * @phpstan-import-type MethodData from ClassVisitor
 * @phpstan-type Edge array{source: string, target: string, type: string, line: int}
 * @phpstan-type ClassMetrics array{ca: int, ce: int, instability: float}
 * @phpstan-type ClassNode array{id: string, type: 'class', label: string, namespace: string, layer: string, classType: string, file: string|null, line: int, methods: list<string>, methodCount: int, metrics?: ClassMetrics, cycle?: int|null}
 * @phpstan-type MethodNode array{id: string, type: 'method', label: string, class: string, visibility: string, static: bool, line: int, layer: string}
 * @phpstan-type Cycle array{members: list<string>, path: list<string>}
 * @phpstan-type NamespaceMetrics array{id: string, size: int, ca: int, ce: int, instability: float, abstractness: float, distance: float}
 * @phpstan-type Stats array{totalClasses: int, totalMethods: int, totalEdges: int, callsCollapsedToClass: int, layerCounts: array<string, int>, edgeTypeCounts: array<string, int>, classTypeCounts: array<string, int>, classCycles?: int, namespaceCycles?: int}
 * @phpstan-type Graph array{root?: string, nodes: list<ClassNode|MethodNode>, edges: list<Edge>, stats: Stats, cycles?: list<Cycle>, namespaces?: list<NamespaceMetrics>, namespaceCycles?: list<Cycle>}
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

    /** @var list<Edge> */
    private array $graphEdges = [];

    /**
     * @param array<string, ClassData> $classes keyed by FQCN
     * @param list<Edge> $rawEdges
     * @return Graph
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
                $nodes[] = $this->methodNode($fqcn, $methodName, $methodData, $data['layer']);
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
     * @param ClassData $data
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
     * @param array<string, ClassData> $classes
     * @return list<Edge>
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
        $targetClass = self::classOf($target);
        $targetMethod = str_contains($target, '::') ? explode('::', $target, 2)[1] : null;
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
     * @param ClassData $data
     * @return ClassNode
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
     * @param MethodData $methodData
     * @return MethodNode
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
     * @param array<string, ClassData> $classes
     * @param list<ClassNode|MethodNode> $nodes
     * @return Stats
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
