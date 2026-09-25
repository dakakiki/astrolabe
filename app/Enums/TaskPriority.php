<?php

namespace App\Enums;

/**
 * How pressing a task is (docs/spec/05, "tasks"). Open tasks with the same
 * deadline are listed most pressing first (`Task::scopeByDeadline`).
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
