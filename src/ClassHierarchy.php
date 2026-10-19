<?php

declare(strict_types=1);

namespace DepsGraph;

/**
 * Member lookups that follow inheritance: own members first, then traits,
 * parent classes and interfaces. Method names are case-insensitive, as in PHP.
 *
 * @phpstan-import-type ClassData from ClassVisitor
 */
final class ClassHierarchy
{
    /** @var array<string, array<string, string>> class => [lowercase method => declared name] */
    private array $methodIndex = [];

    /** @var array<string, array{0: string, 1: string}|null> */
    private array $declaringCache = [];

    /**
     * @param array<string, ClassData> $classMap
     */
    public function __construct(private readonly array $classMap)
    {
        foreach ($classMap as $fqcn => $data) {
            foreach (array_keys($data['methods']) as $method) {
                $this->methodIndex[$fqcn][strtolower($method)] = $method;
            }
        }
    }

    public function has(string $class): bool
    {
        return isset($this->classMap[$class]);
    }

    /**
     * @return array{0: string, 1: string}|null [declaring class, declared method name]
     */
    public function findMethod(string $class, string $method): ?array
    {
        $key = $class . '::' . strtolower($method);
        if (array_key_exists($key, $this->declaringCache)) {
            return $this->declaringCache[$key];
        }

        $found = null;
        foreach ($this->ancestry($class) as $candidate) {
            $declared = $this->methodIndex[$candidate][strtolower($method)] ?? null;
            if ($declared !== null) {
                $found = [$candidate, $declared];
                break;
            }
        }

        return $this->declaringCache[$key] = $found;
    }

    public function methodReturnType(string $class, string $method): ?string
    {
        $found = $this->findMethod($class, $method);
        if ($found === null) {
            return null;
        }

        return $this->classMap[$found[0]]['methods'][$found[1]]['returnType'] ?? null;
    }

    /**
     * @return array<string, string|null> param name => type
     */
    public function methodParams(string $class, string $method): array
    {
        return $this->classMap[$class]['methods'][$method]['params'] ?? [];
    }

    public function propertyType(string $class, string $property): ?string
    {
        foreach ($this->ancestry($class) as $candidate) {
            $type = $this->classMap[$candidate]['properties'][$property] ?? null;
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }

    public function parentOf(string $class): ?string
    {
        return $this->classMap[$class]['extends'][0] ?? null;
    }

    /**
     * Breadth-first: the class itself, its traits, parents, then interfaces.
     *
     * @return iterable<string>
     */
    private function ancestry(string $class): iterable
    {
        $queue = [$class];
        $seen = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current]) || !isset($this->classMap[$current])) {
                continue;
            }
            $seen[$current] = true;

            yield $current;

            $data = $this->classMap[$current];
            array_push($queue, ...$data['traits'], ...$data['extends'], ...$data['implements']);
        }
    }
}
