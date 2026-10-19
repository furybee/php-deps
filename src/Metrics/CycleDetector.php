<?php

declare(strict_types=1);

namespace DepsGraph\Metrics;

/**
 * Tarjan's strongly connected components, iterative so that long dependency chains cannot exhaust the stack.
 */
final class CycleDetector
{
    /**
     * @return array<string[]> components of 2+ nodes, largest first, members sorted
     */
    public function components(DependencyMap $map): array
    {
        $graph = array_map('array_keys', $map->dependencies);
        $index = [];
        $lowLink = [];
        $onStack = [];
        $stack = [];
        $components = [];
        $counter = 0;

        foreach (array_keys($graph) as $root) {
            if (isset($index[$root])) {
                continue;
            }

            $work = [[$root, 0]];
            while ($work !== []) {
                [$node, $childPosition] = array_pop($work);

                if ($childPosition === 0) {
                    $index[$node] = $lowLink[$node] = $counter++;
                    $stack[] = $node;
                    $onStack[$node] = true;
                }

                $children = $graph[$node] ?? [];
                if (isset($children[$childPosition])) {
                    $child = $children[$childPosition];
                    $work[] = [$node, $childPosition + 1];

                    if (!isset($index[$child])) {
                        $work[] = [$child, 0];
                    } elseif (isset($onStack[$child])) {
                        $lowLink[$node] = min($lowLink[$node], $index[$child]);
                    }
                    continue;
                }

                if ($lowLink[$node] === $index[$node]) {
                    $component = [];
                    do {
                        $member = array_pop($stack);
                        unset($onStack[$member]);
                        $component[] = $member;
                    } while ($member !== $node);

                    if (count($component) > 1) {
                        sort($component);
                        $components[] = $component;
                    }
                }

                $parent = end($work);
                if ($parent !== false) {
                    $lowLink[$parent[0]] = min($lowLink[$parent[0]], $lowLink[$node]);
                }
            }
        }

        usort($components, static fn(array $a, array $b) => count($b) <=> count($a) ?: $a <=> $b);

        return $components;
    }

    /**
     * Shortest cycle going through $start inside its component: a concrete path to break.
     *
     * @param string[] $component
     * @return string[] nodes in order, the last one depends on the first
     */
    public function shortestCycle(DependencyMap $map, array $component, string $start): array
    {
        $inComponent = array_fill_keys($component, true);
        $previous = [$start => null];
        $queue = [$start];

        while ($queue !== []) {
            $node = array_shift($queue);
            foreach (array_keys($map->dependencies[$node] ?? []) as $next) {
                if ($next === $start) {
                    $path = [];
                    for ($current = $node; $current !== null; $current = $previous[$current]) {
                        $path[] = $current;
                    }
                    return array_reverse($path);
                }

                if (isset($inComponent[$next]) && !array_key_exists($next, $previous)) {
                    $previous[$next] = $node;
                    $queue[] = $next;
                }
            }
        }

        return [];
    }
}
