<?php

namespace Ademakanaky\LaravelWorkflows\Support;

use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;
use Ademakanaky\LaravelWorkflows\Contracts\TransitionGuard;
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowActionHandler;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;

class WorkflowExtensionRegistry
{
    /** @var array<string, class-string<TransitionGuard>> */
    private array $guards = [];

    /** @var array<string, class-string<AssignmentStrategy>> */
    private array $assignmentStrategies = [];

    /** @var array<string, class-string<WorkflowActionHandler>> */
    private array $actionHandlers = [];

    /**
     * @param  array<string, class-string<TransitionGuard>>  $guards
     * @param  array<string, class-string<AssignmentStrategy>>  $assignmentStrategies
     * @param  array<string, class-string<WorkflowActionHandler>>  $actionHandlers
     */
    public function __construct(array $guards = [], array $assignmentStrategies = [], array $actionHandlers = [])
    {
        foreach ($guards as $alias => $class) {
            $this->registerGuard($alias, $class);
        }
        foreach ($assignmentStrategies as $alias => $class) {
            $this->registerAssignmentStrategy($alias, $class);
        }
        foreach ($actionHandlers as $alias => $class) {
            $this->registerActionHandler($alias, $class);
        }
    }

    /** @param class-string<TransitionGuard> $class */
    public function registerGuard(string $alias, string $class): self
    {
        if (! is_subclass_of($class, TransitionGuard::class)) {
            throw new WorkflowException("Guard [{$class}] must implement ".TransitionGuard::class.'.');
        }

        $this->guards[$alias] = $class;

        return $this;
    }

    /** @return class-string<TransitionGuard> */
    public function resolveGuard(string $aliasOrClass): string
    {
        $class = $this->guards[$aliasOrClass] ?? $aliasOrClass;
        if (! is_subclass_of($class, TransitionGuard::class)) {
            throw new WorkflowException("Guard [{$aliasOrClass}] is not registered or does not implement ".TransitionGuard::class.'.');
        }

        return $class;
    }

    /** @return array<string, class-string<TransitionGuard>> */
    public function guards(): array
    {
        return $this->guards;
    }

    /** @param class-string<AssignmentStrategy> $class */
    public function registerAssignmentStrategy(string $alias, string $class): self
    {
        if (! is_subclass_of($class, AssignmentStrategy::class)) {
            throw new WorkflowException("Assignment strategy [{$class}] must implement ".AssignmentStrategy::class.'.');
        }

        $this->assignmentStrategies[$alias] = $class;

        return $this;
    }

    /** @return array<string, class-string<AssignmentStrategy>> */
    public function assignmentStrategies(): array
    {
        return $this->assignmentStrategies;
    }

    /** @return class-string<AssignmentStrategy> */
    public function resolveAssignmentStrategy(string $aliasOrClass): string
    {
        $class = $this->assignmentStrategies[$aliasOrClass] ?? $aliasOrClass;
        if (! is_subclass_of($class, AssignmentStrategy::class)) {
            throw new WorkflowException("Assignment strategy [{$aliasOrClass}] is not registered or does not implement ".AssignmentStrategy::class.'.');
        }

        return $class;
    }

    /** @param class-string<WorkflowActionHandler> $class */
    public function registerActionHandler(string $alias, string $class): self
    {
        if (! is_subclass_of($class, WorkflowActionHandler::class)) {
            throw new WorkflowException("Action handler [{$class}] must implement ".WorkflowActionHandler::class.'.');
        }

        $this->actionHandlers[$alias] = $class;

        return $this;
    }

    /** @return class-string<WorkflowActionHandler> */
    public function resolveActionHandler(string $aliasOrClass): string
    {
        $class = $this->actionHandlers[$aliasOrClass] ?? $aliasOrClass;
        if (! is_subclass_of($class, WorkflowActionHandler::class)) {
            throw new WorkflowException("Action handler [{$aliasOrClass}] is not registered or does not implement ".WorkflowActionHandler::class.'.');
        }

        return $class;
    }

    /** @return array<string, class-string<WorkflowActionHandler>> */
    public function actionHandlers(): array
    {
        return $this->actionHandlers;
    }
}
