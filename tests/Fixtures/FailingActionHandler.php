<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowActionHandler;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class FailingActionHandler implements WorkflowActionHandler
{
    public function handle(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition, array $data): void
    {
        throw new RuntimeException('The domain action failed.');
    }
}
