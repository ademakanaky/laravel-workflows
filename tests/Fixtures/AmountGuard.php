<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Contracts\TransitionGuard;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

class AmountGuard implements TransitionGuard
{
    public function allows(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition, array $data): bool
    {
        return ($data['amount'] ?? 0) <= 1000;
    }

    public function message(): string
    {
        return 'The amount exceeds the approval limit.';
    }
}
