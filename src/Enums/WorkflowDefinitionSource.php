<?php

namespace Ademakanaky\LaravelWorkflows\Enums;

enum WorkflowDefinitionSource: string
{
    case Code = 'code';
    case Database = 'database';
}
