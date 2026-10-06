<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Illuminate\Database\Eloquent\Model;

class ActorAssignmentStrategy implements AssignmentStrategy
{
    public function assign(WorkflowInstance $instance, WorkflowState $state, ?Model $actor): ?Model
    {
        return $actor;
    }
}
