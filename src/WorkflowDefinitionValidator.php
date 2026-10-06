<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Contracts\DefinitionValidator;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Support\WorkflowExtensionRegistry;

class WorkflowDefinitionValidator implements DefinitionValidator
{
    public function __construct(private readonly WorkflowExtensionRegistry $extensions) {}

    public function validate(WorkflowBlueprint $blueprint): WorkflowBlueprint
    {
        $blueprint->validate();
        $payload = $blueprint->toArray();

        foreach ($payload['states'] as $state) {
            if ($state['assignment_strategy'] !== null) {
                $this->extensions->resolveAssignmentStrategy($state['assignment_strategy']);
            }
        }

        foreach ($payload['transitions'] as $transition) {
            foreach ($transition['guards'] as $guard) {
                $this->extensions->resolveGuard($guard);
            }
        }

        return $blueprint;
    }
}
