<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Model;

class WorkflowDefinitionActivated
{
    public function __construct(public readonly WorkflowVersion $version, public readonly ?Model $actor = null) {}
}
