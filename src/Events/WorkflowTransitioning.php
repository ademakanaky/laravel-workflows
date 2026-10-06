<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

class WorkflowTransitioning
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly WorkflowTransition $transition,
        public readonly ?Model $actor,
        public readonly array $data,
    ) {}
}
