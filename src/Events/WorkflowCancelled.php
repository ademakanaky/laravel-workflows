<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;

class WorkflowCancelled
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly WorkflowTransitionLog $log,
    ) {}
}
