<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowParticipantResolver;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WorkflowInbox
{
    public function __construct(private readonly WorkflowParticipantResolver $participants) {}

    /** @return Builder<WorkflowTask> */
    public function query(Model $actor): Builder
    {
        if (! $actor->exists || $actor->getKey() === null) {
            throw new WorkflowException('The workflow inbox actor must be a persisted Eloquent model.');
        }

        $taskClass = WorkflowModelRegistry::task();
        $principals = collect($this->participants->principals($actor))
            ->push($actor)
            ->filter(fn (Model $principal): bool => $principal->exists && $principal->getKey() !== null)
            ->unique(fn (Model $principal): string => $principal->getMorphClass().'::'.$principal->getKey())
            ->values();

        return $taskClass::query()
            ->where('status', WorkflowTaskStatus::Open->value)
            ->where(function (Builder $query) use ($principals): void {
                foreach ($principals as $principal) {
                    $query->orWhere(function (Builder $assigned) use ($principal): void {
                        $assigned->where('assignee_type', $principal->getMorphClass())
                            ->where('assignee_id', $principal->getKey());
                    });
                }
                $query->orWhere(function (Builder $candidateQuery) use ($principals): void {
                    $candidateQuery->whereNull('assignee_id')
                        ->whereHas('candidates', function (Builder $candidates) use ($principals): void {
                            $candidates->where(function (Builder $references) use ($principals): void {
                                foreach ($principals as $principal) {
                                    $references->orWhere(function (Builder $reference) use ($principal): void {
                                        $reference->where('candidate_type', $principal->getMorphClass())
                                            ->where('candidate_id', $principal->getKey());
                                    });
                                }
                            });
                        });
                });
            })
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
