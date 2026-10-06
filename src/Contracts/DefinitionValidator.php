<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;

interface DefinitionValidator
{
    public function validate(WorkflowBlueprint $blueprint): WorkflowBlueprint;
}
