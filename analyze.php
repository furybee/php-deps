#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Laravel Dependency Graph Analyzer
 *
 * Statically analyzes PHP files in app/ to extract a full call graph:
 * classes, methods, calls, instantiations, DI, and inheritance.
 *
 * Usage:
 *   php scripts/deps-graph/analyze.php [--dir=app] [--output=scripts/deps-graph/viewer/graph.json]
 */

$basePath = dirname(__DIR__, 2);
require_once $basePath . '/vendor/autoload.php';

// Autoload our analyzer classes
spl_autoload_register(function (string $class) {
    $prefix = 'DepsGraph\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Parse CLI arguments
$scanDir = 'app';
$outputFile = __DIR__ . '/output/graph.json';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $scanDir = substr($arg, 6);
    }
    if (str_starts_with($arg, '--output=')) {
        $outputFile = substr($arg, 9);
        if (!str_starts_with($outputFile, '/')) {
            $outputFile = $basePath . '/' . $outputFile;
        }
    }
}

fwrite(STDERR, "=== Laravel Dependency Graph Analyzer ===\n\n");
fwrite(STDERR, "Base path: {$basePath}\n");
fwrite(STDERR, "Scan dir:  {$scanDir}/\n");
fwrite(STDERR, "Output:    {$outputFile}\n\n");

$startTime = microtime(true);

$analyzer = new DepsGraph\Analyzer($basePath, $scanDir);
$graph = $analyzer->analyze();

$elapsed = round(microtime(true) - $startTime, 2);
$json = json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// Ensure output directory exists
$outputDir = dirname($outputFile);
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

file_put_contents($outputFile, $json);

$sizeMb = round(strlen($json) / 1024 / 1024, 2);
fwrite(STDERR, "\nDone in {$elapsed}s. Output: {$outputFile} ({$sizeMb} MB)\n");
