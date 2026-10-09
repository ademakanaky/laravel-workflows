<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Feature;

use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionValidator;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\DefinitionSynchronizer;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;
use Ademakanaky\LaravelWorkflows\Events\WorkflowCancelled;
use Ademakanaky\LaravelWorkflows\Events\WorkflowCompleted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionPublished;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionPublishing;
use Ademakanaky\LaravelWorkflows\Events\WorkflowStarted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowStarting;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskAssigned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTransitioned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTransitioning;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Facades\Workflow;
use Ademakanaky\LaravelWorkflows\Support\WorkflowExtensionRegistry;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\ActorAssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\AmountGuard;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\CustomWorkflowDefinition;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\CustomWorkflowInstance;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\CustomWorkflowState;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\CustomWorkflowTask;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\CustomWorkflowTransition;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\CustomWorkflowTransitionLog;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\CustomWorkflowVersion;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\Document;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\RecordingActionHandler;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\User;
use Ademakanaky\LaravelWorkflows\Tests\TestCase;
use Ademakanaky\LaravelWorkflows\WorkflowDefinitionExporter;
use Ademakanaky\LaravelWorkflows\WorkflowDefinitionValidator;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Ademakanaky\LaravelWorkflows\WorkflowServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PublicApiContractTest extends TestCase
{
    public function test_public_services_and_facade_resolve_from_the_container(): void
    {
        $this->assertSame(app(DefinitionSynchronizer::class), app(DefinitionPublisher::class));
        $this->assertSame(app(WorkflowDefinitionValidator::class), app(DefinitionValidator::class));
        $this->assertSame(app(WorkflowManager::class), Workflow::getFacadeRoot());

        $version = Workflow::define($this->approvalBlueprint());

        $this->assertSame(1, $version->version);
        $this->assertSame('document-approval', $version->definition->slug);
    }

    public function test_model_helpers_and_relationship_traits_use_the_public_runtime_api(): void
    {
        Workflow::define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);
        $actor = User::create(['name' => 'Ada']);
        $instance = Workflow::start($document, 'document-approval', $actor);

        $this->assertSame(['submit'], $instance->availableTransitions($actor)->pluck('action')->all());
        $instance = $instance->transition('submit', $actor);
        $instance = $instance->cancel($actor, ['reason' => 'Withdrawn']);

        $this->assertTrue($instance->is($document->workflowInstances()->first()));
        $this->assertCount(0, $document->activeWorkflowInstances);
        $this->assertTrue($instance->is($actor->startedWorkflowInstances()->first()));
        $this->assertCount(3, $actor->workflowActions);
    }

    public function test_definition_events_observe_outer_transaction_commit_and_rollback(): void
    {
        Event::fake([WorkflowDefinitionPublishing::class, WorkflowDefinitionPublished::class]);
        $publisher = app(DefinitionPublisher::class);

        DB::beginTransaction();
        try {
            $publisher->publish($this->approvalBlueprint(), WorkflowDefinitionSource::Database);
            Event::assertDispatched(WorkflowDefinitionPublishing::class);
            Event::assertNotDispatched(WorkflowDefinitionPublished::class);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        Event::assertDispatched(WorkflowDefinitionPublished::class);

        Event::fake([WorkflowDefinitionPublishing::class, WorkflowDefinitionPublished::class]);
        DB::beginTransaction();
        try {
            $publisher->publish($this->approvalBlueprint('rolled-back'), WorkflowDefinitionSource::Database);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        Event::assertDispatched(WorkflowDefinitionPublishing::class);
        Event::assertNotDispatched(WorkflowDefinitionPublished::class);
        $this->assertDatabaseMissing('workflow_definitions', ['slug' => 'rolled-back']);
    }

    public function test_runtime_lifecycle_events_are_emitted_with_their_public_payloads(): void
    {
        $manager = app(WorkflowManager::class);
        $manager->define($this->approvalBlueprint());
        Event::fake([
            WorkflowStarting::class,
            WorkflowStarted::class,
            WorkflowTransitioning::class,
            WorkflowTransitioned::class,
            WorkflowCompleted::class,
            WorkflowTaskAssigned::class,
            WorkflowCancelled::class,
        ]);

        $actor = User::create(['name' => 'Ada']);
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval', $actor);
        $manager->assign($instance, $actor, $actor);
        $instance = $manager->transition($instance, 'submit', $actor);
        $manager->transition($instance, 'approve', $actor);

        $cancelled = $manager->start(Document::create(['title' => 'Travel']), 'document-approval', $actor);
        $manager->cancel($cancelled, $actor);

        Event::assertDispatched(WorkflowStarting::class, fn (WorkflowStarting $event): bool => $event->actor?->is($actor) === true);
        Event::assertDispatched(WorkflowStarted::class, fn (WorkflowStarted $event): bool => $event->instance->subject instanceof Document);
        Event::assertDispatched(WorkflowTaskAssigned::class, fn (WorkflowTaskAssigned $event): bool => $event->task->assignee?->is($actor) === true);
        Event::assertDispatched(WorkflowTransitioning::class, fn (WorkflowTransitioning $event): bool => $event->transition->action === 'approve');
        Event::assertDispatched(WorkflowTransitioned::class, fn (WorkflowTransitioned $event): bool => $event->log->action === 'approve');
        Event::assertDispatched(WorkflowCompleted::class);
        Event::assertDispatched(WorkflowCancelled::class, fn (WorkflowCancelled $event): bool => $event->log->action === 'cancel');
    }

    public function test_runtime_post_commit_events_are_suppressed_by_an_outer_rollback(): void
    {
        app(WorkflowManager::class)->define($this->approvalBlueprint());
        Event::fake([WorkflowStarting::class, WorkflowStarted::class]);

        DB::beginTransaction();
        try {
            app(WorkflowManager::class)->start(Document::create(['title' => 'Budget']), 'document-approval');
            Event::assertDispatched(WorkflowStarting::class);
            Event::assertNotDispatched(WorkflowStarted::class);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        Event::assertNotDispatched(WorkflowStarted::class);
        $this->assertDatabaseCount('workflow_instances', 0);
    }

    public function test_configured_custom_models_flow_through_manager_and_relationships(): void
    {
        config()->set('workflows.models', [
            'definition' => CustomWorkflowDefinition::class,
            'version' => CustomWorkflowVersion::class,
            'state' => CustomWorkflowState::class,
            'transition' => CustomWorkflowTransition::class,
            'instance' => CustomWorkflowInstance::class,
            'task' => CustomWorkflowTask::class,
            'log' => CustomWorkflowTransitionLog::class,
        ]);
        $manager = app(WorkflowManager::class);
        $version = $manager->define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);

        $instance = $manager->start($document, 'document-approval');

        $this->assertInstanceOf(CustomWorkflowVersion::class, $version);
        $this->assertInstanceOf(CustomWorkflowDefinition::class, $version->definition);
        $this->assertInstanceOf(CustomWorkflowState::class, $version->states->first());
        $this->assertInstanceOf(CustomWorkflowTransition::class, $version->transitions->first());
        $this->assertInstanceOf(CustomWorkflowInstance::class, $instance);
        $this->assertInstanceOf(CustomWorkflowTask::class, $instance->tasks->first());
        $this->assertInstanceOf(CustomWorkflowTransitionLog::class, $instance->logs()->first());
        $this->assertInstanceOf(CustomWorkflowInstance::class, $document->workflowInstances()->first());
    }

    public function test_invalid_custom_model_configuration_fails_early(): void
    {
        config()->set('workflows.models.instance', Document::class);

        $this->expectException(WorkflowException::class);
        WorkflowModelRegistry::instance();
    }

    public function test_extension_registry_exposes_admin_safe_aliases(): void
    {
        $registry = new WorkflowExtensionRegistry(
            ['amount-limit' => AmountGuard::class],
            ['initiator' => ActorAssignmentStrategy::class],
            ['record' => RecordingActionHandler::class],
        );

        $this->assertSame(['amount-limit' => AmountGuard::class], $registry->guards());
        $this->assertSame(['initiator' => ActorAssignmentStrategy::class], $registry->assignmentStrategies());
        $this->assertSame(['record' => RecordingActionHandler::class], $registry->actionHandlers());
        $this->assertSame(AmountGuard::class, $registry->resolveGuard('amount-limit'));
        $this->assertSame(ActorAssignmentStrategy::class, $registry->resolveAssignmentStrategy('initiator'));
        $this->assertSame(RecordingActionHandler::class, $registry->resolveActionHandler('record'));
    }

    public function test_exporter_blueprint_round_trips_the_canonical_definition(): void
    {
        $version = app(WorkflowManager::class)->define($this->approvalBlueprint());
        $exporter = app(WorkflowDefinitionExporter::class);

        $this->assertSame($version->checksum, $exporter->blueprint($version)->checksum());
        $this->assertSame($exporter->export($version), $exporter->blueprint($version)->toArray());
    }

    public function test_configuration_and_migration_publish_tags_are_registered(): void
    {
        $config = ServiceProvider::pathsToPublish(WorkflowServiceProvider::class, 'workflows-config');
        $migrations = ServiceProvider::pathsToPublish(WorkflowServiceProvider::class, 'workflows-migrations');
        $administrationMigrations = ServiceProvider::pathsToPublish(WorkflowServiceProvider::class, 'workflows-administration-migration');
        $actionHandlerMigrations = ServiceProvider::pathsToPublish(WorkflowServiceProvider::class, 'workflows-v1-2-migration');
        $configSource = realpath(__DIR__.'/../../config/workflows.php');
        $migrationSource = realpath(__DIR__.'/../../database/migrations/2026_01_01_000000_create_workflow_tables.php');
        $administrationMigrationSource = realpath(__DIR__.'/../../database/migrations/2026_01_02_000000_add_workflow_administration_support.php');
        $actionHandlerMigrationSource = realpath(__DIR__.'/../../database/migrations/2026_01_03_000000_add_workflow_action_handlers.php');

        $this->assertContains(config_path('workflows.php'), $config);
        $this->assertContains($configSource, array_map('realpath', array_keys($config)));
        $this->assertContains($migrationSource, array_map('realpath', array_keys($migrations)));
        $this->assertContains($administrationMigrationSource, array_map('realpath', array_keys($migrations)));
        $this->assertContains($actionHandlerMigrationSource, array_map('realpath', array_keys($migrations)));
        $this->assertCount(3, $migrations);
        $this->assertContains($administrationMigrationSource, array_map('realpath', array_keys($administrationMigrations)));
        $this->assertCount(1, $administrationMigrations);
        $this->assertContains($actionHandlerMigrationSource, array_map('realpath', array_keys($actionHandlerMigrations)));
        $this->assertCount(1, $actionHandlerMigrations);
    }

    public function test_commands_cover_selection_validation_and_empty_configuration(): void
    {
        config()->set('workflows.definitions', [
            'first' => $this->approvalBlueprint('first'),
            'second' => $this->approvalBlueprint('second'),
        ]);

        $this->artisan('workflow:validate')
            ->expectsOutputToContain('Workflow [first] is valid.')
            ->expectsOutputToContain('Workflow [second] is valid.')
            ->assertSuccessful();
        $this->assertDatabaseCount('workflow_definitions', 0);

        $this->artisan('workflow:sync', ['slug' => 'first'])
            ->expectsOutputToContain('Synchronized [first] version 1.')
            ->assertSuccessful();
        $this->assertDatabaseHas('workflow_definitions', ['slug' => 'first']);
        $this->assertDatabaseMissing('workflow_definitions', ['slug' => 'second']);

        $this->artisan('workflow:sync', ['slug' => 'missing'])
            ->expectsOutputToContain('Workflow [missing] is not configured.')
            ->assertFailed();

        config()->set('workflows.definitions', []);
        $this->artisan('workflow:sync')
            ->expectsOutputToContain('No workflows are configured.')
            ->assertSuccessful();
    }

    private function approvalBlueprint(string $slug = 'document-approval'): WorkflowBlueprint
    {
        return WorkflowBlueprint::make($slug)
            ->name('Document approval')
            ->state('draft', initial: true)
            ->state('review')
            ->state('approved', final: true)
            ->transition('submit', 'draft', 'review')
            ->transition('approve', 'review', 'approved');
    }
}
