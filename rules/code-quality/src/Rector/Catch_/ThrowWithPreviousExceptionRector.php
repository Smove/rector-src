<?php

declare(strict_types=1);

namespace Rector\CodeQuality\Rector\Catch_;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Throw_;
use PhpParser\NodeTraverser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\TypeWithClassName;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\MethodName;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Throwable;

/**
 * @see https://github.com/thecodingmachine/phpstan-strict-rules/blob/e3d746a61d38993ca2bc2e2fcda7012150de120c/src/Rules/Exceptions/ThrowMustBundlePreviousExceptionRule.php#L83
 * @see \Rector\CodeQuality\Tests\Rector\Catch_\ThrowWithPreviousExceptionRector\ThrowWithPreviousExceptionRectorTest
 */
final class ThrowWithPreviousExceptionRector extends AbstractRector
{
    /**
     * @var int
     */
    private const DEFAULT_EXCEPTION_ARGUMENT_POSITION = 2;

    /**
     * @var ReflectionProvider
     */
    private $reflectionProvider;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'When throwing into a catch block, checks that the previous exception is passed to the new throw clause',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        try {
            $someCode = 1;
        } catch (Throwable $throwable) {
            throw new AnotherException('ups');
        }
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        try {
            $someCode = 1;
        } catch (Throwable $throwable) {
            throw new AnotherException('ups', $throwable->getCode(), $throwable);
        }
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Catch_::class];
    }

    /**
     * @param Catch_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $caughtThrowableVariable = $node->var;
        if (! $caughtThrowableVariable instanceof Variable) {
            return null;
        }

        $this->traverseNodesWithCallable($node->stmts, function (Node $node) use ($caughtThrowableVariable): ?int {
            if (! $node instanceof Throw_) {
                return null;
            }

            return $this->refactorThrow($node, $caughtThrowableVariable);
        });

        return $node;
    }

    private function refactorThrow(Throw_ $throw, Variable $catchedThrowableVariable): ?int
    {
        if (! $throw->expr instanceof New_) {
            return null;
        }

        $new = $throw->expr;
        if (! $new->class instanceof Name) {
            return null;
        }

        $exceptionArgumentPosition = $this->resolveExceptionArgumentPosition($new->class);
        if ($exceptionArgumentPosition === null) {
            return null;
        }

        // exception is bundled
        if (isset($new->args[$exceptionArgumentPosition])) {
            return null;
        }

        if (! isset($new->args[0])) {
            // get previous message
            $new->args[0] = new Arg(new MethodCall($catchedThrowableVariable, 'getMessage'));
        }

        if (! isset($new->args[1])) {
            // get previous code
            $new->args[1] = new Arg(new MethodCall($catchedThrowableVariable, 'getCode'));
        }

        $new->args[$exceptionArgumentPosition] = new Arg($catchedThrowableVariable);

        // null the node, to fix broken format preserving printers, see https://github.com/rectorphp/rector/issues/5576
        $new->setAttribute(AttributeKey::ORIGINAL_NODE, null);

        // nothing more to add
        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
    }

    private function resolveExceptionArgumentPosition(Name $exceptionName): ?int
    {
        $className = $this->getName($exceptionName);

        // is native exception?
        if (! Strings::contains($className, '\\')) {
            return self::DEFAULT_EXCEPTION_ARGUMENT_POSITION;
        }

        if (! $this->reflectionProvider->hasClass($className)) {
            return self::DEFAULT_EXCEPTION_ARGUMENT_POSITION;
        }

        $classReflection = $this->reflectionProvider->getClass($className);
        $construct = $classReflection->hasMethod(MethodName::CONSTRUCT);
        if (! $construct) {
            return self::DEFAULT_EXCEPTION_ARGUMENT_POSITION;
        }

        $constructorReflectionMethod = $classReflection->getConstructor();
        $parametersAcceptor = $constructorReflectionMethod->getVariants()[0];

        foreach ($parametersAcceptor->getParameters() as $position => $parameterReflection) {
            $parameterType = $parameterReflection->getType();
            if (! $parameterType instanceof TypeWithClassName) {
                continue;
            }

            if (! is_a($parameterType->getClassName(), Throwable::class, true)) {
                continue;
            }

            return $position;
        }

        return null;
    }
}