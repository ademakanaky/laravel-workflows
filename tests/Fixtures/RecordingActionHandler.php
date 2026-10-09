<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Fixtures;

use Ademakanaky\LaravelWorkflows\Contracts\WorkflowActionHandler;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Illuminate\Database\Eloquent\Model;

class RecordingActionHandler implements WorkflowActionHandler
{
    /** @var list<string> */
    public static array $states = [];

    public function handle(?Model $actor, WorkflowInstance $instance, WorkflowTransition $transition, array $data): void
    {
        self::$states[] = $instance->currentState->key;
    }

    public static function reset(): void
    {
        self::$states = [];
    }
}
