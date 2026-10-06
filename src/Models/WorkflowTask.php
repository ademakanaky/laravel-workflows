<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $workflow_instance_id
 * @property int $workflow_state_id
 * @property string|null $assignee_type
 * @property string|null $assignee_id
 * @property WorkflowTaskStatus $status
 * @property array<string, mixed>|null $metadata
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
        'completed_at' => 'immutable_datetime',
    ];

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
}
