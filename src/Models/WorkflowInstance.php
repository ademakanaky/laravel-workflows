<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowInstanceStatus;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property int $workflow_definition_id
 * @property int $workflow_version_id
 * @property int $current_state_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $started_by_type
 * @property string|null $started_by_id
 * @property WorkflowInstanceStatus $status
 * @property array<string, mixed>|null $context
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property int $lock_version
 * @property-read WorkflowDefinition $definition
 * @property-read WorkflowVersion $version
 * @property-read WorkflowState $currentState
 * @property-read Model $subject
 * @property-read Model|null $startedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkflowTask> $tasks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, WorkflowTransitionLog> $logs
 */
class WorkflowInstance extends Model
{
    use HasUuids;

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'status' => WorkflowInstanceStatus::class,
        'context' => 'array',
        'completed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<WorkflowDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::definition(), 'workflow_definition_id');
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::version(), 'workflow_version_id');
    }

    /** @return BelongsTo<WorkflowState, $this> */
    public function currentState(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::state(), 'current_state_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function startedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<WorkflowTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::task());
    }

    /** @return HasMany<WorkflowTransitionLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::log());
    }

    public function isRunning(): bool
    {
        return $this->status === WorkflowInstanceStatus::Running;
    }

    /** @param array<string, mixed> $data */
    public function transition(string $action, ?Model $actor = null, array $data = [], ?string $idempotencyKey = null): self
    {
        return app(WorkflowManager::class)->transition($this, $action, $actor, $data, $idempotencyKey);
    }

    /** @return Collection<int, WorkflowTransition> */
    public function availableTransitions(?Model $actor = null): Collection
    {
        return app(WorkflowManager::class)->availableActions($this, $actor);
    }

    /** @param array<string, mixed> $data */
    public function cancel(?Model $actor = null, array $data = [], ?string $idempotencyKey = null): self
    {
        return app(WorkflowManager::class)->cancel($this, $actor, $data, $idempotencyKey);
    }
}
