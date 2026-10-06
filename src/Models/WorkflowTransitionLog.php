<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Concerns\ImmutableWorkflowRecord;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $workflow_instance_id
 * @property int|null $from_state_id
 * @property int $to_state_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property array<string, mixed>|null $data
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property CarbonInterface $created_at
 */
class WorkflowTransitionLog extends Model
{
    use HasUuids, ImmutableWorkflowRecord;

    public const UPDATED_AT = null;

    protected $table = 'workflow_transition_logs';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = ['data' => 'array'];

    /** @return BelongsTo<WorkflowInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::instance(), 'workflow_instance_id');
    }

    /** @return BelongsTo<WorkflowState, $this> */
    public function fromState(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::state(), 'from_state_id');
    }

    /** @return BelongsTo<WorkflowState, $this> */
    public function toState(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::state(), 'to_state_id');
    }

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
