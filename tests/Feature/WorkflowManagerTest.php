<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Feature;

use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowInstanceStatus;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Events\WorkflowCancelled;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionPublished;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskAssigned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTransitioning;
use Ademakanaky\LaravelWorkflows\Exceptions\DefinitionOwnershipException;
use Ademakanaky\LaravelWorkflows\Exceptions\IdempotencyConflictException;
use Ademakanaky\LaravelWorkflows\Exceptions\ImmutableWorkflowRecordException;
use Ademakanaky\LaravelWorkflows\Exceptions\InvalidTransitionException;
use Ademakanaky\LaravelWorkflows\Exceptions\TransitionGuardRejectedException;
use Ademakanaky\LaravelWorkflows\Exceptions\TransitionNotAuthorizedException;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowDefinition;
use Ademakanaky\LaravelWorkflows\Support\WorkflowExtensionRegistry;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\ActorAssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\AmountGuard;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\Document;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\User;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\UuidActor;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\UuidDocument;
use Ademakanaky\LaravelWorkflows\Tests\TestCase;
use Ademakanaky\LaravelWorkflows\WorkflowDefinitionExporter;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class WorkflowManagerTest extends TestCase
{
    private WorkflowManager $workflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflows = app(WorkflowManager::class);
    }

    public function test_it_synchronizes_an_immutable_definition_version(): void
    {
        $first = $this->workflows->define($this->approvalBlueprint());
        $same = $this->workflows->define($this->approvalBlueprint());
        $changed = $this->workflows->define(
            $this->approvalBlueprint()
                ->state('escalated', final: true)
                ->transition('escalate', 'review', 'escalated')
        );

        $this->assertSame(1, $first->version);
        $this->assertTrue($first->is($same));
        $this->assertSame(2, $changed->version);
        $this->assertCount(2, WorkflowDefinition::firstOrFail()->versions);
    }

    public function test_it_starts_and_completes_a_workflow_with_history(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);
        $actor = User::create(['name' => 'Ada']);

        $instance = $this->workflows->start($document, 'document-approval', $actor, ['source' => 'api']);

        $this->assertSame('draft', $instance->currentState->key);
        $this->assertSame(WorkflowInstanceStatus::Running, $instance->status);
        $this->assertSame(['source' => 'api'], $instance->context);
        $this->assertCount(1, $instance->logs);
        $this->assertSame(WorkflowTaskStatus::Open, $instance->tasks->first()->status);

        $instance = $this->workflows->transition($instance, 'submit', $actor, ['comment' => 'Ready']);
        $this->assertSame('review', $instance->currentState->key);

        $instance = $this->workflows->transition($instance, 'approve', $actor);
        $this->assertSame('approved', $instance->currentState->key);
        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->status);
        $this->assertNotNull($instance->completed_at);
        $this->assertCount(3, $instance->logs);
        $this->assertCount(0, $this->workflows->availableActions($instance));
    }

    public function test_it_returns_available_actions_for_the_current_state(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');
        $instance = $this->workflows->transition($instance, 'submit');

        $this->assertSame(['approve', 'reject'], $this->workflows->availableActions($instance)->pluck('action')->all());
    }

    public function test_it_rejects_an_action_not_available_from_the_current_state(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');

        $this->expectException(InvalidTransitionException::class);
        $this->workflows->transition($instance, 'approve');
    }

    public function test_a_guard_can_reject_a_transition_without_changing_state(): void
    {
        $blueprint = $this->approvalBlueprint(guards: [AmountGuard::class]);
        $this->workflows->define($blueprint);
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');
        $instance = $this->workflows->transition($instance, 'submit');

        try {
            $this->workflows->transition($instance, 'approve', data: ['amount' => 5000]);
            $this->fail('The guard should reject the transition.');
        } catch (TransitionGuardRejectedException $exception) {
            $this->assertSame('The amount exceeds the approval limit.', $exception->getMessage());
        }

        $this->assertSame('review', $instance->fresh()->currentState->key);
        $this->assertCount(2, $instance->logs()->get());
    }

    public function test_start_and_transition_are_idempotent_when_keys_are_supplied(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);

        $first = $this->workflows->start($document, 'document-approval', idempotencyKey: 'start-1');
        $same = $this->workflows->start($document, 'document-approval', idempotencyKey: 'start-1');
        $this->assertTrue($first->is($same));

        $moved = $this->workflows->transition($first, 'submit', idempotencyKey: 'transition-1');
        $retried = $this->workflows->transition($first, 'submit', idempotencyKey: 'transition-1');
        $this->assertSame($moved->current_state_id, $retried->current_state_id);
        $this->assertCount(2, $retried->logs);
    }

    public function test_reusing_an_idempotency_key_with_different_input_is_rejected(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);
        $instance = $this->workflows->start($document, 'document-approval', context: ['amount' => 100], idempotencyKey: 'start-1');

        try {
            $this->workflows->start($document, 'document-approval', context: ['amount' => 200], idempotencyKey: 'start-1');
            $this->fail('Conflicting start input should be rejected.');
        } catch (IdempotencyConflictException) {
            $this->assertCount(1, $document->workflowInstances);
        }

        $instance = $this->workflows->transition($instance, 'submit', idempotencyKey: 'action-1');

        $this->expectException(IdempotencyConflictException::class);
        $this->workflows->transition($instance, 'approve', idempotencyKey: 'action-1');
    }

    public function test_it_prevents_two_active_instances_for_the_same_subject_by_default(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);
        $this->workflows->start($document, 'document-approval');

        $this->expectException(WorkflowException::class);
        $this->workflows->start($document, 'document-approval');
    }

    public function test_the_default_authorizer_restricts_an_assigned_task_to_its_assignee(): void
    {
        config()->set('workflows.assignment_strategy', ActorAssignmentStrategy::class);
        app()->forgetInstance(AssignmentStrategy::class);
        app()->forgetInstance(WorkflowManager::class);
        $this->workflows = app(WorkflowManager::class);

        $this->workflows->define($this->approvalBlueprint());
        $assignee = User::create(['name' => 'Ada']);
        $intruder = User::create(['name' => 'Grace']);
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval', $assignee);

        $this->assertCount(1, $this->workflows->availableActions($instance, $assignee));
        $this->assertCount(0, $this->workflows->availableActions($instance, $intruder));

        try {
            $this->workflows->transition($instance, 'submit', $intruder);
            $this->fail('A non-assignee should not transition an assigned task.');
        } catch (TransitionNotAuthorizedException) {
            $instance = $this->workflows->transition($instance, 'submit', $assignee);
            $this->assertSame('review', $instance->currentState->key);
        }
    }

    public function test_a_task_can_be_reassigned_with_an_audited_idempotent_operation(): void
    {
        Event::fake([WorkflowTaskAssigned::class]);
        $this->workflows->define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);
        $administrator = User::create(['name' => 'Admin']);
        $assignee = User::create(['name' => 'Ada']);
        $instance = $this->workflows->start($document, 'document-approval');

        $assigned = $this->workflows->assign(
            $instance,
            $assignee,
            $administrator,
            ['reason' => 'Workload balancing'],
            'assign-1',
        );
        $retried = $this->workflows->assign(
            $instance,
            $assignee,
            $administrator,
            ['reason' => 'Workload balancing'],
            'assign-1',
        );

        $this->assertTrue($assignee->is($assigned->tasks->first()->assignee));
        $this->assertCount(2, $retried->logs);
        $this->assertSame('Workload balancing', $retried->logs()->where('action', 'assign')->first()->data['reason']);
        Event::assertDispatched(WorkflowTaskAssigned::class);
    }

    public function test_an_assignment_retry_remains_idempotent_after_the_workflow_completes(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $assignee = User::create(['name' => 'Ada']);
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');
        $instance = $this->workflows->assign($instance, $assignee, idempotencyKey: 'assign-1');
        $instance = $this->workflows->transition($instance, 'submit', $assignee);
        $instance = $this->workflows->transition($instance, 'approve');

        $retried = $this->workflows->assign($instance, $assignee, idempotencyKey: 'assign-1');

        $this->assertSame(WorkflowInstanceStatus::Completed, $retried->status);
        $this->assertCount(4, $retried->logs);
    }

    public function test_an_existing_instance_remains_pinned_to_its_definition_version(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');
        $instance = $this->workflows->transition($instance, 'submit');

        $this->workflows->define(
            $this->approvalBlueprint()
                ->state('escalated', final: true)
                ->transition('escalate', 'review', 'escalated')
        );

        $this->assertSame(1, $instance->version->version);
        $this->assertSame(['approve', 'reject'], $this->workflows->availableActions($instance)->pluck('action')->all());
    }

    public function test_a_pre_transition_listener_can_abort_and_roll_back_the_operation(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');
        Event::listen(WorkflowTransitioning::class, fn () => throw new RuntimeException('External policy rejected.'));

        try {
            $this->workflows->transition($instance, 'submit');
            $this->fail('The listener should abort the transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('External policy rejected.', $exception->getMessage());
        }

        $instance->refresh();
        $this->assertSame('draft', $instance->currentState->key);
        $this->assertCount(1, $instance->logs);
        $this->assertSame(WorkflowTaskStatus::Open, $instance->tasks->first()->status);
    }

    public function test_a_single_final_initial_state_completes_immediately_without_a_task(): void
    {
        $this->workflows->define(
            WorkflowBlueprint::make('instant')
                ->state('complete', initial: true, final: true)
        );

        $instance = $this->workflows->start(Document::create(['title' => 'Instant']), 'instant');

        $this->assertSame(WorkflowInstanceStatus::Completed, $instance->status);
        $this->assertCount(0, $instance->tasks);
        $this->assertCount(1, $instance->logs);
    }

    public function test_the_sync_command_publishes_configured_array_definitions(): void
    {
        config()->set('workflows.definitions', [
            'simple' => [
                'states' => [
                    'new' => ['initial' => true],
                    'done' => ['final' => true],
                ],
                'transitions' => [
                    ['action' => 'finish', 'from' => 'new', 'to' => 'done'],
                ],
            ],
        ]);

        $this->artisan('workflow:sync')
            ->expectsOutputToContain('Synchronized [simple] version 1.')
            ->assertSuccessful();

        $this->assertDatabaseHas('workflow_definitions', ['slug' => 'simple']);
    }

    public function test_uuid_subjects_actors_and_assignees_are_supported(): void
    {
        config()->set('workflows.assignment_strategy', ActorAssignmentStrategy::class);
        app()->forgetInstance(AssignmentStrategy::class);
        app()->forgetInstance(WorkflowManager::class);
        $this->workflows = app(WorkflowManager::class);
        $this->workflows->define($this->approvalBlueprint());

        $document = UuidDocument::create(['title' => 'UUID document']);
        $actor = UuidActor::create(['name' => 'UUID actor']);
        $instance = $this->workflows->start($document, 'document-approval', $actor);

        $this->assertSame($document->getKey(), $instance->subject_id);
        $this->assertSame($actor->getKey(), $instance->started_by_id);
        $this->assertSame($actor->getKey(), $instance->tasks->first()->assignee_id);
        $this->assertCount(1, $actor->assignedWorkflowTasks);
        $this->assertCount(1, $actor->workflowActions);
    }

    public function test_published_definition_records_and_history_are_immutable(): void
    {
        $version = $this->workflows->define($this->approvalBlueprint());
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');

        try {
            $version->update(['version' => 99]);
            $this->fail('Published versions must be immutable.');
        } catch (ImmutableWorkflowRecordException) {
            $this->assertSame(1, $version->fresh()->version);
        }

        $this->expectException(ImmutableWorkflowRecordException::class);
        $instance->logs->first()->delete();
    }

    public function test_a_definition_cannot_be_deleted_through_eloquent(): void
    {
        $this->workflows->define($this->approvalBlueprint());

        $this->expectException(ImmutableWorkflowRecordException::class);
        WorkflowDefinition::firstOrFail()->delete();
    }

    public function test_a_running_workflow_can_be_cancelled_idempotently(): void
    {
        Event::fake([WorkflowCancelled::class]);
        $this->workflows->define($this->approvalBlueprint());
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');
        $actor = User::create(['name' => 'Administrator']);

        $cancelled = $this->workflows->cancel(
            $instance,
            $actor,
            ['reason' => 'Request withdrawn'],
            'cancel-1',
        );
        $retried = $this->workflows->cancel($instance, $actor, ['reason' => 'Request withdrawn'], 'cancel-1');

        $this->assertSame(WorkflowInstanceStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(WorkflowTaskStatus::Cancelled, $cancelled->tasks->first()->status);
        $this->assertCount(2, $retried->logs);
        $this->assertSame('cancel', $retried->logs()->where('action', 'cancel')->first()->action);
        Event::assertDispatched(WorkflowCancelled::class);
    }

    public function test_database_managed_definitions_are_protected_from_code_sync(): void
    {
        Event::fake([WorkflowDefinitionPublished::class]);
        $publisher = app(DefinitionPublisher::class);
        $version = $publisher->publish($this->approvalBlueprint(), WorkflowDefinitionSource::Database);

        $this->assertSame(WorkflowDefinitionSource::Database, $version->definition->managed_by);
        Event::assertDispatched(WorkflowDefinitionPublished::class);

        $this->expectException(DefinitionOwnershipException::class);
        $this->workflows->define($this->approvalBlueprint());
    }

    public function test_published_definitions_export_to_the_same_blueprint_schema(): void
    {
        $version = $this->workflows->define($this->approvalBlueprint());
        $exported = app(WorkflowDefinitionExporter::class)->export($version);
        $roundTrip = WorkflowBlueprint::fromArray($exported['slug'], $exported);

        $this->assertSame($this->approvalBlueprint()->checksum(), $roundTrip->checksum());
        $this->assertSame('document-approval', $exported['slug']);
        $this->assertArrayHasKey('review', $exported['states']);
    }

    public function test_an_older_version_preserves_its_original_name_and_description(): void
    {
        $first = $this->workflows->define(
            $this->approvalBlueprint()->name('Original approval')->description('Original description')
        );
        $this->workflows->define(
            $this->approvalBlueprint()->name('Renamed approval')->description('Updated description')
        );

        $exported = app(WorkflowDefinitionExporter::class)->export($first->fresh());

        $this->assertSame('Original approval', $exported['name']);
        $this->assertSame('Original description', $exported['description']);
        $this->assertSame('Renamed approval', WorkflowDefinition::firstOrFail()->name);
    }

    public function test_registered_guard_aliases_can_be_used_by_admin_authored_definitions(): void
    {
        app(WorkflowExtensionRegistry::class)->registerGuard('amount-limit', AmountGuard::class);
        $this->workflows->define($this->approvalBlueprint(guards: ['amount-limit']));
        $instance = $this->workflows->start(Document::create(['title' => 'Budget']), 'document-approval');
        $instance = $this->workflows->transition($instance, 'submit');

        $this->expectException(TransitionGuardRejectedException::class);
        $this->workflows->transition($instance, 'approve', data: ['amount' => 5000]);
    }

    public function test_a_registered_assignment_strategy_can_be_selected_per_state(): void
    {
        app(WorkflowExtensionRegistry::class)->registerAssignmentStrategy('initiator', ActorAssignmentStrategy::class);
        $blueprint = WorkflowBlueprint::make('assigned-approval')
            ->state('draft', initial: true, assignmentStrategy: 'initiator')
            ->state('approved', final: true)
            ->transition('approve', 'draft', 'approved');
        $this->workflows->define($blueprint);
        $actor = User::create(['name' => 'Ada']);

        $instance = $this->workflows->start(
            Document::create(['title' => 'Budget']),
            'assigned-approval',
            $actor,
        );

        $this->assertTrue($actor->is($instance->tasks->first()->assignee));
        $this->assertSame('initiator', $instance->currentState->assignment_strategy);
    }

    public function test_unregistered_extensions_are_rejected_before_a_definition_is_published(): void
    {
        $this->expectException(WorkflowException::class);

        $this->workflows->define($this->approvalBlueprint(guards: ['missing-guard']));
    }

    public function test_the_validate_command_checks_extensions_without_publishing(): void
    {
        config()->set('workflows.definitions', [
            'approval' => [
                'states' => [
                    'draft' => ['initial' => true],
                    'approved' => ['final' => true],
                ],
                'transitions' => [
                    ['action' => 'approve', 'from' => 'draft', 'to' => 'approved', 'guards' => ['missing-guard']],
                ],
            ],
        ]);

        $this->artisan('workflow:validate')
            ->expectsOutputToContain('Workflow [approval] is invalid')
            ->assertFailed();

        $this->assertDatabaseMissing('workflow_definitions', ['slug' => 'approval']);
    }

    public function test_subjects_must_be_persisted_before_a_workflow_starts(): void
    {
        $this->workflows->define($this->approvalBlueprint());

        $this->expectException(WorkflowException::class);
        $this->workflows->start(new Document(['title' => 'Unsaved']), 'document-approval');
    }

    public function test_idempotency_keys_must_fit_the_published_database_schema(): void
    {
        $this->workflows->define($this->approvalBlueprint());
        $document = Document::create(['title' => 'Budget']);

        foreach (['', str_repeat('x', 192)] as $invalidKey) {
            try {
                $this->workflows->start($document, 'document-approval', idempotencyKey: $invalidKey);
                $this->fail('Invalid idempotency keys must be rejected before persistence.');
            } catch (WorkflowException $exception) {
                $this->assertStringContainsString('between 1 and 191 characters', $exception->getMessage());
            }
        }
    }

    private function approvalBlueprint(array $guards = []): WorkflowBlueprint
    {
        return WorkflowBlueprint::make('document-approval')
            ->name('Document approval')
            ->state('draft', initial: true)
            ->state('review')
            ->state('approved', final: true)
            ->state('rejected', final: true)
            ->transition('submit', 'draft', 'review')
            ->transition('approve', 'review', 'approved', guards: $guards)
            ->transition('reject', 'review', 'rejected');
    }
}
