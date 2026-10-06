<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Commands\SyncWorkflowsCommand;
use Ademakanaky\LaravelWorkflows\Commands\ValidateWorkflowsCommand;
use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionValidator;
use Ademakanaky\LaravelWorkflows\Contracts\TransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Support\WorkflowExtensionRegistry;
use Illuminate\Support\ServiceProvider;

class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workflows.php', 'workflows');

        $this->app->singleton(AssignmentStrategy::class, fn ($app) => $app->make(config('workflows.assignment_strategy')));
        $this->app->singleton(TransitionAuthorizer::class, fn ($app) => $app->make(config('workflows.transition_authorizer')));
        $this->app->singleton(WorkflowTaskNotifier::class, fn ($app) => $app->make(config('workflows.task_notifier')));
        $this->app->singleton(DefinitionSynchronizer::class);
        $this->app->alias(DefinitionSynchronizer::class, DefinitionPublisher::class);
        $this->app->singleton(WorkflowDefinitionValidator::class);
        $this->app->alias(WorkflowDefinitionValidator::class, DefinitionValidator::class);
        $this->app->singleton(WorkflowDefinitionExporter::class);
        $this->app->singleton(WorkflowExtensionRegistry::class, fn () => new WorkflowExtensionRegistry(
            config('workflows.guards', []),
            config('workflows.assignment_strategies', []),
        ));
        $this->app->singleton(WorkflowManager::class);
        $this->app->singleton(WorkflowInbox::class);
    }

    public function boot(): void
    {
        $migration = __DIR__.'/../database/migrations/2026_01_01_000000_create_workflow_tables.php';

        if (config('workflows.load_migrations', true)) {
            $this->loadMigrationsFrom(dirname($migration));
        }
        $this->publishes([
            __DIR__.'/../config/workflows.php' => config_path('workflows.php'),
        ], 'workflows-config');
        $this->publishes([
            $migration => database_path('migrations/'.date('Y_m_d_His').'_create_workflow_tables.php'),
        ], 'workflows-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncWorkflowsCommand::class, ValidateWorkflowsCommand::class]);
        }
    }
}
