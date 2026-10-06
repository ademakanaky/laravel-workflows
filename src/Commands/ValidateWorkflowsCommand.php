<?php

namespace Ademakanaky\LaravelWorkflows\Commands;

use Ademakanaky\LaravelWorkflows\Contracts\DefinitionValidator;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Illuminate\Console\Command;

class ValidateWorkflowsCommand extends Command
{
    protected $signature = 'workflow:validate';

    protected $description = 'Validate configured workflow definitions without writing to the database';

    public function handle(DefinitionValidator $validator): int
    {
        $valid = true;
        foreach (config('workflows.definitions', []) as $slug => $definition) {
            try {
                $blueprint = $definition instanceof WorkflowBlueprint ? $definition : WorkflowBlueprint::fromArray($slug, $definition);
                $validator->validate($blueprint);
                $this->components->info("Workflow [{$slug}] is valid.");
            } catch (WorkflowException $exception) {
                $valid = false;
                $this->components->error("Workflow [{$slug}] is invalid: {$exception->getMessage()}");
            }
        }

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}
