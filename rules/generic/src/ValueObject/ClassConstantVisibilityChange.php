<?php

declare(strict_types=1);

namespace Rector\Generic\ValueObject;

final class ClassConstantVisibilityChange
{
    /**
     * @var string
     */
    private $class;

    /**
     * @var string
     */
    private $constant;

    /**
     * @var int
     */
    private $visibility;

    public function __construct(string $class, string $constant, int $visibility)
    {
        $this->class = $class;
        $this->constant = $constant;
        $this->visibility = $visibility;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getConstant(): string
    {
        return $this->constant;
    }

    public function getVisibility(): int
    {
        return $this->visibility;
    }
}
