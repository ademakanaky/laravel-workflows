<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;

class WorkflowOutcomeReached
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly string $outcome,
    ) {}
}
