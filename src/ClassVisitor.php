<?php

declare(strict_types=1);

namespace DepsGraph;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * First pass: extracts class metadata, methods, properties, use statements, and DI.
 */
final class ClassVisitor extends NodeVisitorAbstract
{
    /** @var array<string, array> Collected class data keyed by FQCN */
    private array $classes = [];

    /** @var array<string, string> alias => FQCN */
    private array $useMap = [];

    private string $currentNamespace = '';
    private ?string $currentClass = null;

    private TypeResolver $typeResolver;

    public function __construct(TypeResolver $typeResolver)
    {
        $this->typeResolver = $typeResolver;
    }

    public function getClasses(): array
    {
        return $this->classes;
    }

    public function getUseMap(): array
    {
        return $this->useMap;
    }

    public function getCurrentNamespace(): string
    {
        return $this->currentNamespace;
    }

    public function reset(): void
    {
        $this->useMap = [];
        $this->currentNamespace = '';
        $this->currentClass = null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : '';
            return null;
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $fqcn = $use->name->toString();
                $alias = $use->getAlias()->toString();
                $this->useMap[$alias] = $fqcn;
            }
            return null;
        }

        if ($node instanceof Node\Stmt\GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                $fqcn = $prefix . '\\' . $use->name->toString();
                $alias = $use->getAlias()->toString();
                $this->useMap[$alias] = $fqcn;
            }
            return null;
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            if ($node->name === null) {
                return null; // anonymous class
            }

            $name = $node->name->toString();
            $fqcn = $this->currentNamespace ? $this->currentNamespace . '\\' . $name : $name;
            $this->currentClass = $fqcn;

            $this->typeResolver->setNamespace($this->currentNamespace);
            $this->typeResolver->setUseMap($this->useMap);

            $classData = [
                'fqcn' => $fqcn,
                'name' => $name,
                'namespace' => $this->currentNamespace,
                'type' => match (true) {
                    $node instanceof Node\Stmt\Interface_ => 'interface',
                    $node instanceof Node\Stmt\Trait_ => 'trait',
                    $node instanceof Node\Stmt\Enum_ => 'enum',
                    default => 'class',
                },
                'layer' => TypeResolver::detectLayer($fqcn),
                'methods' => [],
                'properties' => [],
                'extends' => null,
                'implements' => [],
                'traits' => [],
                'constructorParams' => [],
            ];

            // Parent class
            if ($node instanceof Node\Stmt\Class_ && $node->extends) {
                $classData['extends'] = $this->typeResolver->resolve($node->extends->toString());
            }

            // Interfaces
            if (($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) && !empty($node->implements)) {
                foreach ($node->implements as $impl) {
                    $resolved = $this->typeResolver->resolve($impl->toString());
                    if ($resolved) {
                        $classData['implements'][] = $resolved;
                    }
                }
            }

            $this->classes[$fqcn] = $classData;
            return null;
        }

        if ($this->currentClass === null) {
            return null;
        }

        // Trait uses
        if ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $resolved = $this->typeResolver->resolve($trait->toString());
                if ($resolved) {
                    $this->classes[$this->currentClass]['traits'][] = $resolved;
                }
            }
            return null;
        }

        // Properties
        if ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $prop) {
                $propName = $prop->name->toString();
                $type = $this->typeResolver->resolveTypeNode($node->type);
                if ($type) {
                    $this->classes[$this->currentClass]['properties'][$propName] = $type;
                }
            }
            return null;
        }

        // Methods
        if ($node instanceof Node\Stmt\ClassMethod) {
            $methodName = $node->name->toString();
            $visibility = 'public';
            if ($node->isProtected()) $visibility = 'protected';
            if ($node->isPrivate()) $visibility = 'private';

            $params = [];
            foreach ($node->params as $param) {
                $paramName = '$' . $param->var->name;
                $paramType = $this->typeResolver->resolveTypeNode($param->type);
                $params[$paramName] = $paramType;

                // Constructor promotion (public/protected/private params)
                if ($methodName === '__construct' && ($param->flags & Node\Stmt\Class_::MODIFIER_PUBLIC || $param->flags & Node\Stmt\Class_::MODIFIER_PROTECTED || $param->flags & Node\Stmt\Class_::MODIFIER_PRIVATE)) {
                    if ($paramType) {
                        $this->classes[$this->currentClass]['properties'][$param->var->name] = $paramType;
                    }
                }
            }

            // Track constructor injections separately
            if ($methodName === '__construct') {
                $this->classes[$this->currentClass]['constructorParams'] = $params;
                $injections = [];
                foreach ($params as $pName => $pType) {
                    if ($pType !== null) {
                        $injections[ltrim($pName, '$')] = $pType;
                    }
                }
                $this->typeResolver->registerConstructorInjections($this->currentClass, $injections);
            }

            $returnType = $this->typeResolver->resolveTypeNode($node->returnType);

            $this->classes[$this->currentClass]['methods'][$methodName] = [
                'visibility' => $visibility,
                'params' => $params,
                'returnType' => $returnType,
                'static' => $node->isStatic(),
                'line' => $node->getStartLine(),
            ];
            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): void
    {
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            $this->currentClass = null;
        }
    }
}
