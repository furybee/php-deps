<?php

declare(strict_types=1);

namespace DepsGraph;

use PhpParser\Comment\Doc;
use PhpParser\NameContext;
use PhpParser\Node;

/**
 * Turns type declarations and PHPDoc tags into class FQCNs.
 *
 * Relies on PhpParser's NameResolver having run first: every Node\Name is
 * then already fully qualified, except the special self/static/parent names.
 */
final class TypeResolver
{
    private const NON_CLASS_TYPES = [
        'int', 'integer', 'string', 'float', 'double', 'bool', 'boolean', 'array', 'void', 'null',
        'mixed', 'never', 'true', 'false', 'callable', 'iterable', 'object', 'resource',
        'self', 'static', 'parent', '$this', 'scalar', 'numeric', 'array-key', 'class-string',
        'positive-int', 'negative-int', 'non-empty-string', 'non-empty-array', 'list', 'non-empty-list',
    ];

    public function __construct(private readonly NameContext $nameContext)
    {
    }

    public function resolveTypeNode(?Node $typeNode, ClassScope $scope): ?string
    {
        if ($typeNode === null) {
            return null;
        }

        if ($typeNode instanceof Node\Name) {
            return $this->resolveName($typeNode, $scope);
        }

        if ($typeNode instanceof Node\NullableType) {
            return $this->resolveTypeNode($typeNode->type, $scope);
        }

        if ($typeNode instanceof Node\UnionType || $typeNode instanceof Node\IntersectionType) {
            foreach ($typeNode->types as $type) {
                $resolved = $this->resolveTypeNode($type, $scope);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    public function resolveName(Node\Name $name, ClassScope $scope): ?string
    {
        return match (strtolower($name->toString())) {
            'self', 'static' => $scope->class,
            'parent' => $scope->parent,
            default => $name->toString(),
        };
    }

    /**
     * Reads the first class type of a `@tag Type [$name]` PHPDoc entry.
     * With a null $variable, the first tag occurrence wins.
     */
    public function resolveDocTag(?Doc $doc, string $tag, ?string $variable, ClassScope $scope): ?string
    {
        if ($doc === null) {
            return null;
        }

        $pattern = '/@(?:phpstan-|psalm-)?' . preg_quote($tag, '/') . '\s+([^\s$]+)(?:\s+\$(\w+))?/';
        if (!preg_match_all($pattern, $doc->getText(), $matches, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($matches as $match) {
            if ($variable !== null && ($match[2] ?? null) !== $variable) {
                continue;
            }

            return $this->resolveDocType($match[1], $scope);
        }

        return null;
    }

    /**
     * Reads inline `@var Type $name` annotations.
     *
     * @return array<string, string> variable name => FQCN
     */
    public function resolveDocVariables(?Doc $doc, ClassScope $scope): array
    {
        if ($doc === null || !preg_match_all('/@(?:phpstan-|psalm-)?var\s+([^\s$]+)\s+\$(\w+)/', $doc->getText(), $matches, PREG_SET_ORDER)) {
            return [];
        }

        $types = [];
        foreach ($matches as $match) {
            $type = $this->resolveDocType($match[1], $scope);
            if ($type !== null) {
                $types[$match[2]] = $type;
            }
        }

        return $types;
    }

    private function resolveDocType(string $docType, ClassScope $scope): ?string
    {
        $withoutGenerics = preg_replace('/<.*$/', '', $docType) ?? $docType;

        foreach (explode('|', str_replace(['(', ')', '?'], '', $withoutGenerics)) as $candidate) {
            $lower = strtolower($candidate);
            if (in_array($lower, ['self', 'static', '$this'], true)) {
                return $scope->class;
            }

            // `Foo[]` describes a collection, calling a method on it never reaches Foo
            $isClassName = preg_match('/^\\\\?[A-Za-z_][\w\\\\]*$/', $candidate) === 1;
            if (!$isClassName || in_array($lower, self::NON_CLASS_TYPES, true)) {
                continue;
            }

            if (str_starts_with($candidate, '\\')) {
                return ltrim($candidate, '\\');
            }

            return $this->nameContext->getResolvedClassName(new Node\Name($candidate))->toString();
        }

        return null;
    }
}
