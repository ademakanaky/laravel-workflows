<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Ademakanaky\LaravelWorkflows\Contracts\TransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

class TaskTransitionAuthorizer implements TransitionAuthorizer
{
    public function authorize(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition): bool
    {
        $task = $instance->tasks()
            ->where('workflow_state_id', $instance->current_state_id)
            ->where('status', WorkflowTaskStatus::Open->value)
            ->latest('id')
            ->first();

        if (! $task || $task->assignee_id === null) {
            return true;
        }

        return $actor !== null
            && $task->assignee_type === $actor->getMorphClass()
            && (string) $task->assignee_id === (string) $actor->getKey();
    }
}
