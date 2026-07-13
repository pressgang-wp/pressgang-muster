<?php

namespace PressGang\Muster\Testing;

use RuntimeException;

/**
 * Raised when a structured Muster report differs from its stored snapshot.
 */
final class SnapshotMismatch extends RuntimeException
{
}
