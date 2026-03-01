<?php

declare(strict_types=1);

namespace DepsGraph;

final class TypeResolver
{
    /** @var array<string, string> alias => FQCN */
    private array $useMap = [];

    private string $currentNamespace = '';

    /** @var array<string, string> property name => FQCN */
    private array $propertyTypes = [];

    /** @var array<string, string> param name => FQCN */
    private array $paramTypes = [];

    /** @var array<string, array<string, string>> class FQCN => [property => FQCN] */
    private array $constructorInjections = [];

    public function setNamespace(string $namespace): void
    {
        $this->currentNamespace = $namespace;
    }

    public function setUseMap(array $useMap): void
    {
        $this->useMap = $useMap;
    }

    public function setPropertyTypes(array $propertyTypes): void
    {
        $this->propertyTypes = $propertyTypes;
    }

    public function setParamTypes(array $paramTypes): void
    {
        $this->paramTypes = $paramTypes;
    }

    public function registerConstructorInjections(string $classFqcn, array $injections): void
    {
        $this->constructorInjections[$classFqcn] = $injections;
    }

    public function getConstructorInjections(string $classFqcn): array
    {
        return $this->constructorInjections[$classFqcn] ?? [];
    }

    /**
     * Resolve a class name to its FQCN using the current context.
     */
    public function resolve(string $name): ?string
    {
        if ($name === 'self' || $name === 'static' || $name === '$this') {
            return null; // handled by caller with current class context
        }

        // Already fully qualified
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // Check use map (alias or short name)
        $parts = explode('\\', $name);
        $first = $parts[0];

        if (isset($this->useMap[$first])) {
            if (count($parts) === 1) {
                return $this->useMap[$first];
            }
            array_shift($parts);
            return $this->useMap[$first] . '\\' . implode('\\', $parts);
        }

        // Relative to current namespace
        if ($this->currentNamespace) {
            return $this->currentNamespace . '\\' . $name;
        }

        return $name;
    }

    /**
     * Resolve a PhpParser type node to a FQCN string.
     */
    public function resolveTypeNode($typeNode): ?string
    {
        if ($typeNode === null) {
            return null;
        }

        if ($typeNode instanceof \PhpParser\Node\Name) {
            return $this->resolve($typeNode->toString());
        }

        if ($typeNode instanceof \PhpParser\Node\Identifier) {
            $name = $typeNode->toString();
            // Skip scalar types
            if (in_array($name, ['int', 'string', 'float', 'bool', 'array', 'void', 'null', 'mixed', 'never', 'true', 'false', 'callable', 'iterable', 'object'], true)) {
                return null;
            }
            return $this->resolve($name);
        }

        if ($typeNode instanceof \PhpParser\Node\NullableType) {
            return $this->resolveTypeNode($typeNode->type);
        }

        if ($typeNode instanceof \PhpParser\Node\UnionType || $typeNode instanceof \PhpParser\Node\IntersectionType) {
            // Return the first non-null class type
            foreach ($typeNode->types as $type) {
                $resolved = $this->resolveTypeNode($type);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
            return null;
        }

        return null;
    }

    /**
     * Resolve a variable name to its type using current context.
     */
    public function resolveVariable(string $varName): ?string
    {
        // Check method parameters first
        if (isset($this->paramTypes[$varName])) {
            return $this->paramTypes[$varName];
        }

        // Check property types (for $this->prop access)
        if (isset($this->propertyTypes[$varName])) {
            return $this->propertyTypes[$varName];
        }

        return null;
    }

    /**
     * Detect the architectural layer from a FQCN.
     */
    public static function detectLayer(string $fqcn): string
    {
        if (str_contains($fqcn, '\\Http\\Controllers\\')) return 'Controller';
        if (str_contains($fqcn, '\\Services\\')) return 'Service';
        if (str_contains($fqcn, '\\Models\\') || preg_match('/^App\\\\(Company|Ht|View)[A-Z]/', $fqcn)) return 'Model';
        if (str_contains($fqcn, '\\Actions\\')) return 'Action';
        if (str_contains($fqcn, '\\Jobs\\')) return 'Job';
        if (str_contains($fqcn, '\\Events\\')) return 'Event';
        if (str_contains($fqcn, '\\Listeners\\')) return 'Listener';
        if (str_contains($fqcn, '\\Repository\\')) return 'Repository';
        if (str_contains($fqcn, '\\Http\\Requests\\')) return 'Request';
        if (str_contains($fqcn, '\\Http\\Resources\\')) return 'Resource';
        if (str_contains($fqcn, '\\UseCases\\')) return 'UseCase';
        if (str_contains($fqcn, '\\Observers\\')) return 'Observer';
        if (str_contains($fqcn, '\\Notifications\\')) return 'Notification';
        if (str_contains($fqcn, '\\Enums\\')) return 'Enum';
        if (str_contains($fqcn, '\\Dtos\\')) return 'DTO';
        if (str_contains($fqcn, '\\Contracts\\') || str_contains($fqcn, '\\Interfaces\\')) return 'Contract';
        if (str_contains($fqcn, '\\Traits\\') || str_contains($fqcn, '\\Concerns\\')) return 'Trait';
        if (str_contains($fqcn, '\\Providers\\')) return 'Provider';
        if (str_contains($fqcn, '\\Console\\')) return 'Command';
        if (str_contains($fqcn, '\\Exports\\')) return 'Export';
        if (str_contains($fqcn, '\\Http\\Middleware\\')) return 'Middleware';

        return 'Other';
    }
}
