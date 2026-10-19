<?php

declare(strict_types=1);

namespace DepsGraph\Console;

use DepsGraph\Analyzer;
use DepsGraph\Architecture\ArchitectureConfig;
use DepsGraph\Architecture\Baseline;
use DepsGraph\Architecture\CheckResult;
use DepsGraph\Architecture\DependencyRuleChecker;
use DepsGraph\Architecture\InvalidConfigException;
use DepsGraph\Architecture\Reporter\GithubReporter;
use DepsGraph\Architecture\Reporter\GitlabReporter;
use DepsGraph\Architecture\Reporter\Reporter;
use DepsGraph\Architecture\Reporter\TextReporter;

final class AnalyzeCommand
{
    public const SUCCESS = 0;
    public const VIOLATIONS = 1;
    public const INVALID_USAGE = 2;

    private const DEFAULT_CONFIG = 'php-deps.php';
    private const DEFAULT_BASELINE = 'php-deps-baseline.json';
    private const DEFAULT_EXCLUDE = ['vendor', 'node_modules', '.git', '.claude', 'storage', 'bootstrap/cache'];
    private const VALUE_OPTIONS = ['root', 'dir', 'exclude', 'output', 'config', 'format'];
    private const FLAG_OPTIONS = ['pretty', 'quiet', 'help', 'check', 'generate-baseline'];

    private const USAGE = <<<TXT
Usage: php-deps [options]

Graph:
  --root=PATH           Project root, scan and exclude paths are relative to it (default: current directory)
  --dir=PATH            Directory to scan, repeatable (default: config "paths", else .)
  --exclude=LIST        Comma-separated directories to skip. A bare name (vendor) matches at any depth,
                        a path (bootstrap/cache) is relative to the root
                        (default: config "exclude", else vendor,node_modules,.git,.claude,storage,bootstrap/cache)
  --output=FILE         JSON graph output, relative to the current directory
                        (default: <php-deps>/output/graph.json, not written with --check unless given)
  --pretty              Pretty-print the JSON

Architecture rules:
  --config=FILE         Rules file (default: <root>/php-deps.php when present)
  --check               Check the dependency rules, exit 1 on violations not in the baseline
  --format=FORMAT       text, github (Actions annotations) or gitlab (Code Quality report) (default: text)
  --generate-baseline   Accept every current violation into the baseline file

  --quiet               Only print errors and the report
  --help                Show this help

TXT;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(
        private readonly string $defaultOutput,
        $stdout = null,
        $stderr = null,
    ) {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * @param string[] $arguments without the script name
     */
    public function run(array $arguments, string $cwd): int
    {
        try {
            $options = $this->parseOptions($arguments);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->stderr, "Error: {$e->getMessage()}\n\n" . self::USAGE);
            return self::INVALID_USAGE;
        }

        if (isset($options['help'])) {
            fwrite($this->stdout, self::USAGE);
            return self::SUCCESS;
        }

        $root = self::absolute($options['root'][0] ?? '.', $cwd);
        $root = rtrim(realpath($root) ?: $root, '/');
        if (!is_dir($root)) {
            fwrite($this->stderr, "Error: root directory not found: {$root}\n");
            return self::INVALID_USAGE;
        }

        try {
            $config = $this->loadConfig($options, $root, $cwd);
        } catch (InvalidConfigException $e) {
            fwrite($this->stderr, "Error: {$e->getMessage()}\n");
            return self::INVALID_USAGE;
        }

        $isChecking = isset($options['check']) || isset($options['generate-baseline']);
        if ($isChecking && !$config->hasRules()) {
            fwrite($this->stderr, "Error: no rules to check, create " . self::DEFAULT_CONFIG . " or pass --config\n");
            return self::INVALID_USAGE;
        }

        $reporter = $this->reporter($options['format'][0] ?? 'text');
        if ($reporter === null) {
            fwrite($this->stderr, "Error: unknown format, expected text, github or gitlab\n");
            return self::INVALID_USAGE;
        }

        $graph = $this->analyze($options, $config, $root);

        if (!$isChecking || isset($options['output'])) {
            $this->writeGraph($graph, self::absolute($options['output'][0] ?? $this->defaultOutput, $cwd), isset($options['pretty']));
        }

        if (!$isChecking) {
            return self::SUCCESS;
        }

        $violations = (new DependencyRuleChecker($config))->check($graph);
        $baselineFile = $config->baseline ?? $root . '/' . self::DEFAULT_BASELINE;

        if (isset($options['generate-baseline'])) {
            Baseline::write($baselineFile, $violations);
            fwrite($this->stderr, count($violations) . " violation(s) written to {$baselineFile}\n");
            return self::SUCCESS;
        }

        $result = CheckResult::from($violations, Baseline::load($baselineFile));
        fwrite($this->stdout, $reporter->report($result));

        return $result->isSuccessful() ? self::SUCCESS : self::VIOLATIONS;
    }

