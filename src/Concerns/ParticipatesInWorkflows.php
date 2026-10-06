<?php

namespace Ademakanaky\LaravelWorkflows\Concerns;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** @phpstan-require-extends Model */
trait ParticipatesInWorkflows
{
    /** @return MorphMany<WorkflowInstance, $this> */
    public function startedWorkflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowModelRegistry::instance(), 'started_by');
    }

    /** @return MorphMany<WorkflowTask, $this> */
    public function assignedWorkflowTasks(): MorphMany
    {
        return $this->morphMany(WorkflowModelRegistry::task(), 'assignee');
    }

    /** @return MorphMany<WorkflowTask, $this> */
    public function pendingWorkflowTasks(): MorphMany
    {
        return $this->assignedWorkflowTasks()->where('status', WorkflowTaskStatus::Open->value);
    }

    /** @return MorphMany<WorkflowTransitionLog, $this> */
    public function workflowActions(): MorphMany
    {
        return $this->morphMany(WorkflowModelRegistry::log(), 'actor');
    }
}
