<?php

namespace PressGang\Muster\Builders;

use RuntimeException;
use PressGang\Muster\MusterContext;

/**
 * Fluent builder for WordPress options.
 *
 * This builder collects option intent and persists via the WordPress options API
 * on `save()`. Identity is the option key (`option_name`).
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
     * Set the option value payload.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param mixed $value
     * @return self
     */
    public function value(mixed $value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Set the desired autoload behaviour for option persistence.
     *
     * This mutates builder state only and does not write to WordPress.
     *
     * @param bool $autoload
     * @return self
     */
    public function autoload(bool $autoload): self
    {
        $this->autoload = $autoload;

        return $this;
    }

    /**
     * Persist the option to WordPress.
     *
     * Uses `update_option()` when available (upsert behaviour), or `add_option()`
     * as a fallback. In dry-run mode, this method logs intent and performs no write.
     *
     * See: https://developer.wordpress.org/reference/functions/update_option/
     * See: https://developer.wordpress.org/reference/functions/add_option/
     *
     * @return void
     * @throws RuntimeException If required WordPress option functions are unavailable.
     */
    public function save(): void
    {
        if ($this->context->dryRun()) {
            $this->context->logger()->info(sprintf('Dry run option upsert [%s].', $this->key));

            return;
        }

        if (!function_exists('update_option') && !function_exists('add_option')) {
            throw new RuntimeException('WordPress option runtime functions are required to save options.');
        }

        if (function_exists('update_option')) {
            update_option($this->key, $this->value, $this->autoload);
            $this->context->logger()->debug(sprintf('Option updated [%s].', $this->key));

            return;
        }

        add_option($this->key, $this->value, '', $this->autoload ? 'yes' : 'no');
        $this->context->logger()->debug(sprintf('Option inserted [%s].', $this->key));
    }
}
