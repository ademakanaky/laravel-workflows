<?php

namespace Ademakanaky\LaravelWorkflows\Guards;

use Ademakanaky\LaravelWorkflows\Contracts\TransitionGuard;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

class ActorIsNotWorkflowInitiator implements TransitionGuard
{
    public function allows(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition, array $data): bool
    {
        return $actor !== null
            && ($instance->startedBy === null || ! $actor->is($instance->startedBy));
    }

    public function message(): string
    {
        return 'The workflow initiator cannot perform this action.';
    }
}
