<?php

declare(strict_types=1);

namespace DepsGraph;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeVisitorAbstract;

/**
 * Second pass: extracts method calls, instantiations and container resolutions.
 * Expects PhpParser's NameResolver to run before it in the same traverser.
 *
 * Edges are raw: targets may be external or inherited members, GraphBuilder sorts them out.
 *
 * @phpstan-import-type Edge from GraphBuilder
 */
final class CallVisitor extends NodeVisitorAbstract
{
    private const CONTAINER_FUNCTIONS = ['app', 'resolve'];
    private const CONTAINER_METHODS = ['make', 'makewith'];
    private const CONTAINER_CLASSES = ['Illuminate\Support\Facades\App', 'App', 'Illuminate\Container\Container'];

    /** @var list<Edge> */
    private array $edges = [];

    /** @var ClassScope[] */
    private array $scopes = [];

    /** @var array<array{scope: ClassScope, method: string, locals: array<string, string>}> */
    private array $frames = [];

    public function __construct(
        private readonly TypeResolver $typeResolver,
        private readonly ClassHierarchy $hierarchy,
    ) {
    }

    /**
     * @return list<Edge>
     */
    public function getEdges(): array
    {
        return $this->edges;
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->scopes = [];
        $this->frames = [];

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->scopes[] = $node->namespacedName === null
                ? ClassScope::anonymous()
                : new ClassScope($node->namespacedName->toString(), $this->hierarchy->parentOf($node->namespacedName->toString()));
            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->enterMethod($node);
            return null;
        }

        if ($this->frames === []) {
            return null;
        }

