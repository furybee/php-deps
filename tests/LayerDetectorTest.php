<?php

declare(strict_types=1);

namespace DepsGraph\Tests;

use DepsGraph\LayerDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LayerDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function layers(): iterable
    {
        yield 'laravel controller' => ['App\Http\Controllers\UserController', 'Controller'];
        yield 'versioned api controller' => ['App\Api\V1\Controllers\Survey\ShowController', 'Controller'];
        yield 'nested service domain' => ['App\Services\Survey\UpdateSurveyService', 'Service'];
        yield 'clean architecture action' => ['Modules\Document\Folder\Application\Actions\GetFoldersAction', 'Action'];
        yield 'clean architecture contract' => ['Modules\Document\Folder\Domain\Contracts\Repositories\FolderRepositoryInterface', 'Repository'];
        yield 'clean architecture fallback' => ['Modules\AI\Chat\Infrastructure\OpenAi\Client', 'Infrastructure'];
        yield 'class suffix' => ['App\Library\Pdf\PdfRenderService', 'Service'];
        yield 'singularized segment' => ['App\Strategies\Export\Csv', 'Strategy'];
        yield 'global class' => ['UserNotFoundException', 'Exception'];
        yield 'nothing to infer' => ['App\Foo', 'Other'];
    }

    #[DataProvider('layers')]
    public function testDetectsTheLayer(string $fqcn, string $expectedLayer): void
    {
        $this->assertSame($expectedLayer, LayerDetector::detect($fqcn));
    }
}
