<?php

use Ademakanaky\LaravelWorkflows\Models\WorkflowDefinition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowState;
use Ademakanaky\LaravelWorkflows\Models\WorkflowStateCandidate;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTaskCandidate;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTransitionLog;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Ademakanaky\LaravelWorkflows\Support\DirectWorkflowParticipantResolver;
use Ademakanaky\LaravelWorkflows\Support\NullAssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Support\NullWorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Support\TaskTransitionAuthorizer;

return [
    'load_migrations' => true,

    'definitions' => [],

    'models' => [
        'definition' => WorkflowDefinition::class,
        'version' => WorkflowVersion::class,
        'state' => WorkflowState::class,
        'state_candidate' => WorkflowStateCandidate::class,
        'transition' => WorkflowTransition::class,
        'instance' => WorkflowInstance::class,
        'task' => WorkflowTask::class,
        'task_candidate' => WorkflowTaskCandidate::class,
        'log' => WorkflowTransitionLog::class,
    ],

    'assignment_strategy' => NullAssignmentStrategy::class,
    'transition_authorizer' => TaskTransitionAuthorizer::class,
    'task_notifier' => NullWorkflowTaskNotifier::class,
    'participant_resolver' => DirectWorkflowParticipantResolver::class,
    'guards' => [],
    'assignment_strategies' => [],
    'action_handlers' => [],
    'spatie' => [
        'permission_metadata_key' => 'permission',
        'permission_mode' => 'all',
    ],
    'allow_multiple_active_instances' => false,
];
