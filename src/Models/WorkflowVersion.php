<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Concerns\ImmutableWorkflowRecord;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $workflow_definition_id
 * @property int $version
 * @property string $name
 * @property string|null $description
 * @property string $checksum
 * @property array<string, mixed>|null $metadata
 * @property-read WorkflowDefinition $definition
 * @property-read Collection<int, WorkflowState> $states
 * @property-read Collection<int, WorkflowTransition> $transitions
 */
class WorkflowVersion extends Model
{
    use ImmutableWorkflowRecord;

    protected $guarded = [];

    protected $casts = ['metadata' => 'array', 'published_at' => 'immutable_datetime'];

    /** @return BelongsTo<WorkflowDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::definition(), 'workflow_definition_id');
    }

    /** @return HasMany<WorkflowState, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::state());
    }

    /** @return HasMany<WorkflowTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::transition());
    }

    /** @return HasOne<WorkflowState, $this> */
    public function initialState(): HasOne
    {
        return $this->hasOne(WorkflowModelRegistry::state())->where('is_initial', true);
    }
}
