<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

class RecordingWorkflowTaskNotifier implements WorkflowTaskNotifier
{
    /** @var list<WorkflowTask> */
    public array $opened = [];

    /** @var list<WorkflowTask> */
    public array $assigned = [];

    /** @var list<WorkflowTask> */
    public array $completed = [];

    /** @var list<WorkflowTask> */
    public array $cancelled = [];

    /** @var list<WorkflowTask> */
    public array $nudged = [];

    public function opened(WorkflowTask $task, ?Model $actor): void
    {
        $this->opened[] = $task;
    }

    public function assigned(WorkflowTask $task, ?Model $previousAssignee, ?Model $actor): void
    {
        $this->assigned[] = $task;
    }

    public function completed(WorkflowTask $task, ?Model $actor): void
    {
        $this->completed[] = $task;
    }

    public function cancelled(WorkflowTask $task, ?Model $actor): void
    {
        $this->cancelled[] = $task;
    }

    public function nudged(WorkflowTask $task, ?Model $actor, array $data): void
    {
        $this->nudged[] = $task;
    }
}
