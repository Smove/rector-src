<?php

declare(strict_types=1);

namespace Rector\Symfony\Rector\FrameworkBundle;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\RectorDefinition\CodeSample;
use Rector\Core\RectorDefinition\RectorDefinition;
use Rector\NodeTypeResolver\Node\AttributeKey;

/**
 * Ref: https://github.com/symfony/symfony/blob/master/UPGRADE-4.0.md#console
 * @see \Rector\Symfony\Tests\Rector\FrameworkBundle\ContainerGetToConstructorInjectionRector\ContainerGetToConstructorInjectionRectorTest
 */
final class ContainerGetToConstructorInjectionRector extends AbstractToConstructorInjectionRector implements ConfigurableRectorInterface
{
    /**
     * @api
     * @var string
     */
    public const CONTAINER_AWARE_PARENT_TYPES = '$containerAwareParentTypes';

    /**
     * @var string[]
     */
    private $containerAwareParentTypes = [];

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'Turns fetching of dependencies via `$container->get()` in ContainerAware to constructor injection in Command and Controller in Symfony',
            [
                new CodeSample(
<<<'PHP'
final class SomeCommand extends ContainerAwareCommand
{
    public function someMethod()
    {
        // ...
        $this->getContainer()->get('some_service');
        $this->container->get('some_service');
    }
}
PHP
                    ,
<<<'PHP'
final class SomeCommand extends Command
{
    public function __construct(SomeService $someService)
    {
        $this->someService = $someService;
    }

    public function someMethod()
    {
        // ...
        $this->someService;
        $this->someService;
    }
}
PHP
                ),
            ]
        );
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isObjectType($node->var, 'Symfony\Component\DependencyInjection\ContainerInterface')) {
            return null;
        }

        if (! $this->isName($node->name, 'get')) {
            return null;
        }

        $parentClassName = $node->getAttribute(AttributeKey::PARENT_CLASS_NAME);
        if (! in_array($parentClassName, $this->containerAwareParentTypes, true)) {
            return null;
        }

        return $this->processMethodCallNode($node);
    }

    public function configure(array $configuration): void
    {
        $this->containerAwareParentTypes = $configuration[self::CONTAINER_AWARE_PARENT_TYPES] ?? [];
    }
}
