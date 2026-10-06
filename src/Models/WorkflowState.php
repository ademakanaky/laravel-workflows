<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Concerns\ImmutableWorkflowRecord;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workflow_version_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_initial
 * @property bool $is_final
 * @property string|null $assignment_strategy
 * @property array<string, mixed>|null $metadata
 */
class WorkflowState extends Model
{
    use ImmutableWorkflowRecord;

    protected $table = 'workflow_states';

    protected $guarded = [];

    protected $casts = ['is_initial' => 'boolean', 'is_final' => 'boolean', 'metadata' => 'array'];

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::version(), 'workflow_version_id');
    }

    /** @return HasMany<WorkflowTransition, $this> */
    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::transition(), 'from_state_id');
    }

    /** @return HasMany<WorkflowTransition, $this> */
    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::transition(), 'to_state_id');
    }
}
