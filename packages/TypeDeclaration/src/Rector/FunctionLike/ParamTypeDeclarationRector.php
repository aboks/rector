<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\FunctionLike;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Php\ParamTypeInfo;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

final class ParamTypeDeclarationRector extends AbstractTypeDeclarationRector
{
    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Change @param types to type declarations if not a BC-break', [
            new CodeSample(
                <<<'CODE_SAMPLE'
<?php

class ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }
}

final class ChildClass extends ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }

    /**
     * @param int $number
     */
    public function change($number)
    {
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
<?php

class ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }
}

final class ChildClass extends ParentClass
{
    /**
     * @param int $number
     */
    public function keep($number)
    {
    }

    /**
     * @param int $number
     */
    public function change(int $number)
    {
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @param ClassMethod|Function_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion('7.0')) {
            return null;
        }

        if (empty($node->params)) {
            return null;
        }

        $paramTagInfos = $this->docBlockManipulator->getParamTypeInfos($node);

        // no tags, nothing to complete here
        if ($paramTagInfos === []) {
            return null;
        }

        foreach ($node->params as $position => $paramNode) {
            // skip variadics
            if ($paramNode->variadic) {
                continue;
            }

            // already set → skip
            $hasNewType = false;
            if ($paramNode->type !== null) {
                $hasNewType = $paramNode->type->getAttribute(self::HAS_NEW_INHERITED_TYPE, false);
                if (! $hasNewType) {
                    continue;
                }
            }

            $paramNodeName = $this->getName($paramNode->var);

            // no info about it
            if (! isset($paramTagInfos[$paramNodeName])) {
                continue;
            }

            $paramTypeInfo = $paramTagInfos[$paramNodeName];

            if (! $paramTypeInfo->isTypehintAble()) {
                continue;
            }

            $position = (int) $position;
            if ($node instanceof ClassMethod && $this->isChangeVendorLockedIn($node, $position)) {
                continue;
            }

            if ($hasNewType) {
                // should override - is it subtype?
                $possibleOverrideNewReturnType = $paramTypeInfo->getTypeNode();
                if ($possibleOverrideNewReturnType !== null) {
                    if ($paramNode->type !== null) {
                        if ($this->isSubtypeOf($possibleOverrideNewReturnType, $paramNode->type)) {
                            // allow override
                            $paramNode->type = $paramTypeInfo->getTypeNode();
                        }
                    } else {
                        $paramNode->type = $paramTypeInfo->getTypeNode();
                    }
                }
            } else {
                $paramNode->type = $paramTypeInfo->getTypeNode();

                // "resource" is valid phpdoc type, but it's not implemented in PHP
                if ($paramNode->type instanceof Node\Name && reset($paramNode->type->parts) === 'resource') {
                    $paramNode->type = null;

                    continue;
                }
            }

            $this->populateChildren($node, $position, $paramTypeInfo);
        }

        return $node;
    }

    /**
     * Add typehint to all children
     */
    private function populateChildren(Node $node, int $position, ParamTypeInfo $paramTypeInfo): void
    {
        if (! $node instanceof ClassMethod) {
            return;
        }

        /** @var string $methodName */
        $methodName = $this->getName($node);

        /** @var string $className */
        $className = $node->getAttribute(AttributeKey::CLASS_NAME);
        // anonymous class
        if ($className === null) {
            return;
        }

        $childrenClassLikes = $this->parsedNodesByType->findClassesAndInterfacesByType($className);

        // update their methods as well
        foreach ($childrenClassLikes as $childClassLike) {
            if ($childClassLike instanceof Class_) {
                $usedTraits = $this->parsedNodesByType->findUsedTraitsInClass($childClassLike);

                foreach ($usedTraits as $trait) {
                    $this->addParamTypeToMethod($trait, $methodName, $position, $node, $paramTypeInfo);
                }
            }

            $this->addParamTypeToMethod($childClassLike, $methodName, $position, $node, $paramTypeInfo);
        }
    }

    private function addParamTypeToMethod(
        ClassLike $classLike,
        string $methodName,
        int $position,
        Node $node,
        ParamTypeInfo $paramTypeInfo
    ): void {
        $classMethod = $classLike->getMethod($methodName);
        if ($classMethod === null) {
            return;
        }

        if (! isset($classMethod->params[$position])) {
            return;
        }

        $paramNode = $classMethod->params[$position];

        // already has a type
        if ($paramNode->type !== null) {
            return;
        }

        $resolvedChildType = $this->resolveChildType($paramTypeInfo, $node, $classMethod);
        if ($resolvedChildType === null) {
            return;
        }

        $paramNode->type = $resolvedChildType;

        // let the method know it was changed now
        $paramNode->type->setAttribute(self::HAS_NEW_INHERITED_TYPE, true);

        $this->notifyNodeChangeFileInfo($paramNode);
    }
}
