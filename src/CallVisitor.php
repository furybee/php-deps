<?php

declare(strict_types=1);

namespace DepsGraph;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Second pass: extracts method calls, instantiations, static calls, and app() calls.
 */
final class CallVisitor extends NodeVisitorAbstract
{
    /** @var array<array{from: string, to: string, type: string}> */
    private array $edges = [];

    private TypeResolver $typeResolver;

    /** @var array<string, array> class data from ClassVisitor */
    private array $classMap;

    private ?string $currentClass = null;
    private ?string $currentMethod = null;
    private string $currentNamespace = '';

    /** @var array<string, string> use map for current file */
    private array $useMap = [];

    /** @var array<string, string> local var types in current method: varName => FQCN */
    private array $localVarTypes = [];

    public function __construct(TypeResolver $typeResolver, array $classMap)
    {
        $this->typeResolver = $typeResolver;
        $this->classMap = $classMap;
    }

    public function getEdges(): array
    {
        return $this->edges;
    }

    public function setContext(string $namespace, array $useMap): void
    {
        $this->currentNamespace = $namespace;
        $this->useMap = $useMap;
        $this->typeResolver->setNamespace($namespace);
        $this->typeResolver->setUseMap($useMap);
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : '';
            return null;
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            if ($node->name === null) return null;
            $name = $node->name->toString();
            $this->currentClass = $this->currentNamespace ? $this->currentNamespace . '\\' . $name : $name;
            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = $node->name->toString();
            $this->localVarTypes = [];

            // Register method params as local var types
            if ($this->currentClass && isset($this->classMap[$this->currentClass]['methods'][$this->currentMethod])) {
                $params = $this->classMap[$this->currentClass]['methods'][$this->currentMethod]['params'] ?? [];
                foreach ($params as $paramName => $paramType) {
                    if ($paramType) {
                        $this->localVarTypes[ltrim($paramName, '$')] = $paramType;
                    }
                }
            }
            return null;
        }

        if ($this->currentClass === null || $this->currentMethod === null) {
            return null;
        }

        $from = $this->currentClass . '::' . $this->currentMethod;

