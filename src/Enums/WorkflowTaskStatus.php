<?php

namespace Ademakanaky\LaravelWorkflows\Enums;

enum WorkflowTaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
