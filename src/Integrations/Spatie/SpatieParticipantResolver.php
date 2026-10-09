<?php

namespace Ademakanaky\LaravelWorkflows\Integrations\Spatie;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowParticipantResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Traversable;

class SpatieParticipantResolver implements WorkflowParticipantResolver
{
    public function principals(Model $actor): iterable
    {
        $roles = $actor->relationLoaded('roles')
            ? $actor->getRelation('roles')
            : (method_exists($actor, 'roles') ? $actor->getRelationValue('roles') : new Collection);

        $principals = [$actor];

        if (is_array($roles) || $roles instanceof Traversable) {
            foreach ($roles as $role) {
                $principals[] = $role;
            }
        }

        return collect($principals)
            ->filter(fn ($principal): bool => $principal instanceof Model)
            ->unique(fn (Model $principal): string => $principal->getMorphClass().'::'.$principal->getKey())
            ->values();
    }

    public function matches(Model $actor, Model $principal): bool
    {
        return collect($this->principals($actor))
            ->contains(fn (Model $candidate): bool => $candidate->is($principal));
    }
}
