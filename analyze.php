#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHP Dependency Graph Analyzer
 *
 * Statically analyzes PHP files to extract a full call graph:
 * classes, methods, calls, instantiations, DI, and inheritance.
 *
 * Usage:
 *   php analyze.php [--dir=.] [--exclude=vendor] [--output=output/graph.json]
 *
 * Defaults:
 *   --dir=. (scans entire project, vendor/ excluded by default)
 *   --exclude=vendor,node_modules,.claude,storage,bootstrap/cache
 *   --output=output/graph.json
 */

$basePath = dirname(__DIR__, 1);
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
$scanDirs = [];
$excludeDirs = null;
$outputFile = __DIR__ . '/output/graph.json';

foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if (str_starts_with($arg, '--dir=')) {
        $scanDirs[] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--exclude=')) {
        $excludeDirs = array_map('trim', explode(',', substr($arg, 10)));
    } elseif (str_starts_with($arg, '--output=')) {
        $outputFile = substr($arg, 9);
        if (!str_starts_with($outputFile, '/')) {
            $outputFile = $basePath . '/' . $outputFile;
        }
    }
}

// Defaults
if (empty($scanDirs)) {
    $scanDirs = ['.'];
}
if ($excludeDirs === null) {
    $excludeDirs = ['vendor', 'node_modules', '.claude', 'storage', 'bootstrap/cache'];
}

$dirsLabel = implode(', ', array_map(fn($d) => $d === '.' ? '.' : "{$d}/", $scanDirs));
$excludeLabel = empty($excludeDirs) ? '(none)' : implode(', ', $excludeDirs);

fwrite(STDERR, "=== PHP Dependency Graph Analyzer ===\n\n");
fwrite(STDERR, "Base path: {$basePath}\n");
fwrite(STDERR, "Scan:      {$dirsLabel}\n");
fwrite(STDERR, "Exclude:   {$excludeLabel}\n");
fwrite(STDERR, "Output:    {$outputFile}\n\n");

$startTime = microtime(true);

$analyzer = new DepsGraph\Analyzer($basePath, $scanDirs, $excludeDirs);
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
