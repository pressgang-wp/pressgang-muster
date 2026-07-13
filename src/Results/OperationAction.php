<?php

namespace PressGang\Muster\Results;

/**
 * Reconciliation outcomes emitted by planning and application runs.
 */
enum OperationAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Keep = 'keep';
    case Prune = 'prune';
    case Conflict = 'conflict';
}
