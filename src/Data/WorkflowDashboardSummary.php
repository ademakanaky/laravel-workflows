<?php

namespace Ademakanaky\LaravelWorkflows\Data;

final class WorkflowDashboardSummary
{
    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $byWorkflow
     * @param  array<string, int>  $byState
     * @param  array<string, int>  $byAssignee
     * @param  array<string, int>  $averageStateSeconds
     */
    public function __construct(
        public readonly array $counts,
        public readonly array $byWorkflow,
        public readonly array $byState,
        public readonly array $byAssignee,
        public readonly array $averageStateSeconds,
    ) {}
}
