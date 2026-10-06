<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Concerns\ImmutableWorkflowRecord;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $workflow_task_id
 * @property string $candidate_type
 * @property string $candidate_id
 * @property-read Model|null $candidate
 */
class WorkflowTaskCandidate extends Model
{
    use ImmutableWorkflowRecord;

    protected $table = 'workflow_task_candidates';

    protected $guarded = [];

    /** @return BelongsTo<WorkflowTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkflowModelRegistry::task(), 'workflow_task_id');
    }

    /** @return MorphTo<Model, $this> */
    public function candidate(): MorphTo
    {
        return $this->morphTo();
    }
}
