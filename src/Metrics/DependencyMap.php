<?php

declare(strict_types=1);

namespace DepsGraph\Metrics;

use DepsGraph\GraphBuilder;

/**
 * Class-to-class (or namespace-to-namespace) dependencies, whatever the edge type.
 *
 * Traits are inlined: their dependencies count for every class using them, and `uses_trait`
 * is not a dependency, otherwise a trait referencing its host class would always form a cycle.
 *
 * @phpstan-import-type Edge from GraphBuilder
 * @phpstan-type MinimalGraph array{nodes: list<array{id: string, type: string, classType?: string, file?: string|null}>, edges: list<array{source: string, target: string, type: string, line?: int}>}
 */
final class DependencyMap
{
    /**
     * @param array<string, array<string, true>> $dependencies node => [dependency => true]
     * @param array<string, int> $sizes node => number of classes it groups
     * @param array<string, int> $abstractSizes node => number of interfaces and abstract classes
     * @param array<string, array<string, Edge>> $evidence
     *        source => [target => first edge behind that dependency]
     */
    private function __construct(
        public readonly array $dependencies,
        public readonly array $sizes,
        public readonly array $abstractSizes,
        private readonly array $evidence,
    ) {
    }

    /**
     * @return Edge|null
     */
    public function evidence(string $source, string $target): ?array
    {
        return $this->evidence[$source][$target] ?? null;
    }

    /**
     * @param MinimalGraph $graph
     * @param int|null $namespaceDepth null for class level, 0 for full namespaces, N for the first N segments
     * @param (\Closure(string): bool)|null $classFilter classes rejected are left out entirely
     */
    public static function fromGraph(array $graph, ?int $namespaceDepth = null, ?\Closure $classFilter = null): self
    {
        $groupOf = [];
        $dependencies = [];
        $sizes = [];
        $abstractSizes = [];
        $evidence = [];
        $isTrait = [];

        foreach ($graph['nodes'] as $node) {
            if ($node['type'] !== 'class' || ($classFilter !== null && !$classFilter($node['id']))) {
                continue;
            }

            $group = $namespaceDepth === null ? $node['id'] : self::namespaceOf($node['id'], $namespaceDepth);
            $groupOf[$node['id']] = $group;
            $classType = $node['classType'] ?? 'class';
            $isTrait[$node['id']] = $classType === 'trait';
            $dependencies[$group] ??= [];
            $sizes[$group] = ($sizes[$group] ?? 0) + 1;
            $abstractSizes[$group] = ($abstractSizes[$group] ?? 0)
                + (in_array($classType, ['interface', 'abstract'], true) ? 1 : 0);
        }

        $traitUsers = [];
        $classEdges = [];
        foreach ($graph['edges'] as $edge) {
            $sourceClass = explode('::', $edge['source'], 2)[0];
            $targetClass = explode('::', $edge['target'], 2)[0];
            if (!isset($groupOf[$sourceClass], $groupOf[$targetClass])) {
                continue;
            }

            if ($edge['type'] === 'uses_trait') {
                $traitUsers[$targetClass][] = $sourceClass;
                continue;
            }
            $classEdges[] = [$sourceClass, $targetClass, $edge];
        }

        foreach ($classEdges as [$sourceClass, $targetClass, $edge]) {
            $sourceClasses = $isTrait[$sourceClass] ? self::traitHosts($sourceClass, $traitUsers, $isTrait) : [$sourceClass];

            foreach ($sourceClasses as $hostClass) {
                $source = $groupOf[$hostClass];
                $target = $groupOf[$targetClass];
                if ($source === $target) {
                    continue;
                }

                $dependencies[$source][$target] = true;
                $evidence[$source][$target] ??= [
                    'source' => $edge['source'],
                    'target' => $edge['target'],
                    'type' => $edge['type'],
                    'line' => $edge['line'] ?? 0,
                ];
            }
        }

        return new self($dependencies, $sizes, $abstractSizes, $evidence);
    }

    /**
     * Classes a trait ends up in, through traits using traits.
     *
     * @param array<string, string[]> $traitUsers
     * @param array<string, bool> $isTrait
     * @return string[]
     */
    private static function traitHosts(string $trait, array $traitUsers, array $isTrait): array
    {
        $hosts = [];
        $queue = [$trait];
        $seen = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            foreach ($traitUsers[$current] ?? [] as $user) {
                if ($isTrait[$user]) {
                    $queue[] = $user;
                } else {
                    $hosts[] = $user;
                }
            }
        }

        return $hosts === [] ? [$trait] : $hosts;
    }

    private static function namespaceOf(string $class, int $depth): string
    {
        $segments = explode('\\', $class);
        array_pop($segments);
        if ($segments === []) {
            return '\\';
        }

        return implode('\\', $depth > 0 ? array_slice($segments, 0, $depth) : $segments);
    }
}
