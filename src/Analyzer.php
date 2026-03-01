<?php

declare(strict_types=1);

namespace DepsGraph;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\ErrorHandler;

final class Analyzer
{
    private string $basePath;
    private string $scanDir;
    private \PhpParser\Parser $parser;
    private TypeResolver $typeResolver;

    /** @var array<string, array> class data from first pass */
    private array $classMap = [];

    /** @var array<string, array{namespace: string, useMap: array}> file context from first pass */
    private array $fileContexts = [];

    public function __construct(string $basePath, string $scanDir = 'app')
    {
        $this->basePath = rtrim($basePath, '/');
        $this->scanDir = $scanDir;
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->typeResolver = new TypeResolver();
    }

    /**
     * Run the full analysis and return the graph data.
     */
    public function analyze(): array
    {
        $files = $this->findPhpFiles();
        $totalFiles = count($files);

        fwrite(STDERR, "Found {$totalFiles} PHP files in {$this->scanDir}/\n");

        // First pass: extract class metadata
        fwrite(STDERR, "Pass 1/2: Extracting classes and methods...\n");
        $this->firstPass($files);
        fwrite(STDERR, "  Found " . count($this->classMap) . " classes/interfaces/traits/enums\n");

        // Second pass: extract call relationships
        fwrite(STDERR, "Pass 2/2: Extracting call graph...\n");
        $edges = $this->secondPass($files);
        fwrite(STDERR, "  Found " . count($edges) . " call edges\n");

        // Build final graph
        $builder = new GraphBuilder();
        $graph = $builder->build($this->classMap, $edges);

        fwrite(STDERR, "\nStats:\n");
        fwrite(STDERR, "  Classes: {$graph['stats']['totalClasses']}\n");
        fwrite(STDERR, "  Methods: {$graph['stats']['totalMethods']}\n");
        fwrite(STDERR, "  Edges:   {$graph['stats']['totalEdges']}\n");
        fwrite(STDERR, "  Layers:\n");
        foreach ($graph['stats']['layerCounts'] as $layer => $count) {
            fwrite(STDERR, "    {$layer}: {$count}\n");
        }

        return $graph;
    }

    private function firstPass(array $files): void
    {
        $errorHandler = new ErrorHandler\Collecting();
        $processed = 0;
        $totalFiles = count($files);

        foreach ($files as $file) {
            $code = file_get_contents($file);
            $errorHandler->clearErrors();

            try {
                $ast = $this->parser->parse($code, $errorHandler);
            } catch (\Throwable $e) {
                fwrite(STDERR, "  [WARN] Parse error in {$file}: {$e->getMessage()}\n");
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $visitor = new ClassVisitor($this->typeResolver);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $classes = $visitor->getClasses();
            $relPath = $this->relativePath($file);

            foreach ($classes as $fqcn => &$data) {
                $data['file'] = $relPath;
            }
            unset($data);

            $this->classMap = array_merge($this->classMap, $classes);
            $this->fileContexts[$file] = [
                'namespace' => $visitor->getCurrentNamespace(),
                'useMap' => $visitor->getUseMap(),
            ];

            $processed++;
            if ($processed % 200 === 0) {
                fwrite(STDERR, "  Processed {$processed}/{$totalFiles} files...\n");
            }
        }
    }

    private function secondPass(array $files): array
    {
        $errorHandler = new ErrorHandler\Collecting();
        $allEdges = [];
        $processed = 0;

        $callVisitor = new CallVisitor($this->typeResolver, $this->classMap);

        foreach ($files as $file) {
            if (!isset($this->fileContexts[$file])) {
                continue;
            }

            $code = file_get_contents($file);
            $errorHandler->clearErrors();

            try {
                $ast = $this->parser->parse($code, $errorHandler);
            } catch (\Throwable $e) {
                continue;
            }

            if ($ast === null) {
                continue;
            }

            $ctx = $this->fileContexts[$file];
            $callVisitor->setContext($ctx['namespace'], $ctx['useMap']);

            $traverser = new NodeTraverser();
            $traverser->addVisitor($callVisitor);
            $traverser->traverse($ast);

            $processed++;
            if ($processed % 200 === 0) {
                fwrite(STDERR, "  Processed {$processed}/" . count($files) . " files...\n");
            }
        }

        return $callVisitor->getEdges();
    }

    private function findPhpFiles(): array
    {
        $dir = $this->basePath . '/' . $this->scanDir;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function relativePath(string $path): string
    {
        if (str_starts_with($path, $this->basePath . '/')) {
            return substr($path, strlen($this->basePath) + 1);
        }
        return $path;
    }
}
