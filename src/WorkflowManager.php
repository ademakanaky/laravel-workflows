<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Contracts\TransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowParticipantResolver;
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowInstanceStatus;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Events\WorkflowCancelled;
use Ademakanaky\LaravelWorkflows\Events\WorkflowCompleted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowOutcomeReached;
use Ademakanaky\LaravelWorkflows\Events\WorkflowStarted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowStarting;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskAssigned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskCancelled;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskClaimed;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskCompleted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskNudged;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskOpened;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskReleased;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTransitioned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTransitioning;
use Ademakanaky\LaravelWorkflows\Exceptions\IdempotencyConflictException;
use Ademakanaky\LaravelWorkflows\Exceptions\InvalidTransitionException;
use Ademakanaky\LaravelWorkflows\Exceptions\TransitionGuardRejectedException;
use Ademakanaky\LaravelWorkflows\Exceptions\TransitionNotAuthorizedException;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Ademakanaky\LaravelWorkflows\Support\RequestFingerprint;
use Ademakanaky\LaravelWorkflows\Support\WorkflowExtensionRegistry;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkflowManager
{
    public function __construct(
        private readonly DefinitionPublisher $definitions,
        private readonly AssignmentStrategy $assignments,
        private readonly TransitionAuthorizer $authorizer,
        private readonly WorkflowExtensionRegistry $extensions,
        private readonly WorkflowInbox $workflowInbox,
        private readonly WorkflowTaskNotifier $taskNotifier,
        private readonly WorkflowParticipantResolver $participants,
    ) {}

    public function define(WorkflowBlueprint $blueprint): WorkflowVersion
    {
        return $this->definitions->publish($blueprint);
    }

    /** @param array<string, mixed> $context */
    public function start(
        Model $subject,
        string $definition,
        ?Model $actor = null,
        array $context = [],
        ?string $idempotencyKey = null,
    ): WorkflowInstance {
        $this->assertIdempotencyKey($idempotencyKey);
        $this->assertPersisted($subject, 'subject');
        if ($actor) {
            $this->assertPersisted($actor, 'actor');
        }

        $requestHash = RequestFingerprint::make([
            'operation' => 'start',
            'definition' => $definition,
            'subject' => RequestFingerprint::model($subject),
            'actor' => RequestFingerprint::model($actor),
            'context' => $context,
        ]);

        return DB::transaction(function () use ($subject, $definition, $actor, $context, $idempotencyKey, $requestHash): WorkflowInstance {
            $definitionClass = WorkflowModelRegistry::definition();
            $instanceClass = WorkflowModelRegistry::instance();

            $workflow = $definitionClass::query()->where('slug', $definition)->lockForUpdate()->first();
            if (! $workflow) {
                throw new WorkflowException("Workflow definition [{$definition}] is not synchronized.");
            }
            if (! $workflow->is_active) {
                throw new WorkflowException("Workflow definition [{$definition}] is inactive.");
            }

            if ($idempotencyKey !== null) {
                $existing = $instanceClass::query()
                    ->where('workflow_definition_id', $workflow->getKey())
                    ->where('subject_type', $subject->getMorphClass())
                    ->where('subject_id', $subject->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    $this->assertIdempotentRequestMatches($existing->request_hash, $requestHash, $idempotencyKey);

                    return $existing;
                }
            }

            if (! config('workflows.allow_multiple_active_instances', false)) {
                $active = $instanceClass::query()
                    ->where('workflow_definition_id', $workflow->getKey())
                    ->where('subject_type', $subject->getMorphClass())
                    ->where('subject_id', $subject->getKey())
                    ->where('status', WorkflowInstanceStatus::Running->value)
                    ->exists();
                if ($active) {
                    throw new WorkflowException("Subject already has an active [{$definition}] workflow.");
                }
            }

            $version = $workflow->active_version_id
                ? $workflow->activeVersion()->with('states')->first()
                : $workflow->latestVersion()->with('states')->first();
            if (! $version) {
                throw new WorkflowException("Workflow definition [{$definition}] has no published version.");
            }

            $initialState = $version->states->firstWhere('is_initial', true);
            if (! $initialState) {
                throw new WorkflowException("Workflow definition [{$definition}] has no initial state.");
            }
            event(new WorkflowStarting($subject, $version, $actor, $context));

            $instance = new $instanceClass([
                'workflow_definition_id' => $workflow->getKey(),
                'workflow_version_id' => $version->getKey(),
                'current_state_id' => $initialState->getKey(),
                'status' => $initialState->is_final ? WorkflowInstanceStatus::Completed : WorkflowInstanceStatus::Running,
                'context' => $context,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $idempotencyKey !== null ? $requestHash : null,
                'completed_at' => $initialState->is_final ? now() : null,
            ]);
            $instance->subject()->associate($subject);
            if ($actor) {
                $instance->startedBy()->associate($actor);
            }
            $instance->save();

            $this->record($instance, null, $initialState, 'start', $actor, $context, $idempotencyKey, $requestHash);
            $openedTask = $initialState->is_final ? null : $this->createTask($instance, $initialState, $actor);

            $result = $this->reloadInstance($instance);
            DB::afterCommit(function () use ($result, $openedTask, $actor): void {
                event(new WorkflowStarted($result));
                if ($openedTask) {
                    event(new WorkflowTaskOpened($openedTask, $actor));
                    $this->taskNotifier->opened($openedTask, $actor);
                }
            });

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    public function transition(
        WorkflowInstance|string $instance,
        string $action,
        ?Model $actor = null,
        array $data = [],
        ?string $idempotencyKey = null,
    ): WorkflowInstance {
        $this->assertIdempotencyKey($idempotencyKey);
        if ($actor) {
            $this->assertPersisted($actor, 'actor');
        }

        $id = $instance instanceof WorkflowInstance ? $instance->getKey() : $instance;
        $requestHash = RequestFingerprint::make([
            'operation' => 'transition',
            'action' => $action,
            'actor' => RequestFingerprint::model($actor),
            'data' => $data,
        ]);

        return DB::transaction(function () use ($id, $action, $actor, $data, $idempotencyKey, $requestHash): WorkflowInstance {
            $instanceClass = WorkflowModelRegistry::instance();
            $current = $instanceClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = $current->logs()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $this->assertIdempotentRequestMatches($existing->request_hash, $requestHash, $idempotencyKey);

                    return $this->reloadInstance($current);
                }
            }

            if (! $current->isRunning()) {
                throw new InvalidTransitionException('Only running workflow instances may transition.');
            }

            $transitionClass = WorkflowModelRegistry::transition();
            $transition = $transitionClass::query()
                ->where('workflow_version_id', $current->workflow_version_id)
                ->where('from_state_id', $current->current_state_id)
                ->where('action', $action)
                ->with('toState')
                ->first();

            if (! $transition) {
                throw new InvalidTransitionException("Action [{$action}] is not available from the current state.");
            }

            if (! $this->authorizer->authorize($actor, $current, $transition)) {
                throw new TransitionNotAuthorizedException("Actor is not authorized to perform [{$action}].");
            }

            foreach ($transition->guards ?? [] as $guardAlias) {
                $guardClass = $this->extensions->resolveGuard($guardAlias);
                $guard = app($guardClass);
                if (! $guard->allows($actor, $current, $transition, $data)) {
                    throw new TransitionGuardRejectedException($guard->message());
                }
            }

            event(new WorkflowTransitioning($current, $transition, $actor, $data));

            foreach ($transition->handlers ?? [] as $handlerAlias) {
                $handlerClass = $this->extensions->resolveActionHandler($handlerAlias);
                app($handlerClass)->handle($actor, $current, $transition, $data);
            }

            $from = $current->current_state_id;
            $completedTasks = $current->tasks()
                ->where('workflow_state_id', $from)
                ->where('status', WorkflowTaskStatus::Open->value)
                ->get();
            $current->tasks()
                ->whereKey($completedTasks->modelKeys())
                ->update(['status' => WorkflowTaskStatus::Completed->value, 'completed_at' => now()]);
            $completedTasks->each->refresh();

            $current->update([
                'current_state_id' => $transition->to_state_id,
                'status' => $transition->toState->is_final ? WorkflowInstanceStatus::Completed : WorkflowInstanceStatus::Running,
                'completed_at' => $transition->toState->is_final ? now() : null,
                'lock_version' => $current->lock_version + 1,
            ]);
            $completedTasks->load($this->taskRelations());

            $log = $this->record($current, $from, $transition->toState, $action, $actor, $data, $idempotencyKey, $requestHash);
            $openedTask = $transition->toState->is_final ? null : $this->createTask($current, $transition->toState, $actor);

            $result = $this->reloadInstance($current);
            DB::afterCommit(function () use ($result, $log, $completedTasks, $openedTask, $actor, $transition, $data): void {
                event(new WorkflowTransitioned($result, $log));
                foreach ($completedTasks as $completedTask) {
                    event(new WorkflowTaskCompleted($completedTask, $actor));
                    $this->taskNotifier->completed($completedTask, $actor);
                }
                if ($openedTask) {
                    event(new WorkflowTaskOpened($openedTask, $actor));
                    $this->taskNotifier->opened($openedTask, $actor);
                }
                if ($result->status === WorkflowInstanceStatus::Completed) {
                    $outcome = $result->currentState->metadata['outcome'] ?? null;
                    if (is_string($outcome)) {
                        event(new WorkflowOutcomeReached($result, $outcome));
                    }
                    event(new WorkflowCompleted($result));
                }
                foreach ($transition->after_commit_handlers ?? [] as $handlerAlias) {
                    $handlerClass = $this->extensions->resolveActionHandler($handlerAlias);
                    app($handlerClass)->handle($actor, $result, $transition, $data);
                }
            });

            return $result;
        });
    }

    /** @return Collection<int, WorkflowTransition> */
    public function availableActions(WorkflowInstance $instance, ?Model $actor = null): Collection
    {
        if (! $instance->isRunning()) {
            return collect();
        }

        $transitionClass = WorkflowModelRegistry::transition();

        return $transitionClass::query()
            ->where('workflow_version_id', $instance->workflow_version_id)
            ->where('from_state_id', $instance->current_state_id)
            ->with('toState')
            ->orderBy('action')
            ->get()
            ->filter(fn (WorkflowTransition $transition): bool => $this->authorizer->authorize($actor, $instance, $transition))
            ->values();
    }

    /** @return Builder<WorkflowTask> */
    public function inbox(Model $actor): Builder
    {
        return $this->workflowInbox->query($actor);
    }

    public function pendingCount(Model $actor): int
    {
        return $this->workflowInbox->count($actor);
    }

    /** @param array<string, mixed> $data */
    public function assign(
        WorkflowInstance|string $instance,
        ?Model $assignee,
        ?Model $actor = null,
        array $data = [],
        ?string $idempotencyKey = null,
    ): WorkflowInstance {
        $this->assertIdempotencyKey($idempotencyKey);
        if ($assignee) {
            $this->assertPersisted($assignee, 'assignee');
        }
        if ($actor) {
            $this->assertPersisted($actor, 'actor');
        }

        $id = $instance instanceof WorkflowInstance ? $instance->getKey() : $instance;
        $requestHash = RequestFingerprint::make([
            'operation' => 'assign',
            'assignee' => RequestFingerprint::model($assignee),
            'actor' => RequestFingerprint::model($actor),
            'data' => $data,
        ]);

        return DB::transaction(function () use ($id, $assignee, $actor, $data, $idempotencyKey, $requestHash): WorkflowInstance {
            $instanceClass = WorkflowModelRegistry::instance();
            $current = $instanceClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = $current->logs()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $this->assertIdempotentRequestMatches($existing->request_hash, $requestHash, $idempotencyKey);

                    return $this->reloadInstance($current);
                }
            }

            if (! $current->isRunning()) {
                throw new WorkflowException('Only running workflow instances may be assigned.');
            }

            $task = $current->tasks()
                ->where('workflow_state_id', $current->current_state_id)
                ->where('status', WorkflowTaskStatus::Open->value)
                ->latest('id')
                ->firstOrFail();
            $previousAssignee = $task->assignee;

            if ($assignee) {
                $task->assignee()->associate($assignee);
                $task->assigned_at = now();
            } else {
                $task->assignee()->dissociate();
                $task->assigned_at = null;
                $task->claimed_at = null;
            }
            $task->save();

            $assignmentData = array_merge($data, [
                'assignment' => [
                    'previous' => $previousAssignee ? [
                        'type' => $previousAssignee->getMorphClass(),
                        'id' => $previousAssignee->getKey(),
                    ] : null,
                    'new' => $assignee ? [
                        'type' => $assignee->getMorphClass(),
                        'id' => $assignee->getKey(),
                    ] : null,
                ],
            ]);
            $this->record(
                $current,
                $current->current_state_id,
                $current->currentState,
                'assign',
                $actor,
                $assignmentData,
                $idempotencyKey,
                $requestHash,
            );

            $result = $this->reloadInstance($current);
            $assignedTask = $task->refresh()->load('assignee');
            DB::afterCommit(function () use ($assignedTask, $previousAssignee, $actor): void {
                event(new WorkflowTaskAssigned($assignedTask, $previousAssignee, $actor));
                $this->taskNotifier->assigned($assignedTask, $previousAssignee, $actor);
            });

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    public function claim(
        WorkflowTask|int|string $task,
        Model $actor,
        array $data = [],
        ?string $idempotencyKey = null,
    ): WorkflowInstance {
        $this->assertIdempotencyKey($idempotencyKey);
        $this->assertPersisted($actor, 'actor');
        $id = $task instanceof WorkflowTask ? $task->getKey() : $task;
        $requestHash = RequestFingerprint::make([
            'operation' => 'claim',
            'task' => $id,
            'actor' => RequestFingerprint::model($actor),
            'data' => $data,
        ]);

        return DB::transaction(function () use ($id, $actor, $data, $idempotencyKey, $requestHash): WorkflowInstance {
            $taskClass = WorkflowModelRegistry::task();
            $currentTask = $taskClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $instanceClass = WorkflowModelRegistry::instance();
            $instance = $instanceClass::query()->whereKey($currentTask->workflow_instance_id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null && $existing = $instance->logs()->where('idempotency_key', $idempotencyKey)->first()) {
                $this->assertIdempotentRequestMatches($existing->request_hash, $requestHash, $idempotencyKey);

                return $this->reloadInstance($instance);
            }
            if ($currentTask->status !== WorkflowTaskStatus::Open || ! $instance->isRunning()) {
                throw new WorkflowException('Only an open task on a running workflow may be claimed.');
            }
            if ($currentTask->assignee_id !== null) {
                throw new WorkflowException('The workflow task is already assigned.');
            }
            if (! $this->actorIsCandidate($currentTask, $actor)) {
                throw new WorkflowException('The actor is not a candidate for this workflow task.');
            }

            $currentTask->assignee()->associate($actor);
            $currentTask->assigned_at = now();
            $currentTask->claimed_at = now();
            $currentTask->save();
            $this->record($instance, $instance->current_state_id, $instance->currentState, 'claim', $actor, $data, $idempotencyKey, $requestHash);

            $claimedTask = $currentTask->refresh()->load($this->taskRelations());
            $result = $this->reloadInstance($instance);
            DB::afterCommit(fn () => event(new WorkflowTaskClaimed($claimedTask, $actor)));

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    public function release(
        WorkflowTask|int|string $task,
        Model $actor,
        array $data = [],
        ?string $idempotencyKey = null,
    ): WorkflowInstance {
        $this->assertIdempotencyKey($idempotencyKey);
        $this->assertPersisted($actor, 'actor');
        $id = $task instanceof WorkflowTask ? $task->getKey() : $task;
        $requestHash = RequestFingerprint::make([
            'operation' => 'release',
            'task' => $id,
            'actor' => RequestFingerprint::model($actor),
            'data' => $data,
        ]);

        return DB::transaction(function () use ($id, $actor, $data, $idempotencyKey, $requestHash): WorkflowInstance {
            $taskClass = WorkflowModelRegistry::task();
            $currentTask = $taskClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $instanceClass = WorkflowModelRegistry::instance();
            $instance = $instanceClass::query()->whereKey($currentTask->workflow_instance_id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null && $existing = $instance->logs()->where('idempotency_key', $idempotencyKey)->first()) {
                $this->assertIdempotentRequestMatches($existing->request_hash, $requestHash, $idempotencyKey);

                return $this->reloadInstance($instance);
            }
            if ($currentTask->status !== WorkflowTaskStatus::Open || $currentTask->assignee_id === null) {
                throw new WorkflowException('Only an assigned open task may be released.');
            }
            if (! $currentTask->assignee || ! $this->participants->matches($actor, $currentTask->assignee)) {
                throw new WorkflowException('Only the current assignee may release this workflow task.');
            }

            $currentTask->assignee()->dissociate();
            $currentTask->assigned_at = null;
            $currentTask->claimed_at = null;
            $currentTask->save();
            $this->record($instance, $instance->current_state_id, $instance->currentState, 'release', $actor, $data, $idempotencyKey, $requestHash);

            $releasedTask = $currentTask->refresh()->load($this->taskRelations());
            $result = $this->reloadInstance($instance);
            DB::afterCommit(fn () => event(new WorkflowTaskReleased($releasedTask, $actor)));

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    public function nudge(
        WorkflowTask|int|string $task,
        ?Model $actor = null,
        array $data = [],
        ?string $idempotencyKey = null,
    ): WorkflowInstance {
        $this->assertIdempotencyKey($idempotencyKey);
        if ($actor) {
            $this->assertPersisted($actor, 'actor');
        }
        $id = $task instanceof WorkflowTask ? $task->getKey() : $task;
        $requestHash = RequestFingerprint::make([
            'operation' => 'nudge',
            'task' => $id,
            'actor' => RequestFingerprint::model($actor),
            'data' => $data,
        ]);

        return DB::transaction(function () use ($id, $actor, $data, $idempotencyKey, $requestHash): WorkflowInstance {
            $taskClass = WorkflowModelRegistry::task();
            $currentTask = $taskClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $instanceClass = WorkflowModelRegistry::instance();
            $instance = $instanceClass::query()->whereKey($currentTask->workflow_instance_id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null && $existing = $instance->logs()->where('idempotency_key', $idempotencyKey)->first()) {
                $this->assertIdempotentRequestMatches($existing->request_hash, $requestHash, $idempotencyKey);

                return $this->reloadInstance($instance);
            }
            if ($currentTask->status !== WorkflowTaskStatus::Open || ! $instance->isRunning()) {
                throw new WorkflowException('Only an open task on a running workflow may be nudged.');
            }

            $currentTask->update([
                'last_nudged_at' => now(),
                'nudge_count' => $currentTask->nudge_count + 1,
            ]);
            $this->record($instance, $instance->current_state_id, $instance->currentState, 'nudge', $actor, $data, $idempotencyKey, $requestHash);

            $nudgedTask = $currentTask->refresh()->load($this->taskRelations());
            $result = $this->reloadInstance($instance);
            DB::afterCommit(function () use ($nudgedTask, $actor, $data): void {
                event(new WorkflowTaskNudged($nudgedTask, $actor, $data));
                $this->taskNotifier->nudged($nudgedTask, $actor, $data);
            });

            return $result;
        });
    }

    /** @param array<string, mixed> $data */
    public function cancel(
        WorkflowInstance|string $instance,
        ?Model $actor = null,
        array $data = [],
        ?string $idempotencyKey = null,
    ): WorkflowInstance {
        $this->assertIdempotencyKey($idempotencyKey);
        if ($actor) {
            $this->assertPersisted($actor, 'actor');
        }

        $id = $instance instanceof WorkflowInstance ? $instance->getKey() : $instance;
        $requestHash = RequestFingerprint::make([
            'operation' => 'cancel',
            'actor' => RequestFingerprint::model($actor),
            'data' => $data,
        ]);

        return DB::transaction(function () use ($id, $actor, $data, $idempotencyKey, $requestHash): WorkflowInstance {
            $instanceClass = WorkflowModelRegistry::instance();
            $current = $instanceClass::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = $current->logs()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $this->assertIdempotentRequestMatches($existing->request_hash, $requestHash, $idempotencyKey);

                    return $this->reloadInstance($current);
                }
            }

            if (! $current->isRunning()) {
                throw new WorkflowException('Only running workflow instances may be cancelled.');
            }

            $cancelledTasks = $current->tasks()
                ->where('status', WorkflowTaskStatus::Open->value)
                ->get();
            $current->tasks()
                ->whereKey($cancelledTasks->modelKeys())
                ->update(['status' => WorkflowTaskStatus::Cancelled->value, 'completed_at' => now()]);
            $cancelledTasks->each->refresh();
            $current->update([
                'status' => WorkflowInstanceStatus::Cancelled,
                'cancelled_at' => now(),
                'lock_version' => $current->lock_version + 1,
            ]);
            $cancelledTasks->load($this->taskRelations());

            $log = $this->record(
                $current,
                $current->current_state_id,
                $current->currentState,
                'cancel',
                $actor,
                $data,
                $idempotencyKey,
                $requestHash,
            );
            $result = $this->reloadInstance($current);
            DB::afterCommit(function () use ($result, $log, $cancelledTasks, $actor): void {
                event(new WorkflowCancelled($result, $log));
                foreach ($cancelledTasks as $cancelledTask) {
                    event(new WorkflowTaskCancelled($cancelledTask, $actor));
                    $this->taskNotifier->cancelled($cancelledTask, $actor);
                }
            });

            return $result;
        });
    }

    private function createTask(WorkflowInstance $instance, WorkflowState $state, ?Model $actor): WorkflowTask
    {
        $state->loadMissing('candidates.candidate');
        $assignmentStrategy = $this->assignments;
        if ($state->assignment_strategy !== null) {
            $assignmentStrategy = app($this->extensions->resolveAssignmentStrategy($state->assignment_strategy));
        }
        $assignee = $assignmentStrategy->assign($instance, $state, $actor);
        if ($assignee) {
            $this->assertPersisted($assignee, 'assignee');
        } elseif ($state->candidates->count() === 1) {
            $soleCandidate = $state->candidates->first();
            if ($soleCandidate?->candidate instanceof Model) {
                $assignee = $soleCandidate->candidate;
            }
        }
        $taskClass = WorkflowModelRegistry::task();
        $task = new $taskClass([
            'workflow_instance_id' => $instance->getKey(),
            'workflow_state_id' => $state->getKey(),
            'status' => WorkflowTaskStatus::Open,
            'metadata' => [],
        ]);
        if ($assignee) {
            $task->assignee()->associate($assignee);
            $task->assigned_at = now();
        }
        $task->save();

        foreach ($state->candidates as $candidate) {
            $task->candidates()->create([
                'candidate_type' => $candidate->candidate_type,
                'candidate_id' => $candidate->candidate_id,
            ]);
        }

        return $task->load($this->taskRelations());
    }

    private function actorIsCandidate(WorkflowTask $task, Model $actor): bool
    {
        $candidates = $task->candidates()->with('candidate')->get();
        if ($candidates->isEmpty()) {
            return true;
        }

        return $candidates->contains(function ($candidate) use ($actor): bool {
            if ($candidate->candidate instanceof Model) {
                return $this->participants->matches($actor, $candidate->candidate);
            }
            foreach ($this->participants->principals($actor) as $principal) {
                if ($principal->getMorphClass() === $candidate->candidate_type
                    && (string) $principal->getKey() === (string) $candidate->candidate_id) {
                    return true;
                }
            }

            return false;
        });
    }

    /** @param array<string, mixed> $data */
    private function record(
        WorkflowInstance $instance,
        int|string|null $fromState,
        WorkflowState $toState,
        string $action,
        ?Model $actor,
        array $data,
        ?string $idempotencyKey,
        string $requestHash,
    ): WorkflowTransitionLog {
        $logClass = WorkflowModelRegistry::log();
        $log = new $logClass([
            'workflow_instance_id' => $instance->getKey(),
            'from_state_id' => $fromState,
            'to_state_id' => $toState->getKey(),
            'action' => $action,
            'data' => $data,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $idempotencyKey !== null ? $requestHash : null,
        ]);
        if ($actor) {
            $log->actor()->associate($actor);
        }
        $log->save();

        return $log;
    }

    private function assertIdempotentRequestMatches(?string $storedHash, string $requestHash, string $idempotencyKey): void
    {
        if ($storedHash !== $requestHash) {
            throw new IdempotencyConflictException(
                "Idempotency key [{$idempotencyKey}] was already used for a different operation."
            );
        }
    }

    private function assertPersisted(Model $model, string $role): void
    {
        if (! $model->exists || $model->getKey() === null) {
            throw new WorkflowException("The workflow {$role} must be a persisted Eloquent model.");
        }

        if (strlen($model->getMorphClass()) > 191 || strlen((string) $model->getKey()) > 191) {
            throw new WorkflowException("The workflow {$role} type and identifier must not exceed 191 characters.");
        }
    }

    private function assertIdempotencyKey(?string $idempotencyKey): void
    {
        if ($idempotencyKey === null) {
            return;
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new WorkflowException('An idempotency key must contain between 1 and 191 characters.');
        }
    }

    /** @return list<string> */
    private function defaultRelations(): array
    {
        return ['definition', 'version', 'currentState', 'subject', 'tasks.assignee'];
    }

    /** @return list<string> */
    private function taskRelations(): array
    {
        return ['assignee', 'candidates.candidate', 'state', 'instance.definition', 'instance.currentState', 'instance.subject'];
    }

    private function reloadInstance(WorkflowInstance $instance): WorkflowInstance
    {
        return $instance->refresh()->load($this->defaultRelations());
    }
}
