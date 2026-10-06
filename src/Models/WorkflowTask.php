<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $workflow_instance_id
 * @property int $workflow_state_id
 * @property string|null $assignee_type
 * @property string|null $assignee_id
 * @property WorkflowTaskStatus $status
 * @property array<string, mixed>|null $metadata
 * @property int $nudge_count
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $assigned_at
 * @property CarbonInterface|null $claimed_at
 * @property CarbonInterface|null $last_nudged_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface $created_at
 * @property-read WorkflowInstance $instance
 * @property-read WorkflowState $state
 * @property-read Collection<int, WorkflowTaskCandidate> $candidates
 * @property-read Model|null $assignee
 */
class WorkflowTask extends Model
{
    protected $table = 'workflow_tasks';

    protected $guarded = [];

    protected $casts = [
        'status' => WorkflowTaskStatus::class,
        'metadata' => 'array',
        'due_at' => 'immutable_datetime',
        'assigned_at' => 'immutable_datetime',
        'claimed_at' => 'immutable_datetime',
        'last_nudged_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    /**
     * @param  Builder<WorkflowTask>  $query
     * @return Builder<WorkflowTask>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', WorkflowTaskStatus::Open->value);
    }

    /**
     * @param  Builder<WorkflowTask>  $query
     * @return Builder<WorkflowTask>
     */
    public function scopeAssignedTo(Builder $query, Model $actor): Builder
    {
        if (! $actor->exists || $actor->getKey() === null) {
            throw new WorkflowException('The workflow inbox actor must be a persisted Eloquent model.');
        }

        return $query
            ->where('assignee_type', $actor->getMorphClass())
            ->where('assignee_id', $actor->getKey());
    }

    /**
     * @param  Builder<WorkflowTask>  $query
     * @return Builder<WorkflowTask>
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $query
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<', $at ?? now());
    }

    /**
     * @param  Builder<WorkflowTask>  $query
     * @return Builder<WorkflowTask>
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assignee_id');
    }

    /**
     * @param  Builder<WorkflowTask>  $query
     * @return Builder<WorkflowTask>
     */
    public function scopeForWorkflow(Builder $query, string $slug): Builder
    {
        return $query->whereHas('instance.definition', fn (Builder $definition): Builder => $definition->where('slug', $slug));
    }

    /**
     * @param  Builder<WorkflowTask>  $query
     * @return Builder<WorkflowTask>
     */
    public function scopeInState(Builder $query, string $state): Builder
    {
        return $query->whereHas('state', fn (Builder $workflowState): Builder => $workflowState->where('key', $state));
    }

    /** @return BelongsTo<WorkflowInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::instance(), 'workflow_instance_id');
    }

    /** @return BelongsTo<WorkflowState, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::state(), 'workflow_state_id');
    }

    /** @return MorphTo<Model, $this> */
    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<WorkflowTaskCandidate, $this> */
    public function candidates(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::taskCandidate(), 'workflow_task_id');
    }
}
