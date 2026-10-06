<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

class NullWorkflowTaskNotifier implements WorkflowTaskNotifier
{
    public function opened(WorkflowTask $task, ?Model $actor): void {}

    public function assigned(WorkflowTask $task, ?Model $previousAssignee, ?Model $actor): void {}

    public function completed(WorkflowTask $task, ?Model $actor): void {}

    public function cancelled(WorkflowTask $task, ?Model $actor): void {}

    public function nudged(WorkflowTask $task, ?Model $actor, array $data): void {}
}
