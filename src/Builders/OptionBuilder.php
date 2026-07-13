<?php

namespace PressGang\Muster\Builders;

use LogicException;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnershipRegistry;
use PressGang\Muster\Results\OperationAction;

/**
 * Fluent builder for WordPress options.
 *
 * This builder collects option intent and persists via the WordPress options API
 * on `save()`. Managed identity is an explicit logical key; `option_name` is
 * the immutable WordPress locator.
 */
final class OptionBuilder
{
    use HasOwnership;

    private mixed $value = null;

    private bool $autoload = true;

    /**
     * @param MusterContext $context
     * @param string $key
     * @param string|null $ownershipScope
     */
    public function __construct(
        private MusterContext $context,
        private string $key,
        ?string $ownershipScope = null,
    ) {
        $this->initializeOwnership($ownershipScope);
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
     * as a fallback. Unowned option-name matches require `adopt()`. In planning
     * mode, this resolves state and reports an operation without writing.
     *
     * See: https://developer.wordpress.org/reference/functions/update_option/
     * See: https://developer.wordpress.org/reference/functions/add_option/
     *
     * @return void
     * @throws RuntimeException If required WordPress option functions are unavailable.
     */
    public function save(): void
    {
        $intent = $this->ownershipIntent();

        if ($intent !== null && $this->key === OwnershipRegistry::OPTION) {
            throw new LogicException('Muster cannot manage its own ownership registry option.');
        }

        if (!function_exists('get_option')) {
            throw new RuntimeException('get_option() is required to plan or save options.');
        }

        $owned = null;
        if ($intent !== null) {
            $owned = $this->currentOwnership($this->context, $intent, 'option', 'option');
            if ($owned !== null && $owned->locator() !== $this->key) {
                throw new LogicException(sprintf(
                    'Owned option [%s:%s] cannot change option name from [%s] to [%s].',
                    $intent['scope'],
                    $intent['key'],
                    $owned->locator(),
                    $this->key
                ));
            }

        }

        $sentinel = new \stdClass();
        $current = get_option($this->key, $sentinel);
        $exists = $current !== $sentinel
            && !$this->context->isPlannedDeleted('option', 0, 'option', $this->key);

        if ($intent !== null && $exists) {
            $this->claimExistingOwnership($this->context, $intent, 'option', 0, 'option', $this->key);
        }

        $plannedClaim = $intent !== null
            && $this->context->ownership()->isPlannedClaim($intent['scope'], $intent['key']);
        $operation = !$exists
            ? ($plannedClaim ? OperationAction::Keep : OperationAction::Create)
            : ($owned === null || $current !== $this->value ? OperationAction::Update : OperationAction::Keep);

        if ($this->context->dryRun()) {
            if ($intent !== null) {
                $this->reportOwnership($this->context, $intent, $operation, 'option', 0, $this->key);
                $this->recordOwnership($this->context, $intent, 'option', 0, 'option', $this->key);
            }

            return;
        }

        if ($operation === OperationAction::Keep) {
            if ($intent !== null) {
                $this->recordOwnership($this->context, $intent, 'option', 0, 'option', $this->key);
                $this->reportOwnership($this->context, $intent, $operation, 'option', 0, $this->key);
            }

            return;
        }

        if (!function_exists('update_option') && !function_exists('add_option')) {
            throw new RuntimeException('WordPress write functions are required to save options.');
        }

        if (function_exists('update_option')) {
            update_option($this->key, $this->value, $this->autoload);
            if ($intent !== null) {
                $this->recordOwnership($this->context, $intent, 'option', 0, 'option', $this->key);
                $this->reportOwnership($this->context, $intent, $operation, 'option', 0, $this->key);
            }
            $this->context->logger()->debug(sprintf('Option updated [%s].', $this->key));

            return;
        }

        add_option($this->key, $this->value, '', $this->autoload ? 'yes' : 'no');
        if ($intent !== null) {
            $this->recordOwnership($this->context, $intent, 'option', 0, 'option', $this->key);
            $this->reportOwnership($this->context, $intent, $operation, 'option', 0, $this->key);
        }
        $this->context->logger()->debug(sprintf('Option inserted [%s].', $this->key));
    }
}
