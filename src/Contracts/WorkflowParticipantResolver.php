<?php

namespace Ademakanaky\LaravelWorkflows\Contracts;

use Illuminate\Database\Eloquent\Model;

interface WorkflowParticipantResolver
{
    /** @return iterable<Model> */
    public function principals(Model $actor): iterable;

    public function matches(Model $actor, Model $principal): bool;
}
