<?php

declare(strict_types=1);

namespace DepsGraph;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * First pass: extracts class metadata, methods, properties and DI.
 * Expects PhpParser's NameResolver to run before it in the same traverser.
 *
 * @phpstan-type MethodData array{visibility: string, params: array<string, string|null>, returnType: string|null, static: bool, line: int}
 * @phpstan-type ClassData array{fqcn: string, name: string, namespace: string, type: string, layer: string, line: int, methods: array<string, MethodData>, properties: array<string, string>, extends: list<string>, implements: list<string>, traits: list<string>, constructorParams: array<string, string|null>, file?: string}
 */
final class ClassVisitor extends NodeVisitorAbstract
{
    /** @var array<string, ClassData> keyed by FQCN */
    private array $classes = [];

    /** @var ClassScope[] */
    private array $scopes = [];

    public function __construct(private readonly TypeResolver $typeResolver)
    {
    }

    /**
     * @return array<string, ClassData>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->classes = [];
        $this->scopes = [];

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->enterClassLike($node);
            return null;
        }

        $scope = end($this->scopes);
        $class = $scope === false ? null : $scope->class;
        if ($scope === false || $class === null) {
            return null;
        }

        match (true) {
            $node instanceof Node\Stmt\TraitUse => $this->addTraits($node, $class),
            $node instanceof Node\Stmt\Property => $this->addProperty($node, $class, $scope),
            $node instanceof Node\Stmt\ClassMethod => $this->addMethod($node, $class, $scope),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->scopes);
        }

        return null;
    }

    private function enterClassLike(Node\Stmt\ClassLike $node): void
    {
        if ($node->namespacedName === null) {
            $this->scopes[] = ClassScope::anonymous();
            return;
        }

        $fqcn = $node->namespacedName->toString();
        $parents = match (true) {
            $node instanceof Node\Stmt\Class_ && $node->extends !== null => [$node->extends->toString()],
            $node instanceof Node\Stmt\Interface_ => array_values(array_map(fn(Node\Name $n) => $n->toString(), $node->extends)),
            default => [],
        };
        $implements = $node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_
            ? array_values(array_map(fn(Node\Name $n) => $n->toString(), $node->implements))
            : [];

        $this->scopes[] = new ClassScope($fqcn, $node instanceof Node\Stmt\Class_ ? ($parents[0] ?? null) : null);

        $namespace = $node->namespacedName->slice(0, -1)?->toString() ?? '';

        $this->classes[$fqcn] = [
            'fqcn' => $fqcn,
            'name' => $node->name?->toString() ?? $fqcn,
            'namespace' => $namespace,
            'type' => match (true) {
                $node instanceof Node\Stmt\Interface_ => 'interface',
                $node instanceof Node\Stmt\Trait_ => 'trait',
                $node instanceof Node\Stmt\Enum_ => 'enum',
                $node instanceof Node\Stmt\Class_ && $node->isAbstract() => 'abstract',
                default => 'class',
            },
            'layer' => LayerDetector::detect($fqcn),
            'line' => $node->getStartLine(),
            'methods' => [],
            'properties' => [],
            'extends' => $parents,
            'implements' => $implements,
            'traits' => [],
            'constructorParams' => [],
        ];
    }

    private function addTraits(Node\Stmt\TraitUse $node, string $class): void
    {
        foreach ($node->traits as $trait) {
            $this->classes[$class]['traits'][] = $trait->toString();
        }
    }

    private function addProperty(Node\Stmt\Property $node, string $class, ClassScope $scope): void
    {
        foreach ($node->props as $prop) {
            $type = $this->typeResolver->resolveTypeNode($node->type, $scope)
                ?? $this->typeResolver->resolveDocTag($node->getDocComment(), 'var', null, $scope);

            if ($type !== null) {
                $this->classes[$class]['properties'][$prop->name->toString()] = $type;
            }
        }
    }

    private function addMethod(Node\Stmt\ClassMethod $node, string $class, ClassScope $scope): void
    {
        $methodName = $node->name->toString();
        $doc = $node->getDocComment();

        $params = [];
        foreach ($node->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            $paramName = $param->var->name;
            $paramType = $this->typeResolver->resolveTypeNode($param->type, $scope)
                ?? $this->typeResolver->resolveDocTag($doc, 'param', $paramName, $scope);
            $params[$paramName] = $paramType;

            if ($param->isPromoted() && $paramType !== null) {
                $this->classes[$class]['properties'][$paramName] = $paramType;
            }
        }

        if (strtolower($methodName) === '__construct') {
            $this->classes[$class]['constructorParams'] = $params;
        }

        $this->classes[$class]['methods'][$methodName] = [
            'visibility' => $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public'),
            'params' => $params,
            'returnType' => $this->typeResolver->resolveTypeNode($node->returnType, $scope)
                ?? $this->typeResolver->resolveDocTag($doc, 'return', null, $scope),
            'static' => $node->isStatic(),
            'line' => $node->getStartLine(),
        ];
    }
}
