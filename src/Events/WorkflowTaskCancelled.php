<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

class WorkflowTaskCancelled
{
    public function __construct(
        public readonly WorkflowTask $task,
        public readonly ?Model $actor,
    ) {}
}
