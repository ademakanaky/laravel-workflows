<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Data\WorkflowDashboardSummary;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowInstanceStatus;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;

class WorkflowDashboard
{
    public function summary(): WorkflowDashboardSummary
    {
        $definitionClass = WorkflowModelRegistry::definition();
        $instanceClass = WorkflowModelRegistry::instance();
        $taskClass = WorkflowModelRegistry::task();

        $openTasks = $taskClass::query()->where('status', WorkflowTaskStatus::Open->value);
        $counts = [
            'definitions' => $definitionClass::query()->count(),
            'active_definitions' => $definitionClass::query()->where('is_active', true)->count(),
            'running_processes' => $instanceClass::query()->where('status', WorkflowInstanceStatus::Running->value)->count(),
            'completed_processes' => $instanceClass::query()->where('status', WorkflowInstanceStatus::Completed->value)->count(),
            'cancelled_processes' => $instanceClass::query()->where('status', WorkflowInstanceStatus::Cancelled->value)->count(),
            'open_tasks' => (clone $openTasks)->count(),
            'overdue_tasks' => (clone $openTasks)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'unassigned_tasks' => (clone $openTasks)->whereNull('assignee_id')->count(),
        ];

        $tasks = $taskClass::query()->with(['instance.definition', 'state'])->get();
        $byWorkflow = $tasks->where('status', WorkflowTaskStatus::Open)
            ->countBy(fn ($task): string => $task->instance->definition->slug)->all();
        $byState = $tasks->where('status', WorkflowTaskStatus::Open)
            ->countBy(fn ($task): string => $task->instance->definition->slug.':'.$task->state->key)->all();
        $byAssignee = $tasks->where('status', WorkflowTaskStatus::Open)
            ->filter(fn ($task): bool => $task->assignee_id !== null)
            ->countBy(fn ($task): string => $task->assignee_type.':'.$task->assignee_id)->all();
        $averageStateSeconds = $tasks->filter(fn ($task): bool => $task->status === WorkflowTaskStatus::Completed)
            ->groupBy(fn ($task): string => $task->instance->definition->slug.':'.$task->state->key)
            ->map(function ($stateTasks): int {
                $average = $stateTasks->average(
                    fn ($task): float => (float) $task->created_at->diffInSeconds($task->completed_at)
                );

                return (int) round((float) ($average ?? 0));
            })->all();

        return new WorkflowDashboardSummary($counts, $byWorkflow, $byState, $byAssignee, $averageStateSeconds);
    }
}
