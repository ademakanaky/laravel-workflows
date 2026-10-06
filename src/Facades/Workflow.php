<?php

namespace Ademakanaky\LaravelWorkflows\Facades;

use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Builder<WorkflowTask> inbox(Model $actor)
 * @method static int pendingCount(Model $actor)
 */
class Workflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WorkflowManager::class;
    }
}
