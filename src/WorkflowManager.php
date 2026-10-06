<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Contracts\TransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowInstanceStatus;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Events\WorkflowCancelled;
use Ademakanaky\LaravelWorkflows\Events\WorkflowCompleted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowStarted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowStarting;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskAssigned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTransitioned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTransitioning;
use Ademakanaky\LaravelWorkflows\Exceptions\IdempotencyConflictException;
use Ademakanaky\LaravelWorkflows\Exceptions\InvalidTransitionException;
use Ademakanaky\LaravelWorkflows\Exceptions\TransitionGuardRejectedException;
use Ademakanaky\LaravelWorkflows\Exceptions\TransitionNotAuthorizedException;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Ademakanaky\LaravelWorkflows\Support\RequestFingerprint;
use Ademakanaky\LaravelWorkflows\Support\WorkflowExtensionRegistry;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
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

            $version = $workflow->latestVersion()->with('states')->first();
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
            if (! $initialState->is_final) {
                $this->createTask($instance, $initialState, $actor);
            }

            $result = $this->reloadInstance($instance);
            DB::afterCommit(fn () => event(new WorkflowStarted($result)));

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

            $from = $current->current_state_id;
            $current->tasks()
                ->where('workflow_state_id', $from)
                ->where('status', WorkflowTaskStatus::Open->value)
                ->update(['status' => WorkflowTaskStatus::Completed->value, 'completed_at' => now()]);

            $current->update([
                'current_state_id' => $transition->to_state_id,
                'status' => $transition->toState->is_final ? WorkflowInstanceStatus::Completed : WorkflowInstanceStatus::Running,
                'completed_at' => $transition->toState->is_final ? now() : null,
                'lock_version' => $current->lock_version + 1,
            ]);

            $log = $this->record($current, $from, $transition->toState, $action, $actor, $data, $idempotencyKey, $requestHash);
            if (! $transition->toState->is_final) {
                $this->createTask($current, $transition->toState, $actor);
            }

            $result = $this->reloadInstance($current);
            DB::afterCommit(function () use ($result, $log): void {
                event(new WorkflowTransitioned($result, $log));
                if ($result->status === WorkflowInstanceStatus::Completed) {
                    event(new WorkflowCompleted($result));
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
            } else {
                $task->assignee()->dissociate();
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
            DB::afterCommit(fn () => event(new WorkflowTaskAssigned($assignedTask, $previousAssignee, $actor)));

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

            $current->tasks()
                ->where('status', WorkflowTaskStatus::Open->value)
                ->update(['status' => WorkflowTaskStatus::Cancelled->value, 'completed_at' => now()]);
            $current->update([
                'status' => WorkflowInstanceStatus::Cancelled,
                'cancelled_at' => now(),
                'lock_version' => $current->lock_version + 1,
            ]);

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
            DB::afterCommit(fn () => event(new WorkflowCancelled($result, $log)));

            return $result;
        });
    }

    private function createTask(WorkflowInstance $instance, WorkflowState $state, ?Model $actor): void
    {
        $assignmentStrategy = $this->assignments;
        if ($state->assignment_strategy !== null) {
            $assignmentStrategy = app($this->extensions->resolveAssignmentStrategy($state->assignment_strategy));
        }
        $assignee = $assignmentStrategy->assign($instance, $state, $actor);
        if ($assignee) {
            $this->assertPersisted($assignee, 'assignee');
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
        }
        $task->save();
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

    private function reloadInstance(WorkflowInstance $instance): WorkflowInstance
    {
        return $instance->refresh()->load($this->defaultRelations());
    }
}
