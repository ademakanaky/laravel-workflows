<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Feature;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionActivated;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionDeactivated;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskClaimed;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskNudged;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskReleased;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\Document;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\RecordingWorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\User;
use Ademakanaky\LaravelWorkflows\Tests\TestCase;
use Ademakanaky\LaravelWorkflows\WorkflowAdministration;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Illuminate\Support\Facades\Event;

class WorkflowAdministrationTest extends TestCase
{
    public function test_step_candidates_receive_tasks_and_can_claim_release_and_be_reassigned(): void
    {
        Event::fake([WorkflowTaskClaimed::class, WorkflowTaskReleased::class]);
        $admin = app(WorkflowAdministration::class);
        $ada = User::create(['name' => 'Ada']);
        $grace = User::create(['name' => 'Grace']);
        $outsider = User::create(['name' => 'Outsider']);
        $administrator = User::create(['name' => 'Administrator']);
        $admin->publish($this->approvalBlueprint()->candidates('draft', [$ada, $grace]));

        $instance = app(WorkflowManager::class)->start(Document::create(['title' => 'Budget']), 'document-approval', $administrator);
        $task = $instance->tasks->first();

        $this->assertNull($task->assignee);
        $this->assertCount(2, $task->candidates);
        $this->assertSame(1, app(WorkflowManager::class)->pendingCount($ada));
        $this->assertSame(1, app(WorkflowManager::class)->pendingCount($grace));
        $this->assertSame(0, app(WorkflowManager::class)->pendingCount($outsider));
        $this->assertCount(1, $instance->availableTransitions($ada));
        $this->assertCount(0, $instance->availableTransitions($outsider));

        $claimed = $admin->claim($task, $ada, ['reason' => 'Taking ownership'], 'claim-1');
        $this->assertTrue($ada->is($claimed->tasks->first()->assignee));
        $this->assertSame(1, $claimed->logs()->where('action', 'claim')->count());
        $this->assertSame(0, app(WorkflowManager::class)->pendingCount($grace));
        Event::assertDispatched(WorkflowTaskClaimed::class);

        $released = $admin->release($task, $ada, idempotencyKey: 'release-1');
        $this->assertNull($released->tasks->first()->assignee);
        $this->assertSame(1, app(WorkflowManager::class)->pendingCount($grace));
        Event::assertDispatched(WorkflowTaskReleased::class);

        $reassigned = $admin->reassign($task, $grace, $administrator, ['reason' => 'Admin assignment'], 'assign-1');
        $this->assertTrue($grace->is($reassigned->tasks->first()->assignee));
        $this->assertSame('Admin assignment', $reassigned->logs()->where('action', 'assign')->first()->data['reason']);
    }

    public function test_admin_can_configure_candidates_by_publishing_a_new_immutable_version(): void
    {
        $admin = app(WorkflowAdministration::class);
        $first = $admin->publish($this->approvalBlueprint());
        $ada = User::create(['name' => 'Ada']);
        $grace = User::create(['name' => 'Grace']);

        $second = $admin->configureStepCandidates('document-approval', 'review', [$ada, $grace]);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertCount(2, $second->states->firstWhere('key', 'review')->candidates);
        $this->assertCount(0, $first->fresh()->states->firstWhere('key', 'review')->candidates);
        $this->assertCount(2, $admin->export('document-approval')['states']['review']['candidates']);
    }

    public function test_process_inspection_and_administrative_filters_expose_current_responsibility(): void
    {
        $admin = app(WorkflowAdministration::class);
        $ada = User::create(['name' => 'Ada']);
        $admin->publish($this->approvalBlueprint()->candidate('draft', $ada));
        $instance = app(WorkflowManager::class)->start(Document::create(['title' => 'Budget']), 'document-approval');

        $snapshot = $admin->process($instance);

        $this->assertSame('draft', $snapshot->currentState->key);
        $this->assertTrue($ada->is($snapshot->currentAssignee));
        $this->assertCount(1, $snapshot->candidateActors);
        $this->assertSame(['submit'], $snapshot->availableTransitions->pluck('action')->all());
        $this->assertCount(1, $snapshot->history);
        $this->assertGreaterThanOrEqual(0, $snapshot->timeInCurrentStateSeconds);
        $this->assertSame(1, $admin->processes()->running()->forWorkflow('document-approval')->inState('draft')->assignedTo($ada)->count());
        $this->assertSame(1, $admin->tasks()->open()->forWorkflow('document-approval')->inState('draft')->count());
    }

