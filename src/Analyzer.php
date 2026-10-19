<?php

declare(strict_types=1);

namespace DepsGraph;

use PhpParser\ErrorHandler;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class Analyzer
{
    private readonly string $basePath;
    private readonly Parser $parser;
    private readonly NameResolver $nameResolver;
    private readonly TypeResolver $typeResolver;
    private readonly \Closure $log;

    /**
     * @param string[] $scanDirs relative to $basePath, or absolute
     * @param string[] $excludeDirs a bare name (`vendor`) excludes every directory with that name,
     *                              a path (`bootstrap/cache`) is relative to $basePath
     */
    public function __construct(
        string $basePath,
        private readonly array $scanDirs = ['.'],
        private readonly array $excludeDirs = [],
        ?\Closure $log = null,
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->parser = (new ParserFactory())->createForHostVersion();
        $this->nameResolver = new NameResolver();
        $this->typeResolver = new TypeResolver($this->nameResolver->getNameContext());
        $this->log = $log ?? static function (string $message): void {
        };
    }

    /**
     * @return array{nodes: array<mixed>, edges: array<mixed>, stats: array<string, mixed>}
     */
    public function analyze(): array
    {
        $files = $this->findPhpFiles();
        ($this->log)("Found " . count($files) . " PHP files");

        ($this->log)("Pass 1/2: Extracting classes and methods...");
        $classMap = $this->extractClasses($files);
        ($this->log)("  Found " . count($classMap) . " classes/interfaces/traits/enums");

        $hierarchy = new ClassHierarchy($classMap);

        ($this->log)("Pass 2/2: Extracting call graph...");
        $callVisitor = new CallVisitor($this->typeResolver, $hierarchy);
        $this->traverseFiles($files, $callVisitor, static fn() => null);
        ($this->log)("  Found " . count($callVisitor->getEdges()) . " raw call edges");

        return (new GraphBuilder())->build($classMap, $callVisitor->getEdges(), $hierarchy);
    }

    /**
     * @param string[] $files
     * @return array<string, array<string, mixed>>
     */
    private function extractClasses(array $files): array
    {
        $classMap = [];
        $classVisitor = new ClassVisitor($this->typeResolver);

        $this->traverseFiles($files, $classVisitor, function (string $file) use ($classVisitor, &$classMap): void {
            $relativePath = $this->relativePath($file);
            foreach ($classVisitor->getClasses() as $fqcn => $data) {
                if (isset($classMap[$fqcn])) {
                    ($this->log)("  [WARN] {$fqcn} declared in both {$classMap[$fqcn]['file']} and {$relativePath}, keeping the first");
                    continue;
                }
                $classMap[$fqcn] = $data + ['file' => $relativePath];
            }
        });

        return $classMap;
    }

    /**
     * @param string[] $files
     * @param \Closure(string): void $afterFile
     */
    private function traverseFiles(array $files, NodeVisitorAbstract $visitor, \Closure $afterFile): void
    {
        // Visitors inspect child nodes ahead of the traversal, so names must all be resolved beforehand.
        // The second NameResolver only keeps the name context current for PHPDoc types.
        $nameResolution = new NodeTraverser(new NameResolver());
        $traverser = new NodeTraverser($this->nameResolver, $visitor);
        $errorHandler = new ErrorHandler\Collecting();

        foreach ($files as $index => $file) {
            $errorHandler->clearErrors();
            $ast = $this->parser->parse((string) file_get_contents($file), $errorHandler);

            if ($errorHandler->hasErrors()) {
                ($this->log)("  [WARN] Parse errors in {$this->relativePath($file)}: {$errorHandler->getErrors()[0]->getMessage()}");
            }

            if ($ast !== null) {
                $traverser->traverse($nameResolution->traverse($ast));
                $afterFile($file);
            }

            if (($index + 1) % 500 === 0) {
                ($this->log)("  Processed " . ($index + 1) . "/" . count($files) . " files...");
            }
        }
    }

    /**
     * @return string[]
     */
    private function findPhpFiles(): array
    {
        $files = [];

        foreach ($this->scanDirs as $scanDir) {
            $dir = str_starts_with($scanDir, '/') ? rtrim($scanDir, '/') : $this->basePath . '/' . trim($scanDir, '/');
            $dir = rtrim(str_replace('/./', '/', $dir . '/'), '/');

            if (!is_dir($dir)) {
                ($this->log)("  [WARN] Directory not found: {$dir}");
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    fn(\SplFileInfo $current) => !$current->isDir() || !$this->isExcluded($current),
                ),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function isExcluded(\SplFileInfo $directory): bool
    {
        $relativePath = $this->relativePath($directory->getPathname());

        foreach ($this->excludeDirs as $excluded) {
            $excluded = trim($excluded, '/');
            $isBareName = !str_contains($excluded, '/');

            if ($isBareName && $directory->getFilename() === $excluded) {
                return true;
            }

            if ($relativePath === $excluded || str_starts_with($relativePath, $excluded . '/')) {
                return true;
            }
        }

        return false;
    }

    private function relativePath(string $path): string
    {
        return str_starts_with($path, $this->basePath . '/')
            ? substr($path, strlen($this->basePath) + 1)
            : $path;
    }
}