        $this->collectLocalTypes($node);
        $this->collectEdges($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->scopes);
        }

        if ($node instanceof Node\Stmt\ClassMethod && $this->isInNamedClass()) {
            array_pop($this->frames);
        }

        return null;
    }

    private function isInNamedClass(): bool
    {
        $scope = end($this->scopes);

        return $scope !== false && !$scope->isAnonymous();
    }

    private function enterMethod(Node\Stmt\ClassMethod $node): void
    {
        if (!$this->isInNamedClass()) {
            return;
        }
        $scope = $this->currentScope();

        $method = $node->name->toString();
        $this->frames[] = [
            'scope' => $scope,
            'method' => $method,
            'locals' => array_filter($this->hierarchy->methodParams((string) $scope->class, $method)),
        ];
    }

    /**
     * Calls made inside an anonymous class are attributed to the enclosing named method,
     * but `$this` there is unknown.
     */
    private function currentScope(): ClassScope
    {
        return end($this->scopes) ?: ClassScope::anonymous();
    }

    private function collectLocalTypes(Node $node): void
    {
        $frameIndex = array_key_last($this->frames);

        if ($node instanceof Node\Stmt && $node->getDocComment() !== null) {
            foreach ($this->typeResolver->resolveDocVariables($node->getDocComment(), $this->currentScope()) as $name => $type) {
                $this->frames[$frameIndex]['locals'][$name] = $type;
            }
        }

        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $type = $this->inferType($node->expr);
            if ($type === null) {
                unset($this->frames[$frameIndex]['locals'][$node->var->name]);
            } else {
                $this->frames[$frameIndex]['locals'][$node->var->name] = $type;
            }
            return;
        }

        if ($node instanceof Expr\Instanceof_ && $node->expr instanceof Expr\Variable && is_string($node->expr->name) && $node->class instanceof Node\Name) {
            $type = $this->typeResolver->resolveName($node->class, $this->currentScope());
            if ($type !== null) {
                $this->frames[$frameIndex]['locals'][$node->expr->name] = $type;
            }
            return;
        }

        if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            foreach ($node->params as $param) {
                $type = $this->typeResolver->resolveTypeNode($param->type, $this->currentScope());
                if ($type !== null && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    $this->frames[$frameIndex]['locals'][$param->var->name] = $type;
                }
            }
        }
    }

    private function collectEdges(Node $node): void
    {
        $frame = end($this->frames);
        if ($frame === false) {
            return;
        }
        $from = $frame['scope']->class . '::' . $frame['method'];
        $line = $node->getStartLine();

        if ($node instanceof Expr\New_ && $node->class instanceof Node\Name) {
            $this->addEdge($from, $this->typeResolver->resolveName($node->class, $this->currentScope()), 'instantiates', $line);
            return;
        }

        $resolved = $this->containerResolution($node);
        if ($resolved !== null) {
            $this->addEdge($from, $resolved, 'resolves', $line);
            return;
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $class = $this->typeResolver->resolveName($node->class, $this->currentScope());
            $this->addEdge($from, $class === null ? null : $class . '::' . $node->name->toString(), 'calls', $line);
            return;
        }

        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
            $class = $this->inferType($node->var);
            $this->addEdge($from, $class === null ? null : $class . '::' . $node->name->toString(), 'calls', $line);
            return;
        }

        foreach ($this->referencedNames($node) as $name) {
            $this->addEdge($from, $this->typeResolver->resolveName($name, $this->currentScope()), 'references', $line);
        }
    }

    /**
     * Class names used without being called: Foo::BAR, Foo::class, Foo::$bar, instanceof, catch, attributes.
     *
     * @return Node\Name[]
     */
    private function referencedNames(Node $node): array
    {
        return match (true) {
            $node instanceof Expr\ClassConstFetch,
            $node instanceof Expr\StaticPropertyFetch,
            $node instanceof Expr\Instanceof_ => $node->class instanceof Node\Name ? [$node->class] : [],
            $node instanceof Node\Stmt\Catch_ => $node->types,
            $node instanceof Node\Attribute => [$node->name],
            default => [],
        };
    }

    private function inferType(Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof Expr\Variable => $this->inferVariableType($expr),
            $expr instanceof Expr\New_ => $expr->class instanceof Node\Name
                ? $this->typeResolver->resolveName($expr->class, $this->currentScope())
                : null,
            $expr instanceof Expr\PropertyFetch, $expr instanceof Expr\NullsafePropertyFetch => $this->inferPropertyType($expr),
            $expr instanceof Expr\StaticPropertyFetch => $expr->class instanceof Node\Name && $expr->name instanceof Node\VarLikeIdentifier
                ? $this->propertyType($this->typeResolver->resolveName($expr->class, $this->currentScope()), $expr->name->toString())
                : null,
            $expr instanceof Expr\MethodCall, $expr instanceof Expr\NullsafeMethodCall => $this->containerResolution($expr)
                ?? ($expr->name instanceof Node\Identifier ? $this->returnType($this->inferType($expr->var), $expr->name->toString()) : null),
            $expr instanceof Expr\StaticCall => $this->containerResolution($expr)
                ?? ($expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
                    ? $this->returnType($this->typeResolver->resolveName($expr->class, $this->currentScope()), $expr->name->toString())
                    : null),
            $expr instanceof Expr\FuncCall => $this->containerResolution($expr),
            $expr instanceof Expr\Clone_ => $this->inferType($expr->expr),
            $expr instanceof Expr\Assign => $this->inferType($expr->expr),
            $expr instanceof Expr\BinaryOp\Coalesce => $this->inferType($expr->left) ?? $this->inferType($expr->right),
            $expr instanceof Expr\Ternary => ($expr->if !== null ? $this->inferType($expr->if) : null) ?? $this->inferType($expr->else),
            default => null,
        };
    }

    private function inferVariableType(Expr\Variable $variable): ?string
    {
        if (!is_string($variable->name)) {
            return null;
        }

        if ($variable->name === 'this') {
            return $this->currentScope()->class;
        }

        $frame = end($this->frames);

        return $frame === false ? null : ($frame['locals'][$variable->name] ?? null);
    }

    private function inferPropertyType(Expr\PropertyFetch|Expr\NullsafePropertyFetch $fetch): ?string
    {
        if (!$fetch->name instanceof Node\Identifier) {
            return null;
        }

        return $this->propertyType($this->inferType($fetch->var), $fetch->name->toString());
    }

    private function propertyType(?string $class, string $property): ?string
    {
        return $class === null ? null : $this->hierarchy->propertyType($class, $property);
    }

    private function returnType(?string $class, string $method): ?string
    {
        if ($class === null) {
            return null;
        }

        $type = $this->hierarchy->methodReturnType($class, $method);

        // Fluent methods declared `: static` / `: self` in a parent return the caller's class
        $found = $this->hierarchy->findMethod($class, $method);
        if ($found !== null && $type === $found[0]) {
            return $class;
        }

        return $type;
    }

    /**
     * app(Foo::class), resolve(Foo::class), app()->make(Foo::class), App::make(Foo::class)
     */
    private function containerResolution(Node $node): ?string
    {
        $isContainerCall = match (true) {
            $node instanceof Expr\FuncCall => $node->name instanceof Node\Name
                && in_array(strtolower($node->name->getLast()), self::CONTAINER_FUNCTIONS, true),
            $node instanceof Expr\MethodCall => $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), self::CONTAINER_METHODS, true)
                && $node->var instanceof Expr\FuncCall
                && $node->var->name instanceof Node\Name
                && strtolower($node->var->name->getLast()) === 'app',
            $node instanceof Expr\StaticCall => $node->class instanceof Node\Name
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toLowerString(), self::CONTAINER_METHODS, true)
                && in_array($node->class->toString(), self::CONTAINER_CLASSES, true),
            default => false,
        };

        if (!$isContainerCall || $node->isFirstClassCallable()) {
            return null;
        }

        $firstArg = $node->getArgs()[0]->value ?? null;
        if (!$firstArg instanceof Expr\ClassConstFetch
            || !$firstArg->class instanceof Node\Name
            || !$firstArg->name instanceof Node\Identifier
            || $firstArg->name->toLowerString() !== 'class') {
            return null;
        }

        return $this->typeResolver->resolveName($firstArg->class, $this->currentScope());
    }

    private function addEdge(string $from, ?string $to, string $type, int $line): void
    {
        if ($to === null) {
            return;
        }

        $this->edges[] = ['source' => $from, 'target' => $to, 'type' => $type, 'line' => $line];
    }
}
