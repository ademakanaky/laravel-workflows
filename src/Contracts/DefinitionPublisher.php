<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;

interface DefinitionPublisher
{
    public function publish(
        WorkflowBlueprint $blueprint,
        WorkflowDefinitionSource $source = WorkflowDefinitionSource::Code,
    ): WorkflowVersion;
}
