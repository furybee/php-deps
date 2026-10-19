<?php

declare(strict_types=1);

namespace DepsGraph;

/**
 * Infers an architectural layer from a FQCN, in decreasing order of confidence:
 * a known layer namespace segment (deepest first), a Clean Architecture layer,
 * a known class name suffix, then the first meaningful namespace segment.
 */
final class LayerDetector
{
    private const COMPOUND_PATTERNS = [
        'Http\\Controllers' => 'Controller',
        'Http\\Middleware' => 'Middleware',
        'Http\\Requests' => 'Request',
        'Http\\Resources' => 'Resource',
        'Http\\Integrations' => 'Integration',
    ];

    /** Namespace segment (lowercase) => layer */
    private const KNOWN_SEGMENTS = [
        'controllers' => 'Controller', 'controller' => 'Controller',
        'middleware' => 'Middleware', 'middlewares' => 'Middleware',
        'requests' => 'Request', 'resources' => 'Resource',
        'services' => 'Service', 'service' => 'Service',
        'models' => 'Model', 'entities' => 'Entity', 'entity' => 'Entity',
        'actions' => 'Action', 'usecases' => 'UseCase', 'handlers' => 'Handler',
        'repositories' => 'Repository', 'repository' => 'Repository',
        'jobs' => 'Job', 'events' => 'Event', 'listeners' => 'Listener',
        'observers' => 'Observer', 'policies' => 'Policy', 'providers' => 'Provider',
        'notifications' => 'Notification', 'mail' => 'Mail',
        'console' => 'Command', 'commands' => 'Command',
        'exceptions' => 'Exception', 'enums' => 'Enum',
        'dtos' => 'DTO', 'dto' => 'DTO', 'data' => 'DTO',
        'valueobjects' => 'ValueObject', 'contracts' => 'Contract', 'interfaces' => 'Contract',
        'traits' => 'Trait', 'concerns' => 'Trait', 'casts' => 'Cast', 'rules' => 'Rule',
        'scopes' => 'Scope', 'factories' => 'Factory', 'seeders' => 'Seeder',
        'builders' => 'Builder', 'validators' => 'Validator', 'specifications' => 'Specification',
        'queries' => 'Query', 'apiclients' => 'ApiClient', 'clients' => 'ApiClient',
    ];

    private const ARCHITECTURE_LAYERS = [
        'domain' => 'Domain',
        'application' => 'Application',
        'infrastructure' => 'Infrastructure',
        'presentation' => 'Presentation',
    ];

    private const SUFFIX_TO_LAYER = [
        'Controller' => 'Controller', 'Service' => 'Service', 'Repository' => 'Repository',
        'Exception' => 'Exception', 'Validator' => 'Validator', 'Observer' => 'Observer',
        'Listener' => 'Listener', 'Event' => 'Event', 'Job' => 'Job', 'Command' => 'Command',
        'Policy' => 'Policy', 'Middleware' => 'Middleware', 'Request' => 'Request',
        'Resource' => 'Resource', 'Notification' => 'Notification', 'Mail' => 'Mail',
        'Cast' => 'Cast', 'Rule' => 'Rule', 'Scope' => 'Scope', 'Factory' => 'Factory',
        'Seeder' => 'Seeder', 'Provider' => 'Provider', 'Helper' => 'Helper', 'Action' => 'Action',
        'UseCase' => 'UseCase', 'Handler' => 'Handler', 'Transformer' => 'Transformer',
        'Presenter' => 'Presenter', 'Filter' => 'Filter', 'Adapter' => 'Adapter', 'Enum' => 'Enum',
        'Interface' => 'Contract', 'Contract' => 'Contract', 'Trait' => 'Trait', 'DTO' => 'DTO',
        'Data' => 'DTO',
    ];

    private const ROOT_SEGMENTS = ['app', 'src', 'source', 'lib', 'module', 'modules'];

    private const STRUCTURAL_SEGMENTS = [
        'abstracts', 'base', 'common', 'shared', 'core', 'support', 'foundation',
        'internal', 'generic', 'default', 'http', 'api', 'v1', 'v2', 'v3',
    ];

    private const SEGMENT_ALIASES = [
        'Helpers' => 'Helper',
        'Miscellaneous' => 'Misc',
        'Classes' => 'Class',
    ];

    public static function detect(string $fqcn): string
    {
        $segments = explode('\\', $fqcn);
        $className = array_pop($segments);

        if ($segments === []) {
            return self::detectFromSuffix($className) ?? 'Other';
        }

        $namespacePath = implode('\\', $segments);
        foreach (self::COMPOUND_PATTERNS as $pattern => $layer) {
            if (str_contains($namespacePath, $pattern)) {
                return $layer;
            }
        }

        foreach (array_reverse($segments) as $segment) {
            $layer = self::KNOWN_SEGMENTS[strtolower($segment)] ?? null;
            if ($layer !== null) {
                return $layer;
            }
        }

        foreach (array_reverse($segments) as $segment) {
            $layer = self::ARCHITECTURE_LAYERS[strtolower($segment)] ?? null;
            if ($layer !== null) {
                return $layer;
            }
        }

        return self::detectFromSuffix($className)
            ?? self::detectFromFirstMeaningfulSegment($segments)
            ?? 'Other';
    }

    private static function detectFromSuffix(string $className): ?string
    {
        foreach (self::SUFFIX_TO_LAYER as $suffix => $layer) {
            if ($className !== $suffix && str_ends_with($className, $suffix)) {
                return $layer;
            }
        }

        return null;
    }

    /**
     * @param string[] $segments
     */
    private static function detectFromFirstMeaningfulSegment(array $segments): ?string
    {
        foreach ($segments as $segment) {
            $lower = strtolower($segment);
            if (!in_array($lower, self::ROOT_SEGMENTS, true) && !in_array($lower, self::STRUCTURAL_SEGMENTS, true)) {
                return self::formatLayerName($segment);
            }
        }

        return null;
    }

    private static function formatLayerName(string $segment): string
    {
        if (isset(self::SEGMENT_ALIASES[$segment])) {
            return self::SEGMENT_ALIASES[$segment];
        }

        if (strtoupper($segment) === $segment && strlen($segment) <= 5) {
            return $segment;
        }

        if (strlen($segment) > 4 && str_ends_with($segment, 'ies')) {
            return substr($segment, 0, -3) . 'y';
        }

        if (strlen($segment) > 3 && str_ends_with($segment, 's') && !str_ends_with($segment, 'ss') && !str_ends_with($segment, 'us')) {
            return substr($segment, 0, -1);
        }

        return $segment;
    }
}
