<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionValidator;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionPublished;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionPublishing;
use Ademakanaky\LaravelWorkflows\Exceptions\DefinitionOwnershipException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Support\Facades\DB;

class DefinitionSynchronizer implements DefinitionPublisher
{
    public function __construct(private readonly DefinitionValidator $validator) {}

    public function publish(
        WorkflowBlueprint $blueprint,
        WorkflowDefinitionSource $source = WorkflowDefinitionSource::Code,
    ): WorkflowVersion {
        $this->validator->validate($blueprint);
        $payload = $blueprint->toArray();

        return DB::transaction(function () use ($blueprint, $payload, $source): WorkflowVersion {
            $definitionClass = WorkflowModelRegistry::definition();
            $definition = $definitionClass::query()->firstOrCreate(
                ['slug' => $blueprint->slug],
                [
                    'name' => $payload['name'],
                    'description' => $payload['description'],
                    'managed_by' => $source,
                ],
            );
            $definition = $definitionClass::query()->whereKey($definition->getKey())->lockForUpdate()->firstOrFail();

            if ($definition->managed_by !== $source) {
                throw new DefinitionOwnershipException(
                    "Workflow [{$blueprint->slug}] is managed by [{$definition->managed_by->value}] and cannot be published as [{$source->value}]."
                );
            }

            $existing = $definition->versions()->where('checksum', $blueprint->checksum())->first();
            if ($existing) {
                return $existing;
            }

            event(new WorkflowDefinitionPublishing($blueprint, $source));
            $definition->update(['name' => $payload['name'], 'description' => $payload['description']]);
            $version = $definition->versions()->create([
                'version' => ((int) $definition->versions()->max('version')) + 1,
                'name' => $payload['name'],
                'description' => $payload['description'],
                'checksum' => $blueprint->checksum(),
                'metadata' => $payload['metadata'],
                'published_at' => now(),
            ]);

            $states = [];
            foreach ($payload['states'] as $state) {
                $states[$state['key']] = $version->states()->create([
                    'key' => $state['key'],
                    'name' => $state['name'],
                    'description' => $state['description'],
                    'is_initial' => $state['initial'],
                    'is_final' => $state['final'],
                    'assignment_strategy' => $state['assignment_strategy'],
                    'metadata' => $state['metadata'],
                ]);
                foreach ($state['candidates'] as $candidate) {
                    $states[$state['key']]->candidates()->create([
                        'candidate_type' => $candidate['type'],
                        'candidate_id' => $candidate['id'],
                    ]);
                }
            }

            foreach ($payload['transitions'] as $transition) {
                $version->transitions()->create([
                    'from_state_id' => $states[$transition['from']]->getKey(),
                    'to_state_id' => $states[$transition['to']]->getKey(),
                    'action' => $transition['action'],
                    'name' => $transition['name'],
                    'guards' => $transition['guards'],
                    'metadata' => $transition['metadata'],
                ]);
            }

            $definition->update(['active_version_id' => $version->getKey(), 'is_active' => true]);
            $result = $version->load('definition', 'states.candidates', 'transitions');
            DB::afterCommit(fn () => event(new WorkflowDefinitionPublished($result, $source)));

            return $result;
        });
    }

    public function sync(WorkflowBlueprint $blueprint): WorkflowVersion
    {
        return $this->publish($blueprint);
    }
}
