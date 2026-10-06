<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Concerns\ImmutableWorkflowRecord;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $workflow_state_id
 * @property string $candidate_type
 * @property string $candidate_id
 * @property-read Model|null $candidate
 */
class WorkflowStateCandidate extends Model
{
    use ImmutableWorkflowRecord;

    protected $table = 'workflow_state_candidates';

    protected $guarded = [];

    /** @return BelongsTo<WorkflowState, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::state(), 'workflow_state_id');
    }

    /** @return MorphTo<Model, $this> */
    public function candidate(): MorphTo
    {
        return $this->morphTo();
    }
}