    /**
     * @param array<string, string[]> $options
     * @return array{nodes: array<mixed>, edges: array<mixed>, stats: array<string, mixed>}
     */
    private function analyze(array $options, ArchitectureConfig $config, string $root): array
    {
        $scanDirs = $options['dir'] ?? ($config->paths ?: ['.']);
        $excludeDirs = isset($options['exclude'])
            ? array_values(array_filter(array_map('trim', explode(',', $options['exclude'][0]))))
            : ($config->exclude ?? self::DEFAULT_EXCLUDE);

        $isQuiet = isset($options['quiet']);
        $log = function (string $message) use ($isQuiet): void {
            if (!$isQuiet) {
                fwrite($this->stderr, $message . "\n");
            }
        };

        $log("Root:    {$root}");
        $log("Scan:    " . implode(', ', $scanDirs));
        $log("Exclude: " . ($excludeDirs === [] ? '(none)' : implode(', ', $excludeDirs)) . "\n");

        $startTime = microtime(true);
        $graph = (new Analyzer($root, $scanDirs, $excludeDirs, $log(...)))->analyze();

        $stats = $graph['stats'];
        $log("\nClasses: {$stats['totalClasses']}, methods: {$stats['totalMethods']}, edges: {$stats['totalEdges']}");
        foreach ($stats['edgeTypeCounts'] as $type => $count) {
            $log("  {$type}: {$count}");
        }
        $log(sprintf("Analyzed in %.2fs\n", microtime(true) - $startTime));

        return $graph;
    }

    /**
     * @param array<string, mixed> $graph
     */
    private function writeGraph(array $graph, string $outputFile, bool $isPretty): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        $json = json_encode($graph, $isPretty ? $flags | JSON_PRETTY_PRINT : $flags);

        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        file_put_contents($outputFile, $json);

        fwrite($this->stderr, sprintf("Graph written to %s (%.2f MB)\n", $outputFile, strlen($json) / 1024 / 1024));
    }

    /**
     * @param array<string, string[]> $options
     */
    private function loadConfig(array $options, string $root, string $cwd): ArchitectureConfig
    {
        if (isset($options['config'])) {
            return ArchitectureConfig::fromFile(self::absolute($options['config'][0], $cwd));
        }

        $defaultConfig = $root . '/' . self::DEFAULT_CONFIG;

        return is_file($defaultConfig) ? ArchitectureConfig::fromFile($defaultConfig) : new ArchitectureConfig();
    }

    private function reporter(string $format): ?Reporter
    {
        return match ($format) {
            'text' => new TextReporter(),
            'github' => new GithubReporter(),
            'gitlab' => new GitlabReporter(),
            default => null,
        };
    }

    /**
     * @param string[] $arguments
     * @return array<string, string[]> option => values, flags hold an empty list
     */
    private function parseOptions(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $match)) {
                throw new \InvalidArgumentException("unexpected argument \"{$argument}\"");
            }

            $name = $match[1];
            $value = $match[2] ?? null;
            $isValueOption = in_array($name, self::VALUE_OPTIONS, true);

            if (!$isValueOption && !in_array($name, self::FLAG_OPTIONS, true)) {
                throw new \InvalidArgumentException("unknown option --{$name}");
            }
            if ($isValueOption && ($value === null || $value === '')) {
                throw new \InvalidArgumentException("--{$name} expects a value");
            }

            $options[$name] ??= [];
            if ($value !== null) {
                $options[$name][] = $value;
            }
        }

        return $options;
    }

    private static function absolute(string $path, string $base): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($base, '/') . '/' . $path;
    }
}
