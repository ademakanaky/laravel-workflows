<?php

namespace Ademakanaky\LaravelWorkflows\Commands;

use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Illuminate\Console\Command;

class SyncWorkflowsCommand extends Command
{
    protected $signature = 'workflow:sync {slug? : Synchronize only one configured workflow}';

    protected $description = 'Validate and publish configured workflow definitions';

    public function handle(DefinitionPublisher $publisher): int
    {
        $definitions = config('workflows.definitions', []);
        $slug = $this->argument('slug');

        if ($slug !== null) {
            if (! array_key_exists($slug, $definitions)) {
                $this->components->error("Workflow [{$slug}] is not configured.");

                return self::FAILURE;
            }
            $definitions = [$slug => $definitions[$slug]];
        }

        foreach ($definitions as $key => $definition) {
            $blueprint = $definition instanceof WorkflowBlueprint
                ? $definition
                : WorkflowBlueprint::fromArray($key, $definition);
            $version = $publisher->publish($blueprint);
            $this->components->info("Synchronized [{$key}] version {$version->version}.");
        }

        if ($definitions === []) {
            $this->components->warn('No workflows are configured.');
        }

        return self::SUCCESS;
    }
}
