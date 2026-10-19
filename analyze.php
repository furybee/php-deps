#!/usr/bin/env php
<?php

declare(strict_types=1);

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

exit((new DepsGraph\Console\AnalyzeCommand(__DIR__ . '/output/graph.json'))->run(array_slice($argv, 1), (string) getcwd()));
