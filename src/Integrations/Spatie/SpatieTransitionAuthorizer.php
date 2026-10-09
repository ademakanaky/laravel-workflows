<?php

namespace Ademakanaky\LaravelWorkflows\Integrations\Spatie;

use Ademakanaky\LaravelWorkflows\Contracts\TransitionAuthorizer;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Ademakanaky\LaravelWorkflows\Support\TaskTransitionAuthorizer;
use Illuminate\Database\Eloquent\Model;

class SpatieTransitionAuthorizer implements TransitionAuthorizer
{
    public function __construct(private readonly TaskTransitionAuthorizer $tasks) {}

    public function authorize(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition): bool
    {
        if (! $this->tasks->authorize($actor, $instance, $transition)) {
            return false;
        }

        $key = (string) config('workflows.spatie.permission_metadata_key', 'permission');
        $required = $transition->metadata[$key] ?? null;
        if ($required === null) {
            return true;
        }
        if ($actor === null) {
            return false;
        }

        $permissions = is_array($required) ? array_values($required) : [$required];
        if ($permissions === [] || collect($permissions)->contains(fn ($permission): bool => ! is_string($permission) || trim($permission) === '')) {
            return false;
        }
        $checks = collect($permissions)->map(fn (string $permission): bool => $this->can($actor, $permission));

        return config('workflows.spatie.permission_mode', 'all') === 'any'
            ? $checks->contains(true)
            : ! $checks->contains(false);
    }

    private function can(Model $actor, string $permission): bool
    {
        if (is_callable([$actor, 'hasPermissionTo'])) {
            return (bool) $actor->{'hasPermissionTo'}($permission);
        }
        if (is_callable([$actor, 'can'])) {
            return (bool) $actor->{'can'}($permission);
        }

        return false;
    }
}
