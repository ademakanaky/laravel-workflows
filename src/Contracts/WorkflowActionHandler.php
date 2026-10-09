<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

interface WorkflowActionHandler
{
    /** @param array<string, mixed> $data */
    public function handle(
        ?Model $actor,
        WorkflowInstance $instance,
        WorkflowTransition $transition,
        array $data,
    ): void;
}
