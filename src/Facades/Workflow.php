<?php

namespace Ademakanaky\LaravelWorkflows\Facades;

use Ademakanaky\LaravelWorkflows\WorkflowManager;
use Illuminate\Support\Facades\Facade;

class Workflow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WorkflowManager::class;
    }
}
