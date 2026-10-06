<?php

namespace Ademakanaky\LaravelWorkflows\Enums;

enum WorkflowInstanceStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
