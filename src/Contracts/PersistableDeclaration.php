<?php

namespace PressGang\Muster\Contracts;

/**
 * Explicit contract for a declaration that a Pattern can persist.
 *
 * Implementations collect one resource's intent and perform their write only
 * when `save()` is called. Returning an immutable ref keeps Pattern execution
 * independent of a particular WordPress resource type.
 */
interface PersistableDeclaration
{
    public function save(): object;
}
