<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Feature;

use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Contracts\TransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowParticipantResolver;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowDraft;
use Ademakanaky\LaravelWorkflows\Events\WorkflowOutcomeReached;
use Ademakanaky\LaravelWorkflows\Exceptions\TransitionGuardRejectedException;
use Ademakanaky\LaravelWorkflows\Guards\ActorIsNotWorkflowInitiator;
use Ademakanaky\LaravelWorkflows\Integrations\Spatie\SpatieParticipantResolver;
use Ademakanaky\LaravelWorkflows\Integrations\Spatie\SpatieTransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Support\WorkflowExtensionRegistry;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\Document;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\FailingActionHandler;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\PermissionedUser;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\RecordingActionHandler;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\Role;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\User;
use Ademakanaky\LaravelWorkflows\Tests\TestCase;
use Ademakanaky\LaravelWorkflows\WorkflowAdministration;
use Ademakanaky\LaravelWorkflows\WorkflowInbox;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class WorkflowExtensionsTest extends TestCase
{
    public function test_spatie_authorizer_preserves_task_rules_and_checks_transition_permissions(): void
    {
        config()->set('workflows.transition_authorizer', SpatieTransitionAuthorizer::class);
        $this->forgetWorkflowServices();
        $manager = app(WorkflowManager::class);
        $actor = PermissionedUser::create(['name' => 'Ada'])->grant('requests.approve');
        $intruder = PermissionedUser::create(['name' => 'Grace'])->grant('requests.approve');
        $manager->define($this->approvalBlueprint()->candidate('draft', $actor));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval');

        $this->assertCount(1, $manager->availableActions($instance, $actor));
        $this->assertCount(0, $manager->availableActions($instance, $intruder));

        $actorWithoutPermission = PermissionedUser::create(['name' => 'No permission']);
        $instance = $manager->assign($instance, $actorWithoutPermission);
        $this->assertCount(0, $manager->availableActions($instance, $actorWithoutPermission));
    }

    public function test_spatie_authorizer_supports_all_and_any_permission_lists(): void
    {
        config()->set('workflows.transition_authorizer', SpatieTransitionAuthorizer::class);
        $this->forgetWorkflowServices();
        $manager = app(WorkflowManager::class);
        $actor = PermissionedUser::create(['name' => 'Ada'])->grant('requests.review');
        $manager->define($this->approvalBlueprint(permission: ['requests.review', 'requests.approve']));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval');

        $this->assertCount(0, $manager->availableActions($instance, $actor));

        config()->set('workflows.spatie.permission_mode', 'any');

        $this->assertCount(1, $manager->availableActions($instance, $actor));
    }

    public function test_spatie_participant_resolver_exposes_role_candidates_to_members(): void
    {
        config()->set('workflows.participant_resolver', SpatieParticipantResolver::class);
        $this->forgetWorkflowServices();
        $manager = app(WorkflowManager::class);
        $role = Role::create(['name' => 'Approver']);
        $actor = User::create(['name' => 'Ada']);
        $actor->setRelation('roles', new Collection([$role]));
        $manager->define($this->approvalBlueprint()->candidate('draft', $role));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval');

        $this->assertInstanceOf(SpatieParticipantResolver::class, app(WorkflowParticipantResolver::class));
        $this->assertCount(2, collect(app(WorkflowParticipantResolver::class)->principals($actor)));
        $this->assertTrue($role->is($instance->tasks->first()->assignee));
        $this->assertSame(1, $manager->pendingCount($actor));
        $this->assertCount(1, $manager->availableActions($instance, $actor));
    }

    public function test_initiator_guard_enforces_maker_checker_separation(): void
    {
        config()->set('workflows.guards', ['maker-checker' => ActorIsNotWorkflowInitiator::class]);
        $this->forgetWorkflowServices();
        $manager = app(WorkflowManager::class);
        $maker = User::create(['name' => 'Maker']);
        $checker = User::create(['name' => 'Checker']);
        $manager->define($this->approvalBlueprint(guards: ['maker-checker']));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval', $maker);

        try {
            $manager->transition($instance, 'approve', $maker);
            $this->fail('The initiator must not approve their own workflow.');
        } catch (TransitionGuardRejectedException) {
            $moved = $manager->transition($instance, 'approve', $checker);
            $this->assertSame('approved', $moved->currentState->key);
        }
    }

    public function test_transactional_and_after_commit_handlers_run_once_at_their_documented_boundaries(): void
    {
        RecordingActionHandler::reset();
        config()->set('workflows.action_handlers', ['record' => RecordingActionHandler::class]);
        $this->forgetWorkflowServices();
        $manager = app(WorkflowManager::class);
        $manager->define($this->approvalBlueprint(handlers: ['record'], afterCommitHandlers: ['record']));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval');

        $manager->transition($instance, 'approve', idempotencyKey: 'approve-1');
        $manager->transition($instance, 'approve', idempotencyKey: 'approve-1');

        $this->assertSame(['draft', 'approved'], RecordingActionHandler::$states);
    }

    public function test_a_failing_transactional_handler_rolls_back_the_transition(): void
    {
        config()->set('workflows.action_handlers', ['fail' => FailingActionHandler::class]);
        $this->forgetWorkflowServices();
        $manager = app(WorkflowManager::class);
        $manager->define($this->approvalBlueprint(handlers: ['fail']));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval');

        try {
            $manager->transition($instance, 'approve');
            $this->fail('The handler must abort the transition.');
        } catch (RuntimeException) {
            $this->assertSame('draft', $instance->fresh()->currentState->key);
            $this->assertSame(1, $instance->logs()->count());
        }
    }

    public function test_after_commit_handlers_are_suppressed_by_an_outer_rollback(): void
    {
        RecordingActionHandler::reset();
        config()->set('workflows.action_handlers', ['record' => RecordingActionHandler::class]);
        $this->forgetWorkflowServices();
        $manager = app(WorkflowManager::class);
        $manager->define($this->approvalBlueprint(handlers: ['record'], afterCommitHandlers: ['record']));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval');

        DB::beginTransaction();
        try {
            $manager->transition($instance, 'approve');
            $this->assertSame(['draft'], RecordingActionHandler::$states);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(['draft'], RecordingActionHandler::$states);
        $this->assertSame('draft', $instance->fresh()->currentState->key);
    }

    public function test_final_state_outcomes_dispatch_a_generic_post_commit_event(): void
    {
        Event::fake([WorkflowOutcomeReached::class]);
        $manager = app(WorkflowManager::class);
        $manager->define($this->approvalBlueprint()->outcome('approved', 'approved'));
        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval');

        $manager->transition($instance, 'approve');

        Event::assertDispatched(WorkflowOutcomeReached::class, fn (WorkflowOutcomeReached $event): bool => $event->outcome === 'approved');
    }

    public function test_mutable_drafts_are_application_persistable_validated_and_publishable(): void
    {
        config()->set('workflows.action_handlers', ['record' => RecordingActionHandler::class]);
        $this->forgetWorkflowServices();
        $admin = app(WorkflowAdministration::class);
        $draft = WorkflowDraft::make('leave-approval')
            ->name('Leave approval')
            ->putState('pending', ['initial' => true])
            ->putState('approved', ['final' => true])
            ->outcome('approved', 'approved')
            ->putTransition('approve', 'pending', 'approved')
            ->handlers('pending', 'approve', afterCommitHandlers: ['record']);

        $serialized = $draft->toArray();
        $restored = WorkflowDraft::fromArray($serialized);
        $admin->validateDraft($restored);
        $version = $admin->publishDraft($restored);

        $this->assertSame('leave-approval', $version->definition->slug);
        $this->assertSame('approved', $admin->draft('leave-approval')->toArray()['states']['approved']['metadata']['outcome']);
    }

    private function forgetWorkflowServices(): void
    {
        foreach ([
            AssignmentStrategy::class,
            TransitionAuthorizer::class,
            WorkflowParticipantResolver::class,
            WorkflowExtensionRegistry::class,
            WorkflowManager::class,
            WorkflowInbox::class,
            WorkflowAdministration::class,
        ] as $service) {
            app()->forgetInstance($service);
        }
    }

    /**
     * @param  list<string>  $guards
     * @param  list<string>  $handlers
     * @param  list<string>  $afterCommitHandlers
     * @param  string|list<string>  $permission
     */
    private function approvalBlueprint(
        array $guards = [],
        array $handlers = [],
        array $afterCommitHandlers = [],
        string|array $permission = 'requests.approve',
    ): WorkflowBlueprint {
        return WorkflowBlueprint::make('document-approval')
            ->state('draft', initial: true)
            ->state('approved', final: true)
            ->transition(
                'approve',
                'draft',
                'approved',
                guards: $guards,
                metadata: ['permission' => $permission],
                handlers: $handlers,
                afterCommitHandlers: $afterCommitHandlers,
            );
    }
}
