<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;

class WorkflowStarted
{
    public function __construct(public readonly WorkflowInstance $instance) {}
}
