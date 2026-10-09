<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;

class WorkflowDefinitionExporter
{
    /** @return array<string, mixed> */
    public function export(WorkflowVersion $version): array
    {
        $version->loadMissing('definition', 'states.candidates', 'transitions.fromState', 'transitions.toState');

        $states = [];
        foreach ($version->states as $state) {
            $states[$state->key] = [
                'name' => $state->name,
                'description' => $state->description,
                'initial' => $state->is_initial,
                'final' => $state->is_final,
                'assignment_strategy' => $state->assignment_strategy,
                'candidates' => $state->candidates->map(fn ($candidate): array => [
                    'type' => $candidate->candidate_type,
                    'id' => $candidate->candidate_id,
                ])->all(),
                'metadata' => $state->metadata ?? [],
            ];
        }

        $transitions = $version->transitions->map(fn ($transition): array => [
            'action' => $transition->action,
            'name' => $transition->name,
            'from' => $transition->fromState->key,
            'to' => $transition->toState->key,
            'guards' => $transition->guards ?? [],
            'handlers' => $transition->handlers ?? [],
            'after_commit_handlers' => $transition->after_commit_handlers ?? [],
            'metadata' => $transition->metadata ?? [],
        ])->all();

        return WorkflowBlueprint::fromArray($version->definition->slug, [
            'name' => $version->name,
            'description' => $version->description,
            'metadata' => $version->metadata ?? [],
            'states' => $states,
            'transitions' => $transitions,
        ])->toArray();
    }

    public function blueprint(WorkflowVersion $version): WorkflowBlueprint
    {
        $definition = $this->export($version);

        return WorkflowBlueprint::fromArray($definition['slug'], $definition);
    }
}
