<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

interface WorkflowTaskNotifier
{
    public function opened(WorkflowTask $task, ?Model $actor): void;

    public function assigned(WorkflowTask $task, ?Model $previousAssignee, ?Model $actor): void;

    public function completed(WorkflowTask $task, ?Model $actor): void;

    public function cancelled(WorkflowTask $task, ?Model $actor): void;

    /** @param array<string, mixed> $data */
    public function nudged(WorkflowTask $task, ?Model $actor, array $data): void;
}
