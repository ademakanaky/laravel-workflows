<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Feature;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskAssigned;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskCancelled;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskCompleted;
use Ademakanaky\LaravelWorkflows\Events\WorkflowTaskOpened;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Facades\Workflow;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\ActorAssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\Document;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\RecordingWorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Tests\Fixtures\User;
use Ademakanaky\LaravelWorkflows\Tests\TestCase;
use Ademakanaky\LaravelWorkflows\WorkflowInbox;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class WorkflowInboxTest extends TestCase
{
    public function test_assigned_actors_can_query_paginate_and_count_pending_tasks(): void
    {
        [$manager, $notifier] = $this->managerWithNotifications();
        $manager->define($this->approvalBlueprint());
        $actor = User::create(['name' => 'Ada']);
        $otherActor = User::create(['name' => 'Grace']);

        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval', $actor);

        $this->assertSame(1, Workflow::pendingCount($actor));
        $this->assertSame(0, Workflow::pendingCount($otherActor));
        $this->assertCount(1, $actor->pendingWorkflowTasks);
        $this->assertCount(1, Workflow::inbox($actor)->get());

        $page = app(WorkflowInbox::class)->paginate($actor, perPage: 10);
        $this->assertSame(1, $page->total());
        $this->assertTrue($page->first()->relationLoaded('instance'));
        $this->assertTrue($page->first()->instance->relationLoaded('subject'));

        $task = $instance->tasks->first();
        $task->update(['due_at' => now()->subMinute()]);

        $this->assertSame(1, WorkflowTask::query()->open()->assignedTo($actor)->count());
        $this->assertSame(0, WorkflowTask::query()->open()->assignedTo($otherActor)->count());
        $this->assertSame(1, WorkflowTask::query()->overdue()->count());
        $this->assertCount(1, $notifier->opened);
    }

    public function test_task_lifecycle_events_and_notification_hook_run_after_commit(): void
    {
        [$manager, $notifier] = $this->managerWithNotifications();
        $manager->define($this->approvalBlueprint());
        Event::fake([
            WorkflowTaskOpened::class,
            WorkflowTaskAssigned::class,
            WorkflowTaskCompleted::class,
            WorkflowTaskCancelled::class,
        ]);
        $actor = User::create(['name' => 'Ada']);

        $instance = $manager->start(Document::create(['title' => 'Budget']), 'document-approval', $actor);
        $firstTask = $instance->tasks->first();
        Event::assertDispatched(WorkflowTaskOpened::class, fn (WorkflowTaskOpened $event): bool => $event->task->is($firstTask));
        $this->assertCount(1, $notifier->opened);

        $manager->assign($instance, $actor, $actor);
        Event::assertDispatched(WorkflowTaskAssigned::class);
        $this->assertCount(1, $notifier->assigned);

        $instance = $manager->transition($instance, 'submit', $actor);
        Event::assertDispatched(WorkflowTaskCompleted::class, fn (WorkflowTaskCompleted $event): bool => $event->task->is($firstTask)
            && $event->task->status === WorkflowTaskStatus::Completed);
        $this->assertCount(1, $notifier->completed);
        $this->assertCount(2, $notifier->opened);
        $this->assertSame(1, $manager->pendingCount($actor));

        $openTask = $instance->tasks->firstWhere('status', WorkflowTaskStatus::Open);
        $manager->cancel($instance, $actor);
        Event::assertDispatched(WorkflowTaskCancelled::class, fn (WorkflowTaskCancelled $event): bool => $event->task->is($openTask)
            && $event->task->status === WorkflowTaskStatus::Cancelled);
        $this->assertCount(1, $notifier->cancelled);
        $this->assertSame(0, $manager->pendingCount($actor));
    }

    public function test_task_opened_event_and_notification_are_suppressed_by_outer_rollback(): void
    {
        [$manager, $notifier] = $this->managerWithNotifications();
        $manager->define($this->approvalBlueprint());
        Event::fake([WorkflowTaskOpened::class]);
        $actor = User::create(['name' => 'Ada']);

        DB::beginTransaction();
        try {
            $manager->start(Document::create(['title' => 'Budget']), 'document-approval', $actor);
            Event::assertNotDispatched(WorkflowTaskOpened::class);
            $this->assertCount(0, $notifier->opened);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        Event::assertNotDispatched(WorkflowTaskOpened::class);
        $this->assertCount(0, $notifier->opened);
    }

    public function test_an_unsaved_actor_cannot_query_unassigned_tasks(): void
    {
        $this->expectException(WorkflowException::class);

        app(WorkflowInbox::class)->query(new User);
    }

    /** @return array{WorkflowManager, RecordingWorkflowTaskNotifier} */
    private function managerWithNotifications(): array
    {
        config()->set('workflows.assignment_strategy', ActorAssignmentStrategy::class);
        $notifier = new RecordingWorkflowTaskNotifier;
        app()->instance(WorkflowTaskNotifier::class, $notifier);
        app()->forgetInstance(WorkflowManager::class);

        return [app(WorkflowManager::class), $notifier];
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