        // new ClassName()
        if ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $className = $node->class->toString();
                if ($className === 'self' || $className === 'static') {
                    $resolved = $this->currentClass;
                } else {
                    $resolved = $this->typeResolver->resolve($className);
                }
                if ($resolved && $this->isAppClass($resolved)) {
                    $this->addEdge($from, $resolved, 'instantiates');
                }
            }
            return null;
        }

        // Static call: ClassName::method()
        if ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $className = $node->class->toString();
                if ($className === 'self' || $className === 'static' || $className === 'parent') {
                    $resolved = $this->currentClass;
                } else {
                    $resolved = $this->typeResolver->resolve($className);
                }
                $method = $node->name->toString();
                if ($resolved && $this->isAppClass($resolved)) {
                    $this->addEdge($from, $resolved . '::' . $method, 'calls');
                }
            }
            return null;
        }

        // app(ClassName::class) — Laravel container resolution
        if ($node instanceof Node\Expr\FuncCall) {
            if ($node->name instanceof Node\Name && $node->name->toString() === 'app') {
                if (!empty($node->args) && $node->args[0] instanceof Node\Arg) {
                    $arg = $node->args[0]->value;
                    if ($arg instanceof Node\Expr\ClassConstFetch && $arg->name instanceof Node\Identifier && $arg->name->toString() === 'class') {
                        if ($arg->class instanceof Node\Name) {
                            $resolved = $this->typeResolver->resolve($arg->class->toString());
                            if ($resolved && $this->isAppClass($resolved)) {
                                $this->addEdge($from, $resolved, 'resolves');
                            }
                        }
                    }
                }
            }
            return null;
        }

        // $this->method() or $this->property->method()
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $method = $node->name->toString();
            $targetClass = $this->resolveCallTarget($node->var);

            if ($targetClass && $this->isAppClass($targetClass)) {
                $this->addEdge($from, $targetClass . '::' . $method, 'calls');
            }
            return null;
        }

        // Track assignments: $var = new ClassName() or $var = app(ClassName::class)
        if ($node instanceof Node\Expr\Assign) {
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $varName = $node->var->name;

                // $var = new ClassName()
                if ($node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
                    $className = $node->expr->class->toString();
                    $resolved = ($className === 'self' || $className === 'static')
                        ? $this->currentClass
                        : $this->typeResolver->resolve($className);
                    if ($resolved) {
                        $this->localVarTypes[$varName] = $resolved;
                    }
                }

                // $var = app(ClassName::class)
                if ($node->expr instanceof Node\Expr\FuncCall && $node->expr->name instanceof Node\Name && $node->expr->name->toString() === 'app') {
                    if (!empty($node->expr->args) && $node->expr->args[0] instanceof Node\Arg) {
                        $arg = $node->expr->args[0]->value;
                        if ($arg instanceof Node\Expr\ClassConstFetch && $arg->name instanceof Node\Identifier && $arg->name->toString() === 'class' && $arg->class instanceof Node\Name) {
                            $resolved = $this->typeResolver->resolve($arg->class->toString());
                            if ($resolved) {
                                $this->localVarTypes[$varName] = $resolved;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->currentMethod = null;
            $this->localVarTypes = [];
        }
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            $this->currentClass = null;
        }
    }

    /**
     * Resolve the class of a method call target expression.
     */
    private function resolveCallTarget(Node\Expr $expr): ?string
    {
        // $this->method()
        if ($expr instanceof Node\Expr\Variable && $expr->name === 'this') {
            return $this->currentClass;
        }

        // $variable->method()
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            // Check local vars first
            if (isset($this->localVarTypes[$expr->name])) {
                return $this->localVarTypes[$expr->name];
            }
            // Check method params
            if ($this->currentClass && isset($this->classMap[$this->currentClass]['methods'][$this->currentMethod])) {
                $params = $this->classMap[$this->currentClass]['methods'][$this->currentMethod]['params'] ?? [];
                if (isset($params['$' . $expr->name])) {
                    return $params['$' . $expr->name];
                }
            }
            return null;
        }

        // $this->property->method() — property fetch on $this
        if ($expr instanceof Node\Expr\PropertyFetch) {
            if ($expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
                $propName = $expr->name->toString();

                // Check declared properties
                if ($this->currentClass && isset($this->classMap[$this->currentClass]['properties'][$propName])) {
                    return $this->classMap[$this->currentClass]['properties'][$propName];
                }

                // Check constructor injections
                if ($this->currentClass) {
                    $injections = $this->typeResolver->getConstructorInjections($this->currentClass);
                    if (isset($injections[$propName])) {
                        return $injections[$propName];
                    }
                }
            }

            // Chained: $expr->prop where $expr is resolvable
            $parentClass = $this->resolveCallTarget($expr->var);
            if ($parentClass && $expr->name instanceof Node\Identifier) {
                $propName = $expr->name->toString();
                if (isset($this->classMap[$parentClass]['properties'][$propName])) {
                    return $this->classMap[$parentClass]['properties'][$propName];
                }
            }

            return null;
        }

        // MethodCall chain: $a->b()->c() — try to resolve via return type
        if ($expr instanceof Node\Expr\MethodCall) {
            $parentClass = $this->resolveCallTarget($expr->var);
            if ($parentClass && $expr->name instanceof Node\Identifier) {
                $method = $expr->name->toString();
                if (isset($this->classMap[$parentClass]['methods'][$method]['returnType'])) {
                    return $this->classMap[$parentClass]['methods'][$method]['returnType'];
                }
            }
            return null;
        }

        return null;
    }

    private function addEdge(string $from, string $to, string $type): void
    {
        $this->edges[] = [
            'source' => $from,
            'target' => $to,
            'type' => $type,
        ];
    }

    private function isAppClass(string $fqcn): bool
    {
        return str_starts_with($fqcn, 'App\\');
    }
}
