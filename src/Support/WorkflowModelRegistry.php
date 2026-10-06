<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowDefinition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Ademakanaky\LaravelWorkflows\Models\WorkflowStateCandidate;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTaskCandidate;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Model;

final class WorkflowModelRegistry
{
    /** @return class-string<WorkflowDefinition> */
    public static function definition(): string
    {
        return self::resolve('definition', WorkflowDefinition::class);
    }

    /** @return class-string<WorkflowVersion> */
    public static function version(): string
    {
        return self::resolve('version', WorkflowVersion::class);
    }

    /** @return class-string<WorkflowState> */
    public static function state(): string
    {
        return self::resolve('state', WorkflowState::class);
    }

    /** @return class-string<WorkflowStateCandidate> */
    public static function stateCandidate(): string
    {
        return self::resolve('state_candidate', WorkflowStateCandidate::class);
    }

    /** @return class-string<WorkflowTransition> */
    public static function transition(): string
    {
        return self::resolve('transition', WorkflowTransition::class);
    }

    /** @return class-string<WorkflowInstance> */
    public static function instance(): string
    {
        return self::resolve('instance', WorkflowInstance::class);
    }

    /** @return class-string<WorkflowTask> */
    public static function task(): string
    {
        return self::resolve('task', WorkflowTask::class);
    }

    /** @return class-string<WorkflowTaskCandidate> */
    public static function taskCandidate(): string
    {
        return self::resolve('task_candidate', WorkflowTaskCandidate::class);
    }

    /** @return class-string<WorkflowTransitionLog> */
    public static function log(): string
    {
        return self::resolve('log', WorkflowTransitionLog::class);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $default
     * @return class-string<TModel>
     */
    private static function resolve(string $key, string $default): string
    {
        $class = config("workflows.models.{$key}", $default);

        if (! is_string($class) || ! is_a($class, $default, true)) {
            throw new WorkflowException("Configured workflow model [{$key}] must extend [{$default}].");
        }

        return $class;
    }
}
