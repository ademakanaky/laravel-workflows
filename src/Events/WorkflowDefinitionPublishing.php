<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;

class WorkflowDefinitionPublishing
{
    public function __construct(
        public readonly WorkflowBlueprint $blueprint,
        public readonly WorkflowDefinitionSource $source,
    ) {}
}
