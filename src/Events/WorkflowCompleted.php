<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;

class WorkflowCompleted
{
    public function __construct(public readonly WorkflowInstance $instance) {}
}
