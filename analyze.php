#!/usr/bin/env php
<?php

declare(strict_types=1);

const USAGE = <<<TXT
Usage: php-deps [options]

  --root=PATH      Project root, scan and exclude paths are relative to it (default: current directory)
  --dir=PATH       Directory to scan, repeatable (default: .)
  --exclude=LIST   Comma-separated directories to skip. A bare name (vendor) matches at any depth,
                   a path (bootstrap/cache) is relative to the root
                   (default: vendor,node_modules,.git,.claude,storage,bootstrap/cache)
  --output=FILE    JSON output, relative to the current directory (default: <php-deps>/output/graph.json)
  --pretty         Pretty-print the JSON
  --quiet          Only print errors
  --help           Show this help

TXT;

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
    getcwd() . '/vendor/autoload.php',
];

$autoload = array_values(array_filter($autoloadCandidates, 'file_exists'))[0] ?? null;
if ($autoload === null) {
    fwrite(STDERR, "Error: no vendor/autoload.php found. Run: composer install (in " . __DIR__ . ")\n");
    exit(1);
}
require_once $autoload;

if (!class_exists(DepsGraph\Analyzer::class)) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'DepsGraph\\';
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

$options = getopt('', ['root:', 'dir:', 'exclude:', 'output:', 'pretty', 'quiet', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, USAGE);
    exit(0);
}

$toAbsolute = static fn(string $path, string $base): string => str_starts_with($path, '/') ? $path : $base . '/' . $path;

$cwd = (string) getcwd();
$root = rtrim($toAbsolute($options['root'] ?? '.', $cwd), '/');
$root = realpath($root) ?: $root;
$scanDirs = (array) ($options['dir'] ?? ['.']);
$excludeDirs = isset($options['exclude'])
    ? array_filter(array_map('trim', explode(',', (string) $options['exclude'])))
    : ['vendor', 'node_modules', '.git', '.claude', 'storage', 'bootstrap/cache'];
$outputFile = $toAbsolute($options['output'] ?? __DIR__ . '/output/graph.json', $cwd);
$isQuiet = isset($options['quiet']);

if (!is_dir($root)) {
    fwrite(STDERR, "Error: root directory not found: {$root}\n");
    exit(1);
}

$log = static function (string $message) use ($isQuiet): void {
    if (!$isQuiet) {
        fwrite(STDERR, $message . "\n");
    }
};

$log("=== PHP Dependency Graph Analyzer ===\n");
$log("Root:    {$root}");
$log("Scan:    " . implode(', ', $scanDirs));
$log("Exclude: " . (empty($excludeDirs) ? '(none)' : implode(', ', $excludeDirs)));
$log("Output:  {$outputFile}\n");

$startTime = microtime(true);
$graph = (new DepsGraph\Analyzer($root, $scanDirs, $excludeDirs, $log(...)))->analyze();
$elapsed = round(microtime(true) - $startTime, 2);

$log("\nStats:");
$log("  Classes: {$graph['stats']['totalClasses']}");
$log("  Methods: {$graph['stats']['totalMethods']}");
$log("  Edges:   {$graph['stats']['totalEdges']}");
foreach ($graph['stats']['edgeTypeCounts'] as $type => $count) {
    $log("    {$type}: {$count}");
}
$log("  Layers:");
foreach ($graph['stats']['layerCounts'] as $layer => $count) {
    $log("    {$layer}: {$count}");
}

$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
$json = json_encode($graph, isset($options['pretty']) ? $flags | JSON_PRETTY_PRINT : $flags);

$outputDir = dirname($outputFile);
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Error: cannot create {$outputDir}\n");
    exit(1);
}
file_put_contents($outputFile, $json);

$sizeMb = round(strlen($json) / 1024 / 1024, 2);
$log("\nDone in {$elapsed}s. Output: {$outputFile} ({$sizeMb} MB)");
