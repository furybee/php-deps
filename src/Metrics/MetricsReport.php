<?php

declare(strict_types=1);

namespace DepsGraph\Metrics;

use DepsGraph\GraphBuilder;

/**
 * Text summary of the metrics added by GraphMetrics.
 *
 * @phpstan-import-type Graph from GraphBuilder
 * @phpstan-import-type ClassNode from GraphBuilder
 * @phpstan-import-type Cycle from GraphBuilder
 * @phpstan-import-type NamespaceMetrics from GraphBuilder
 */
final class MetricsReport
{
    private const TOP = 10;
    private const MIN_NAMESPACE_SIZE = 5;

    /**
     * @param Graph $graph enriched by GraphMetrics
     */
    public function render(array $graph): string
    {
        $classes = [];
        foreach ($graph['nodes'] as $node) {
            if ($node['type'] === 'class') {
                $classes[] = $node;
            }
        }

        return $this->cycles('Class cycles', $graph['cycles'] ?? [], 'classes')
            . $this->cycles('Namespace cycles', $graph['namespaceCycles'] ?? [], 'namespaces')
            . $this->ranking('Most depended upon classes (afferent coupling)', $classes, 'ca')
            . $this->ranking('Classes depending on the most classes (efferent coupling)', $classes, 'ce')
            . $this->namespaceDistances($graph['namespaces'] ?? []);
    }

    /**
     * @param list<Cycle> $cycles
     */
    private function cycles(string $title, array $cycles, string $unit): string
    {
        $output = "\n{$title}: " . count($cycles) . "\n";
        foreach (array_slice($cycles, 0, self::TOP) as $cycle) {
            $output .= sprintf("  %5d %s  %s\n", count($cycle['members']), $unit, implode(' -> ', [...$cycle['path'], $cycle['path'][0]]));
        }

        return $output;
    }

    /**
     * @param list<ClassNode> $classes
     * @param 'ca'|'ce' $metric
     */
    private function ranking(string $title, array $classes, string $metric): string
    {
        usort($classes, static fn(array $a, array $b) => self::metric($b, $metric) <=> self::metric($a, $metric));

        $output = "\n{$title}\n";
        foreach (array_slice($classes, 0, self::TOP) as $class) {
            $output .= sprintf("  %5d  %s\n", self::metric($class, $metric), $class['id']);
        }

        return $output;
    }

    /**
     * @param ClassNode $class
     * @param 'ca'|'ce' $metric
     */
    private static function metric(array $class, string $metric): int
    {
        return $class['metrics'][$metric] ?? 0;
    }

    /**
     * Concrete and stable (hard to change, everything depends on it) or abstract and unstable (abstractions nobody uses).
     * Namespaces depending on nothing (enums, DTOs, exceptions) are concrete and stable by nature, they would fill the list.
     *
     * @param list<NamespaceMetrics> $namespaces
     */
    private function namespaceDistances(array $namespaces): string
    {
        $namespaces = array_filter(
            $namespaces,
            static fn(array $namespace) => $namespace['size'] >= self::MIN_NAMESPACE_SIZE && $namespace['ce'] > 0,
        );
        usort($namespaces, static fn(array $a, array $b) => [$b['distance'], $b['ca']] <=> [$a['distance'], $a['ca']]);

        $output = "\nNamespaces furthest from the main sequence (D = |A + I - 1|), "
            . self::MIN_NAMESPACE_SIZE . "+ classes, namespaces without dependencies left out\n";
        foreach (array_slice($namespaces, 0, self::TOP) as $namespace) {
            $output .= sprintf(
                "  D %.2f  A %.2f  I %.2f  Ca %4d  %4d classes  %s\n",
                $namespace['distance'],
                $namespace['abstractness'],
                $namespace['instability'],
                $namespace['ca'],
                $namespace['size'],
                $namespace['id'],
            );
        }

        return $output;
    }
}
