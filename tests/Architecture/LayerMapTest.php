<?php

declare(strict_types=1);

namespace DepsGraph\Tests\Architecture;

use DepsGraph\Architecture\LayerMap;
use PHPUnit\Framework\TestCase;

final class LayerMapTest extends TestCase
{
    public function testSingleStarStaysWithinOneNamespaceSegment(): void
    {
        $layers = new LayerMap(['Model' => ['App\*']]);

        $this->assertSame('Model', $layers->layerOf('App\User'));
        $this->assertNull($layers->layerOf('App\Services\UserService'));
    }

    public function testDoubleStarCrossesNamespaceSegments(): void
    {
        $layers = new LayerMap(['Domain' => ['**\Domain\**']]);

        $this->assertSame('Domain', $layers->layerOf('Modules\Document\Folder\Domain\Contracts\FolderRepository'));
        $this->assertNull($layers->layerOf('Modules\Document\Folder\Application\Actions\MoveFolder'));
    }

    public function testFirstMatchingLayerWins(): void
    {
        $layers = new LayerMap(['Contract' => ['**\Contracts\**'], 'Domain' => ['**\Domain\**']]);

        $this->assertSame('Contract', $layers->layerOf('Modules\Billing\Domain\Contracts\InvoiceRepository'));
    }

    public function testPatternsAreLiteralApartFromStars(): void
    {
        $layers = new LayerMap(['Http' => ['App\Http.Kernel']]);

        $this->assertNull($layers->layerOf('App\HttpXKernel'));
    }
}
