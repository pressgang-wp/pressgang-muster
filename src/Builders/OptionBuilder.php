<?php

namespace PressGang\Muster\Builders;

use LogicException;
use PressGang\Muster\Contracts\PersistableDeclaration;
use RuntimeException;
use PressGang\Muster\MusterContext;
use PressGang\Muster\Ownership\HasOwnership;
use PressGang\Muster\Ownership\OwnedResource;
use PressGang\Muster\Ownership\OwnershipRegistry;
use PressGang\Muster\Results\OperationAction;
use PressGang\Muster\Refs\OptionRef;

/**
 * Fluent builder for WordPress options.
 *
 * This builder collects option intent and persists via the WordPress options API
 * on `save()`. Managed identity is an explicit logical key; `option_name` is
 * the immutable WordPress locator.
 */
final class OptionBuilder implements PersistableDeclaration
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
     * @return OptionRef
     * @throws RuntimeException If required WordPress option functions are unavailable.
     */
    public function save(): OptionRef
    {
        $intent = $this->ownershipIntent();
        $this->context->debugDeclaration('Option', ['value', 'autoload']);

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

        $operation = $this->optionOperation($exists, $current, $owned, $intent);

        if ($this->context->dryRun() || $operation === OperationAction::Keep) {
            $this->finalizeUpsert($this->context, $intent, $operation, 'option', 0, 'option', $this->key);

            return new OptionRef($this->key);
        }

        if (!function_exists('update_option') && !function_exists('add_option')) {
            throw new RuntimeException('WordPress write functions are required to save options.');
        }

        if (function_exists('update_option')) {
            update_option($this->key, $this->value, $this->autoload);
            $this->context->logger()->debug(sprintf('Option updated [%s].', $this->key));
        } else {
            add_option($this->key, $this->value, '', $this->autoload ? 'yes' : 'no');
            $this->context->logger()->debug(sprintf('Option inserted [%s].', $this->key));
        }

        $this->finalizeUpsert($this->context, $intent, $operation, 'option', 0, 'option', $this->key);

        return new OptionRef($this->key);
    }

    /**
     * Determine whether the declaration creates, updates, or keeps the option.
     *
     * @param bool $exists Whether the option currently exists in WordPress.
     * @param mixed $current The stored value when it exists.
     * @param OwnedResource|null $owned
     * @param array{scope: string, key: string, adopt: bool}|null $intent
     * @return OperationAction
     */
    private function optionOperation(bool $exists, mixed $current, ?OwnedResource $owned, ?array $intent): OperationAction
    {
        if (!$exists) {
            $plannedClaim = $intent !== null
                && $this->context->ownership()->isPlannedClaim($intent['scope'], $intent['key']);

            return $plannedClaim ? OperationAction::Keep : OperationAction::Create;
        }

        return $owned === null || $current !== $this->value ? OperationAction::Update : OperationAction::Keep;
    }
}
