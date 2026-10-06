<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

interface TransitionAuthorizer
{
    public function authorize(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition): bool;
}
