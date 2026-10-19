<?php

declare(strict_types=1);

namespace DepsGraph\Tests\Metrics;

use DepsGraph\Metrics\CouplingMetrics;
use DepsGraph\Metrics\DependencyMap;
use PHPUnit\Framework\TestCase;

final class CouplingMetricsTest extends TestCase
{
    public function testComputesCouplingInstabilityAbstractnessAndDistance(): void
    {
        $map = DependencyMap::fromGraph(GraphFactory::make(
            ['App\Ui\Page' => 'class', 'App\Ui\Form' => 'class', 'App\Core\Repository' => 'interface', 'App\Core\Entity' => 'class'],
            [['App\Ui\Page', 'App\Core\Repository'], ['App\Ui\Form', 'App\Core\Entity']],
        ), 0);

        $metrics = (new CouplingMetrics())->compute($map);

        $this->assertSame(['size' => 2, 'ca' => 0, 'ce' => 1, 'instability' => 1.0, 'abstractness' => 0.0, 'distance' => 0.0], $metrics['App\Ui']);
        $this->assertSame(['size' => 2, 'ca' => 1, 'ce' => 0, 'instability' => 0.0, 'abstractness' => 0.5, 'distance' => 0.5], $metrics['App\Core']);
    }

    public function testTreatsIsolatedNodesAsStable(): void
    {
        $metrics = (new CouplingMetrics())->compute(DependencyMap::fromGraph(GraphFactory::make(['A' => 'class'], [])));

        $this->assertSame(0.0, $metrics['A']['instability']);
    }
}
