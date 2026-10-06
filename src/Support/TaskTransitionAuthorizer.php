<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Ademakanaky\LaravelWorkflows\Contracts\TransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowParticipantResolver;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

class TaskTransitionAuthorizer implements TransitionAuthorizer
{
    public function __construct(private readonly WorkflowParticipantResolver $participants) {}

    public function authorize(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition): bool
    {
        $task = $instance->tasks()
            ->where('workflow_state_id', $instance->current_state_id)
            ->where('status', WorkflowTaskStatus::Open->value)
            ->latest('id')
            ->first();

        if (! $task) {
            return true;
        }

        if ($actor === null) {
            return $task->assignee_id === null && ! $task->candidates()->exists();
        }

        if ($task->assignee_id !== null) {
            $assignee = $task->assignee;

            return $assignee
                ? $this->participants->matches($actor, $assignee)
                : $this->matchesReference($actor, $task->assignee_type, $task->assignee_id);
        }

        $candidates = $task->candidates()->with('candidate')->get();
        if ($candidates->isEmpty()) {
            return true;
        }

        return $candidates->contains(function ($candidate) use ($actor): bool {
            return $candidate->candidate
                ? $this->participants->matches($actor, $candidate->candidate)
                : $this->matchesReference($actor, $candidate->candidate_type, $candidate->candidate_id);
        });
    }

    private function matchesReference(Model $actor, ?string $type, string|int|null $id): bool
    {
        foreach ($this->participants->principals($actor) as $principal) {
            if ($principal->getMorphClass() === $type && (string) $principal->getKey() === (string) $id) {
                return true;
            }
        }

        return false;
    }
}