    public function test_nudges_are_audited_idempotent_and_delivered_through_the_notification_hook(): void
    {
        Event::fake([WorkflowTaskNudged::class]);
        $notifier = new RecordingWorkflowTaskNotifier;
        app()->instance(WorkflowTaskNotifier::class, $notifier);
        app()->forgetInstance(WorkflowManager::class);
        app()->forgetInstance(WorkflowAdministration::class);
        $admin = app(WorkflowAdministration::class);
        $assignee = User::create(['name' => 'Ada']);
        $administrator = User::create(['name' => 'Administrator']);
        $admin->publish($this->approvalBlueprint()->candidate('draft', $assignee));
        $instance = app(WorkflowManager::class)->start(Document::create(['title' => 'Budget']), 'document-approval');
        $task = $instance->tasks->first();

        $admin->nudge($task, $administrator, ['message' => 'Please review'], 'nudge-1');
        $retried = $admin->nudge($task, $administrator, ['message' => 'Please review'], 'nudge-1');

        $this->assertSame(1, $task->fresh()->nudge_count);
        $this->assertNotNull($task->fresh()->last_nudged_at);
        $this->assertCount(1, $notifier->nudged);
        $this->assertSame(1, $retried->logs()->where('action', 'nudge')->count());
        Event::assertDispatched(WorkflowTaskNudged::class);
    }

    public function test_admin_can_activate_an_older_version_and_deactivate_new_starts(): void
    {
        Event::fake([WorkflowDefinitionActivated::class, WorkflowDefinitionDeactivated::class]);
        $admin = app(WorkflowAdministration::class);
        $first = $admin->publish($this->approvalBlueprint());
        $admin->publish($this->approvalBlueprint()->state('escalated', final: true)->transition('escalate', 'review', 'escalated'));

        $admin->activate($first);
        $instance = app(WorkflowManager::class)->start(Document::create(['title' => 'Budget']), 'document-approval');
        $this->assertSame(1, $instance->version->version);
        Event::assertDispatched(WorkflowDefinitionActivated::class);

        $admin->deactivate('document-approval');
        Event::assertDispatched(WorkflowDefinitionDeactivated::class);

        $this->expectException(WorkflowException::class);
        app(WorkflowManager::class)->start(Document::create(['title' => 'Travel']), 'document-approval');
    }

    public function test_dashboard_summarizes_processes_tasks_and_operational_groupings(): void
    {
        $admin = app(WorkflowAdministration::class);
        $ada = User::create(['name' => 'Ada']);
        $admin->publish($this->approvalBlueprint()->candidate('draft', $ada));
        $instance = app(WorkflowManager::class)->start(Document::create(['title' => 'Budget']), 'document-approval');
        $instance->tasks->first()->update(['due_at' => now()->subMinute()]);

        $summary = $admin->dashboard();

        $this->assertSame(1, $summary->counts['active_definitions']);
        $this->assertSame(1, $summary->counts['running_processes']);
        $this->assertSame(1, $summary->counts['open_tasks']);
        $this->assertSame(1, $summary->counts['overdue_tasks']);
        $this->assertSame(1, $summary->byWorkflow['document-approval']);
        $this->assertSame(1, $summary->byState['document-approval:draft']);
        $this->assertSame(1, $summary->byAssignee[$ada->getMorphClass().':'.$ada->getKey()]);

        app(WorkflowManager::class)->transition($instance, 'submit', $ada);
        $this->assertArrayHasKey('document-approval:draft', $admin->dashboard()->averageStateSeconds);
    }

    private function approvalBlueprint(): WorkflowBlueprint
    {
        return WorkflowBlueprint::make('document-approval')
            ->name('Document approval')
            ->state('draft', initial: true)
            ->state('review')
            ->state('approved', final: true)
            ->transition('submit', 'draft', 'review')
            ->transition('approve', 'review', 'approved');
    }
}
