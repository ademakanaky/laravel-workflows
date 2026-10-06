<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Illuminate\Database\Eloquent\Model;

interface AssignmentStrategy
{
    public function assign(WorkflowInstance $instance, WorkflowState $state, ?Model $actor): ?Model;
}
