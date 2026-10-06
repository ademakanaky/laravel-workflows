<?php

namespace Ademakanaky\LaravelWorkflows\Models;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;
use Ademakanaky\LaravelWorkflows\Exceptions\ImmutableWorkflowRecordException;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property WorkflowDefinitionSource $managed_by
 */
class WorkflowDefinition extends Model
{
    protected $table = 'workflow_definitions';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $definition): void {
            if ($definition->isDirty(['slug', 'managed_by'])) {
                throw new ImmutableWorkflowRecordException(
                    'A workflow definition slug and ownership source are immutable after creation.'
                );
            }
        });

        static::deleting(function (): never {
            throw new ImmutableWorkflowRecordException(
                'Published workflow definitions cannot be deleted; preserve them for version and audit integrity.'
            );
        });
    }

    protected $casts = ['managed_by' => WorkflowDefinitionSource::class];

    /** @return HasMany<WorkflowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::version(), 'workflow_definition_id');
    }

    /** @return HasOne<WorkflowVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(WorkflowModelRegistry::version(), 'workflow_definition_id')->ofMany('version', 'max');
    }

    /** @return HasMany<WorkflowInstance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowModelRegistry::instance(), 'workflow_definition_id');
    }
}
