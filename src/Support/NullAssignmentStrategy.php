<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Illuminate\Database\Eloquent\Model;

class NullAssignmentStrategy implements AssignmentStrategy
{
    public function assign(WorkflowInstance $instance, WorkflowState $state, ?Model $actor): ?Model
    {
        return null;
    }
}
