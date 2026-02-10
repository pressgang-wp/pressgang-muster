<?php

namespace PressGang\Muster\Builders;

use PressGang\Muster\MusterContext;

/**
 * Fluent option builder.
 */
final class OptionBuilder
{
    private mixed $value = null;

    private bool $autoload = true;

    /**
     * @param MusterContext $context
     * @param string $key
     */
    public function __construct(private MusterContext $context, private string $key)
    {
    }

    /**
     * @param mixed $value
     * @return self
     */
    public function value(mixed $value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @param bool $autoload
     * @return self
     */
    public function autoload(bool $autoload): self
    {
        $this->autoload = $autoload;

        return $this;
    }

    /**
     * @return void
     */
    public function save(): void
    {
    }
}
