<?php

namespace Ademakanaky\LaravelWorkflows\Facades;

use Ademakanaky\LaravelWorkflows\WorkflowAdministration;
use Illuminate\Support\Facades\Facade;

class WorkflowAdmin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WorkflowAdministration::class;
    }
}
