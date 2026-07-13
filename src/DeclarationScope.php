<?php

namespace PressGang\Muster;

use LogicException;
use PressGang\Muster\Contracts\LoggerInterface;

/**
 * Declaration-group state for one Muster execution pass.
 *
 * Owns the named-group lifecycle (`group()` enter/leave), the `--only`
 * allowlist, and the assertions that keep partial runs safe: partial runs may
 * only declare inside named groups, and whole-scope operations are refused
 * outright while a filter is active.
 */
final class DeclarationScope
{
    private ?string $activeGroup = null;

    /**
     * @var array<string, true>
     */
    private array $declaredGroups = [];

    /**
     * @param LoggerInterface $logger
     * @param array<int, string> $onlyGroups Optional allowlist of group names.
     */
    public function __construct(private LoggerInterface $logger, private array $onlyGroups = [])
    {
    }

    /**
     * Enter a named declaration group when it is selected for this run.
     *
     * Group names must be unique within one Muster pass. A filtered-out group
     * is still registered so unknown `--only` values can be reported, but its
     * callback must not be invoked by the caller.
     *
     * @param string $name
     * @return bool Whether the group is selected and has been entered.
     */
    public function enterGroup(string $name): bool
    {
        $name = trim($name);

        if ($name === '') {
            throw new LogicException('Muster group names must not be empty.');
        }

        if ($this->activeGroup !== null) {
            throw new LogicException(sprintf(
                'Muster group [%s] cannot be nested inside active group [%s].',
                $name,
                $this->activeGroup
            ));
        }

        if (isset($this->declaredGroups[$name])) {
            throw new LogicException(sprintf('Muster group [%s] was declared more than once.', $name));
        }

        $this->declaredGroups[$name] = true;

        if ($this->onlyGroups !== [] && !in_array($name, $this->onlyGroups, true)) {
            $this->logger->info(sprintf('Skipping group [%s] due to --only filter.', $name));

            return false;
        }

        $this->activeGroup = $name;

        return true;
    }

    /**
     * Leave the active declaration group.
     *
     * @return void
     */
    public function leaveGroup(): void
    {
        if ($this->activeGroup === null) {
            throw new LogicException('Cannot leave a Muster group when no group is active.');
        }

        $this->activeGroup = null;
    }

    /**
     * Return the group currently evaluating declarations, if any.
     *
     * @return string|null
     */
    public function activeGroup(): ?string
    {
        return $this->activeGroup;
    }

    /**
     * Require partial runs to place every data declaration inside a group.
     *
     * This fails before a builder can perform WordPress reads or writes. Full
     * runs remain free to use ungrouped declarations when filtering is not needed.
     *
     * @param string $declaration Human-readable declaration type for the error.
     * @return void
     */
    public function assertDeclarationAllowed(string $declaration): void
    {
        if ($this->onlyGroups !== [] && $this->activeGroup === null) {
            throw new LogicException(sprintf(
                '%s was declared outside a named group during a partial --only run. Wrap it in $this->group(...).',
                $declaration
            ));
        }
    }

    /**
     * Reject whole-scope reconciliation during a partial group run.
     *
     * @param string $operation
     * @return void
     */
    public function assertCompleteRun(string $operation): void
    {
        if ($this->onlyGroups !== []) {
            throw new LogicException(sprintf(
                '%s requires a complete Muster run and cannot be used with --only.',
                $operation
            ));
        }
    }

    /**
     * Verify that every requested group was declared by the completed Muster.
     *
     * @return void
     */
    public function assertOnlyGroupsResolved(): void
    {
        $unknown = array_values(array_diff($this->onlyGroups, array_keys($this->declaredGroups)));

        if ($unknown === []) {
            return;
        }

        $available = array_keys($this->declaredGroups);
        $guidance = $available === []
            ? ' This Muster declared no groups.'
            : ' Available groups: ' . implode(', ', $available) . '.';

        throw new LogicException(sprintf(
            'Unknown Muster group%s requested by --only: %s.%s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', $unknown),
            $guidance
        ));
    }
}
