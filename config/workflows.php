<?php

use Ademakanaky\LaravelWorkflows\Models\WorkflowDefinition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Ademakanaky\LaravelWorkflows\Support\NullAssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Support\TaskTransitionAuthorizer;

return [
    'load_migrations' => true,

    'definitions' => [],

    'models' => [
        'definition' => WorkflowDefinition::class,
        'version' => WorkflowVersion::class,
        'state' => WorkflowState::class,
        'transition' => WorkflowTransition::class,
        'instance' => WorkflowInstance::class,
        'task' => WorkflowTask::class,
        'log' => WorkflowTransitionLog::class,
    ],

    'assignment_strategy' => NullAssignmentStrategy::class,
    'transition_authorizer' => TaskTransitionAuthorizer::class,
    'guards' => [],
    'assignment_strategies' => [],
    'allow_multiple_active_instances' => false,
];
