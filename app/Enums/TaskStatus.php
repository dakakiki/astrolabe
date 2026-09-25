<?php

namespace App\Enums;

/**
 * A task is open until it is done; done tasks can be reopened. Deleting is a
 * soft delete, not a status.
 */
enum TaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
}
