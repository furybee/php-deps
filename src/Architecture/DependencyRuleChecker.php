<?php

declare(strict_types=1);

namespace DepsGraph\Architecture;

use DepsGraph\Metrics\DependencyMap;

/**
 * Checks graph edges against the allowed layer dependencies.
 * Classes outside every layer are not checked, nor are layers without rules.
 *
 * @phpstan-import-type MinimalGraph from DependencyMap
 */
final class DependencyRuleChecker
{
    public function __construct(private readonly ArchitectureConfig $config)
    {
    }

    /**
     * @param MinimalGraph $graph
     * @return Violation[] one per class pair, at its first occurrence
     */
    public function check(array $graph): array
    {
        $layerMap = new LayerMap($this->config->layers);
        $files = [];
        foreach ($graph['nodes'] as $node) {
            if ($node['type'] === 'class') {
                $files[$node['id']] = $node['file'] ?? '';
            }
        }

        $violations = [];
        foreach ($graph['edges'] as $edge) {
            $sourceClass = explode('::', $edge['source'], 2)[0];
            $targetClass = explode('::', $edge['target'], 2)[0];
            $sourceLayer = $layerMap->layerOf($sourceClass);
            $targetLayer = $layerMap->layerOf($targetClass);

            if ($sourceLayer === null || $targetLayer === null || $this->isAllowed($sourceLayer, $targetLayer)) {
                continue;
            }

            $violation = new Violation(
                $sourceClass,
                $targetClass,
                $sourceLayer,
                $targetLayer,
                $edge['type'],
                $files[$sourceClass] ?? '',
                $edge['line'] ?? 0,
            );
            $existing = $violations[$violation->key()] ?? null;
            if ($existing === null || $violation->line < $existing->line) {
                $violations[$violation->key()] = $violation;
            }
        }

        $violations = array_values($violations);
        usort($violations, static fn(Violation $a, Violation $b) => [$a->file, $a->line] <=> [$b->file, $b->line]);

        return $violations;
    }

    private function isAllowed(string $sourceLayer, string $targetLayer): bool
    {
        if ($sourceLayer === $targetLayer || !isset($this->config->rules[$sourceLayer])) {
            return true;
        }

        return in_array($targetLayer, $this->config->rules[$sourceLayer], true);
    }
}
