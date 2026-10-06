<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Model;

class WorkflowTaskNudged
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly WorkflowTask $task,
        public readonly ?Model $actor,
        public readonly array $data = [],
    ) {}
}
