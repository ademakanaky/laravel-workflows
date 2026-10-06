<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;

class WorkflowDefinitionPublished
{
    public function __construct(
        public readonly WorkflowVersion $version,
        public readonly WorkflowDefinitionSource $source,
    ) {}
}
