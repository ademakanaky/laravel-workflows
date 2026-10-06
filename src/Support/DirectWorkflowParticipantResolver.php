<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowParticipantResolver;
use Illuminate\Database\Eloquent\Model;

class DirectWorkflowParticipantResolver implements WorkflowParticipantResolver
{
    public function principals(Model $actor): iterable
    {
        return [$actor];
    }

    public function matches(Model $actor, Model $principal): bool
    {
        foreach ($this->principals($actor) as $candidate) {
            if ($candidate->is($principal)) {
                return true;
            }
        }

        return false;
    }
}
