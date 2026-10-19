<?php

declare(strict_types=1);

namespace DepsGraph\Metrics;

/**
 * Robert C. Martin's package metrics, per node of a DependencyMap.
 */
final class CouplingMetrics
{
    /**
     * @return array<string, array{size: int, ca: int, ce: int, instability: float, abstractness: float, distance: float}>
     */
    public function compute(DependencyMap $map): array
    {
        $afferent = array_fill_keys(array_keys($map->sizes), 0);
        foreach ($map->dependencies as $dependencies) {
            foreach (array_keys($dependencies) as $target) {
                $afferent[$target]++;
            }
        }

        $metrics = [];
        foreach ($map->sizes as $node => $size) {
            $ca = $afferent[$node];
            $ce = count($map->dependencies[$node]);
            // Isolated nodes have nothing to be unstable about
            $instability = $ca + $ce === 0 ? 0.0 : $ce / ($ca + $ce);
            $abstractness = $map->abstractSizes[$node] / $size;

            $metrics[$node] = [
                'size' => $size,
                'ca' => $ca,
                'ce' => $ce,
                'instability' => round($instability, 2),
                'abstractness' => round($abstractness, 2),
                'distance' => round(abs($abstractness + $instability - 1), 2),
            ];
        }

        return $metrics;
    }
}
