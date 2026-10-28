<?php

declare(strict_types=1);

namespace App\Enum;

enum HistoryType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';
    case Reassigned = 'reassigned';
    case PriorityEscalated = 'priority_escalated';
}
