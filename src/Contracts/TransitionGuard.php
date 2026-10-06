<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

interface TransitionGuard
{
    /** @param array<string, mixed> $data */
    public function allows(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition, array $data): bool;

    public function message(): string;
}
