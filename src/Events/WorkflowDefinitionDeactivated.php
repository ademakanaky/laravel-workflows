<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowDefinition;
use Illuminate\Database\Eloquent\Model;

class WorkflowDefinitionDeactivated
{
    public function __construct(public readonly WorkflowDefinition $definition, public readonly ?Model $actor = null) {}
}
