<?php

namespace Ademakanaky\LaravelWorkflows\Data;

use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class WorkflowProcessSnapshot
{
    /**
     * @param  EloquentCollection<int, Model>  $candidateActors
     * @param  Collection<int, mixed>  $availableTransitions
     * @param  EloquentCollection<int, WorkflowTransitionLog>  $history
     */
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly WorkflowState $currentState,
        public readonly Model $subject,
        public readonly ?WorkflowTask $currentTask,
        public readonly ?Model $currentAssignee,
        public readonly EloquentCollection $candidateActors,
        public readonly Collection $availableTransitions,
        public readonly EloquentCollection $history,
        public readonly int $timeInCurrentStateSeconds,
    ) {}
}
