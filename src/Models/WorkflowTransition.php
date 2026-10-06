<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Concerns\ImmutableWorkflowRecord;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workflow_version_id
 * @property int $from_state_id
 * @property int $to_state_id
 * @property string $action
 * @property string $name
 * @property list<string>|null $guards
 * @property array<string, mixed>|null $metadata
 * @property-read WorkflowState $fromState
 * @property-read WorkflowState $toState
 */
class WorkflowTransition extends Model
{
    use ImmutableWorkflowRecord;

    protected $table = 'workflow_transitions';

    protected $guarded = [];

    protected $casts = ['guards' => 'array', 'metadata' => 'array'];

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::version(), 'workflow_version_id');
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
}
