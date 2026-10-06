<?php

namespace Ademakanaky\LaravelWorkflows\Concerns;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowInstanceStatus;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** @phpstan-require-extends Model */
trait HasWorkflows
{
    /** @return MorphMany<WorkflowInstance, $this> */
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowModelRegistry::instance(), 'subject');
    }

    /** @return MorphMany<WorkflowInstance, $this> */
    public function activeWorkflowInstances(): MorphMany
    {
        return $this->workflowInstances()->where('status', WorkflowInstanceStatus::Running->value);
    }
}
