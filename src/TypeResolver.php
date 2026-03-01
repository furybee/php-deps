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

    /** Namespace segments that are structural, not architectural layers */
    private const SKIP_SEGMENTS = [
        'abstracts', 'base', 'common', 'shared', 'core', 'concerns',
        'support', 'foundation', 'internal', 'generic', 'default',
    ];

    /** Known root namespaces to skip (first segment) */
    private const ROOT_NAMESPACES = [
        'app', 'src', 'source', 'lib', 'module', 'modules', 'domain',
        'infrastructure', 'presentation', 'application',
    ];

    /** Multi-segment namespace patterns that map to a specific layer */
    private const COMPOUND_PATTERNS = [
        'Http\\Controllers' => 'Controller',
        'Http\\Middleware' => 'Middleware',
        'Http\\Requests' => 'Request',
        'Http\\Resources' => 'Resource',
        'Http\\Integrations' => 'Integration',
    ];

    /**
     * Detect the architectural layer from a FQCN.
     *
     * Auto-infers the layer from namespace segments instead of hardcoded patterns.
     * Works with any PHP project (Laravel, Symfony, custom architectures).
     */
    public static function detectLayer(string $fqcn): string
    {
        $segments = explode('\\', $fqcn);
        array_pop($segments); // remove class name

        if (empty($segments)) {
            return 'Other';
        }

        // Check compound patterns first (Http\Controllers → Controller, etc.)
        $namespacePath = implode('\\', $segments);
        foreach (self::COMPOUND_PATTERNS as $pattern => $layer) {
            if (str_contains($namespacePath, $pattern)) {
                return $layer;
            }
        }

        // Walk segments, skip root namespaces and structural segments
        foreach ($segments as $segment) {
            $lower = strtolower($segment);

            // Skip root namespaces
            if (in_array($lower, self::ROOT_NAMESPACES, true)) {
                continue;
            }

            // Skip structural/generic segments
            if (in_array($lower, self::SKIP_SEGMENTS, true)) {
                continue;
            }

            // Found a meaningful segment — use it as layer
            return self::formatLayerName($segment);
        }

        // All segments were skipped — use the last non-root one
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $lower = strtolower($segments[$i]);
            if (!in_array($lower, self::ROOT_NAMESPACES, true)) {
                return self::formatLayerName($segments[$i]);
            }
        }

        return 'Other';
    }

    /** Explicit renames for common namespace segments */
    private const SEGMENT_ALIASES = [
        'Console' => 'Command',
        'Commands' => 'Command',
        'Exceptions' => 'Exception',
        'Helpers' => 'Helper',
        'Interfaces' => 'Contract',
        'Contracts' => 'Contract',
        'Concerns' => 'Trait',
        'Traits' => 'Trait',
        'Miscellaneous' => 'Misc',
        'Classes' => 'Class',
        'DTOs' => 'DTO',
        'Dtos' => 'DTO',
    ];

    /**
     * Clean up a namespace segment into a readable layer name.
     * "Controllers" → "Controller", "Services" → "Service", "DTOs" → "DTO"
     */
    private static function formatLayerName(string $segment): string
    {
        // Check explicit aliases first
        if (isset(self::SEGMENT_ALIASES[$segment])) {
            return self::SEGMENT_ALIASES[$segment];
        }

        // Preserve fully uppercase segments (DTO, API, etc.)
        if (strtoupper($segment) === $segment && strlen($segment) <= 5) {
            return $segment;
        }

        // Depluralize: remove trailing 's' (Services → Service, Jobs → Job, Rules → Rule)
        // But keep: Bus, Dms, Status, Address, etc.
        if (strlen($segment) > 3 && str_ends_with($segment, 's') && !str_ends_with($segment, 'ss') && !str_ends_with($segment, 'us')) {
            $segment = substr($segment, 0, -1);
        }

        return $segment;
    }
}
