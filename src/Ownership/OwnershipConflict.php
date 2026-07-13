<?php

namespace PressGang\Muster\Ownership;

use RuntimeException;

/**
 * Raised when a keyed declaration would take over an unowned resource or a
 * resource already claimed by another logical key.
 */
final class OwnershipConflict extends RuntimeException
{
}
