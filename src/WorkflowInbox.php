<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkflowInbox
{
    /** @return Builder<WorkflowTask> */
    public function query(Model $actor): Builder
    {
        if (! $actor->exists || $actor->getKey() === null) {
            throw new WorkflowException('The workflow inbox actor must be a persisted Eloquent model.');
        }

        $taskClass = WorkflowModelRegistry::task();

        return $taskClass::query()
            ->where('status', WorkflowTaskStatus::Open->value)
            ->where('assignee_type', $actor->getMorphClass())
            ->where('assignee_id', $actor->getKey())
            ->with([
                'state',
                'instance.definition',
                'instance.currentState',
                'instance.subject',
            ])
            ->latest('id');
    }

    /** @return LengthAwarePaginator<int, WorkflowTask> */
    public function paginate(Model $actor, int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        return $this->query($actor)->paginate($perPage, ['*'], $pageName, $page);
    }

    public function count(Model $actor): int
    {
        return $this->query($actor)->count();
    }
}
