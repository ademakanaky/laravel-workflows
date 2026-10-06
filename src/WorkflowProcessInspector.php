<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Data\WorkflowProcessSnapshot;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

class WorkflowProcessInspector
{
    public function inspect(WorkflowInstance|string $instance): WorkflowProcessSnapshot
    {
        if (is_string($instance)) {
            $instanceClass = WorkflowModelRegistry::instance();
            $instance = $instanceClass::query()->findOrFail($instance);
        }
        $instance->loadMissing(['definition', 'version', 'currentState', 'subject', 'logs.actor']);
        $task = $instance->tasks()
            ->where('workflow_state_id', $instance->current_state_id)
            ->where('status', WorkflowTaskStatus::Open->value)
            ->with(['assignee', 'candidates.candidate', 'state'])
            ->latest('id')
            ->first();
        $candidates = new EloquentCollection;
        if ($task) {
            $candidates = new EloquentCollection($task->candidates
                ->pluck('candidate')
                ->filter(fn ($candidate): bool => $candidate instanceof Model)
                ->values()
                ->all());
        }
        $enteredAt = $task
            ? $task->created_at
            : $instance->logs()->latest('created_at')->firstOrFail()->created_at;

        return new WorkflowProcessSnapshot(
            instance: $instance,
            currentState: $instance->currentState,
            subject: $instance->subject,
            currentTask: $task,
            currentAssignee: $task?->assignee,
            candidateActors: $candidates,
            availableTransitions: $this->availableTransitions($instance),
            history: $instance->logs->sortBy('created_at')->values(),
            timeInCurrentStateSeconds: (int) $enteredAt->diffInSeconds(now()),
        );
    }

    /** @return EloquentCollection<int, WorkflowTransition> */
    private function availableTransitions(WorkflowInstance $instance): EloquentCollection
    {
        if (! $instance->isRunning()) {
            return new EloquentCollection;
        }

        $transitionClass = WorkflowModelRegistry::transition();

        return $transitionClass::query()
            ->where('workflow_version_id', $instance->workflow_version_id)
            ->where('from_state_id', $instance->current_state_id)
            ->with('toState')
            ->orderBy('action')
            ->get();
    }
}
