<?php

declare(strict_types=1);

namespace DepsGraph;

final class GraphBuilder
{
    /**
     * Build the final graph JSON structure from class data and edges.
     *
     * @param array<string, array> $classes Class data keyed by FQCN
     * @param array<array{source: string, target: string, type: string}> $edges
     * @return array{nodes: array, edges: array, stats: array}
     */
    public function build(array $classes, array $edges): array
    {
        $nodes = [];
        $graphEdges = [];
        $referencedClasses = [];
        $edgeKeys = [];

        // Collect all referenced class FQCNs from edges
        foreach ($edges as $edge) {
            $sourceClass = str_contains($edge['source'], '::') ? explode('::', $edge['source'])[0] : $edge['source'];
            $targetClass = str_contains($edge['target'], '::') ? explode('::', $edge['target'])[0] : $edge['target'];
            $referencedClasses[$sourceClass] = true;
            $referencedClasses[$targetClass] = true;
        }

        // Build class nodes
        foreach ($classes as $fqcn => $data) {
            $nodes[] = [
                'id' => $fqcn,
                'type' => 'class',
                'label' => $data['name'],
                'namespace' => $data['namespace'],
                'layer' => $data['layer'],
                'classType' => $data['type'], // class/interface/trait/enum
                'methods' => array_keys($data['methods']),
                'methodCount' => count($data['methods']),
            ];

            // Build method nodes
            foreach ($data['methods'] as $methodName => $methodData) {
                $methodId = $fqcn . '::' . $methodName;
                $nodes[] = [
                    'id' => $methodId,
                    'type' => 'method',
                    'label' => $methodName,
                    'class' => $fqcn,
                    'visibility' => $methodData['visibility'],
                    'static' => $methodData['static'],
                    'layer' => $data['layer'],
                ];
            }

            // Add class-level dependency edges (extends, implements, traits)
            if (!empty($data['extends']) && $this->isAppClass($data['extends'])) {
                $key = $fqcn . '|extends|' . $data['extends'];
                if (!isset($edgeKeys[$key])) {
                    $graphEdges[] = ['source' => $fqcn, 'target' => $data['extends'], 'type' => 'extends'];
                    $edgeKeys[$key] = true;
                    $referencedClasses[$data['extends']] = true;
                }
            }

            foreach ($data['implements'] as $impl) {
                if ($this->isAppClass($impl)) {
                    $key = $fqcn . '|implements|' . $impl;
                    if (!isset($edgeKeys[$key])) {
                        $graphEdges[] = ['source' => $fqcn, 'target' => $impl, 'type' => 'implements'];
                        $edgeKeys[$key] = true;
                        $referencedClasses[$impl] = true;
                    }
                }
            }

            foreach ($data['traits'] as $trait) {
                if ($this->isAppClass($trait)) {
                    $key = $fqcn . '|uses_trait|' . $trait;
                    if (!isset($edgeKeys[$key])) {
                        $graphEdges[] = ['source' => $fqcn, 'target' => $trait, 'type' => 'uses_trait'];
                        $edgeKeys[$key] = true;
                        $referencedClasses[$trait] = true;
                    }
                }
            }

            // Constructor injection as depends_on edges
            foreach ($data['constructorParams'] as $paramName => $paramType) {
                if ($paramType && $this->isAppClass($paramType)) {
                    $key = $fqcn . '|depends_on|' . $paramType;
                    if (!isset($edgeKeys[$key])) {
                        $graphEdges[] = ['source' => $fqcn, 'target' => $paramType, 'type' => 'depends_on'];
                        $edgeKeys[$key] = true;
                    }
                }
            }
        }

        // Add call edges (deduplicated)
        foreach ($edges as $edge) {
            $key = $edge['source'] . '|' . $edge['type'] . '|' . $edge['target'];
            if (!isset($edgeKeys[$key])) {
                $graphEdges[] = $edge;
                $edgeKeys[$key] = true;
            }
        }

        // Create placeholder nodes for referenced but not-parsed classes
        $existingNodes = array_flip(array_column($nodes, 'id'));
        foreach ($referencedClasses as $fqcn => $_) {
            if (!isset($existingNodes[$fqcn]) && !isset($classes[$fqcn])) {
                $parts = explode('\\', $fqcn);
                $className = end($parts);
                $nodes[] = [
                    'id' => $fqcn,
                    'type' => 'class',
                    'label' => $className,
                    'namespace' => implode('\\', array_slice($parts, 0, -1)),
                    'layer' => TypeResolver::detectLayer($fqcn),
                    'classType' => self::guessClassType($className),
                    'methods' => [],
                    'methodCount' => 0,
                    'external' => true,
                ];
            }
        }

        // Compute stats
        $layerCounts = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'class') {
                $layer = $node['layer'];
                $layerCounts[$layer] = ($layerCounts[$layer] ?? 0) + 1;
            }
        }
        arsort($layerCounts);

        $edgeTypeCounts = [];
        foreach ($graphEdges as $edge) {
            $type = $edge['type'];
            $edgeTypeCounts[$type] = ($edgeTypeCounts[$type] ?? 0) + 1;
        }

        $classTypeCounts = [];
        foreach ($nodes as $node) {
            if ($node['type'] === 'class') {
                $ct = $node['classType'];
                $classTypeCounts[$ct] = ($classTypeCounts[$ct] ?? 0) + 1;
            }
        }
        arsort($classTypeCounts);

        return [
            'nodes' => $nodes,
            'edges' => $graphEdges,
            'stats' => [
                'totalClasses' => count($classes),
                'totalMethods' => array_sum(array_map(fn($c) => count($c['methods']), $classes)),
                'totalEdges' => count($graphEdges),
                'layerCounts' => $layerCounts,
                'edgeTypeCounts' => $edgeTypeCounts,
                'classTypeCounts' => $classTypeCounts,
            ],
        ];
    }

    /**
     * Guess the PHP type of an unparsed class from its name.
     */
    private static function guessClassType(string $className): string
    {
        // Starts with I + uppercase = likely interface (IUserService, IRepository)
        if (preg_match('/^I[A-Z]/', $className)) {
            return 'interface';
        }

        if (str_ends_with($className, 'Interface') || str_ends_with($className, 'Contract')) {
            return 'interface';
        }

        if (str_ends_with($className, 'Trait')) {
            return 'trait';
        }

        if (str_ends_with($className, 'Enum')) {
            return 'enum';
        }

        return 'class';
    }

    private function isAppClass(string $fqcn): bool
    {
        return str_starts_with($fqcn, 'App\\');
    }
}
