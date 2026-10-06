<?php

namespace Ademakanaky\LaravelWorkflows\Events;

use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Model;

class WorkflowStarting
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly Model $subject,
        public readonly WorkflowVersion $version,
        public readonly ?Model $actor,
        public readonly array $context,
    ) {}
}
