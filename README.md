# Laravel Workflows

A versioned, extensible workflow and approval engine for Laravel applications.

Laravel Workflows attaches durable processes to any Eloquent model. It provides explicit states and transitions, immutable definition versions, optional task assignment, transition guards, actor authorization, idempotency, lifecycle events, and an append-only audit history without requiring a particular role or tenancy package.

## Requirements

- PHP 8.2 or newer
- Laravel 9.52+, 10.48+, 11, 12, or 13
- A Laravel-supported relational database

## Installation

```bash
composer require ademakanaky/laravel-workflows
php artisan migrate
```

Laravel discovers the package service provider automatically, and package migrations are loaded automatically. Publish the configuration only when you need to customize behavior:

```bash
php artisan vendor:publish --tag=workflows-config
```

If the application must own and modify its migration, publish it and set `load_migrations` to `false` in the published configuration before running migrations. This prevents both copies from being executed:

```bash
php artisan vendor:publish --tag=workflows-migrations
```

## Define a workflow

Add definitions to `config/workflows.php`:

```php
'definitions' => [
    'purchase-approval' => [
        'name' => 'Purchase approval',
        'states' => [
            'draft' => ['initial' => true],
            'manager-review' => [],
            'finance-review' => [],
            'approved' => ['final' => true],
            'rejected' => ['final' => true],
        ],
        'transitions' => [
            ['action' => 'submit', 'from' => 'draft', 'to' => 'manager-review'],
            ['action' => 'approve', 'from' => 'manager-review', 'to' => 'finance-review'],
            ['action' => 'reject', 'from' => 'manager-review', 'to' => 'rejected'],
            ['action' => 'approve', 'from' => 'finance-review', 'to' => 'approved'],
            ['action' => 'reject', 'from' => 'finance-review', 'to' => 'rejected'],
        ],
    ],
],
```

Validate and synchronize definitions:

```bash
php artisan workflow:validate
php artisan workflow:sync
```

Synchronization creates a new immutable version only when a definition changes. Existing instances remain pinned to the version on which they started.

Definitions may also be constructed in application code:

```php
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Facades\Workflow;

$version = Workflow::define(
    WorkflowBlueprint::make('article-review')
        ->name('Article review')
        ->state('draft', initial: true)
        ->state('review')
        ->state('published', final: true)
        ->transition('submit', 'draft', 'review')
        ->transition('publish', 'review', 'published')
);
```

## Attach workflows to a model

```php
use Ademakanaky\LaravelWorkflows\Concerns\HasWorkflows;

class PurchaseRequest extends Model
{
    use HasWorkflows;
}
```

The trait exposes `workflowInstances()` and `activeWorkflowInstances()` relationships. It does not automatically start workflows when a model is created; explicit startup avoids hidden writes and lets the application supply the correct actor and context.

## Start and transition

```php
use Ademakanaky\LaravelWorkflows\Facades\Workflow;

$instance = Workflow::start(
    subject: $purchaseRequest,
    definition: 'purchase-approval',
    actor: $request->user(),
    context: ['amount' => 250_000],
    idempotencyKey: $request->header('Idempotency-Key'),
);

$available = Workflow::availableActions($instance, $request->user());

$instance = Workflow::transition(
    instance: $instance,
    action: 'submit',
    actor: $request->user(),
    data: ['comment' => 'Ready for review'],
    idempotencyKey: 'purchase-123-submit',
);
```

Instances also provide a convenience method:

```php
$instance = $instance->transition('approve', actor: $request->user());
```

All state changes execute inside database transactions and lock the workflow instance row. Supplying idempotency keys makes identical retried starts and transitions return the original result rather than applying the operation twice. Reusing a key with different input throws `IdempotencyConflictException`.

Subjects and actors may use integer, UUID, or ULID string keys, but they must be persisted Eloquent models.

## Cancel a workflow

Cancellation is an audited, idempotent operation intended for trusted application services:

```php
$instance = Workflow::cancel(
    instance: $instance,
    actor: $administrator,
    data: ['reason' => 'The request was withdrawn'],
    idempotencyKey: 'purchase-123-cancel',
);
```

Cancellation closes every open task, records a `cancel` history entry, and dispatches `WorkflowCancelled` after commit. The calling application remains responsible for authorizing cancellation.

## Guards

Guards enforce business conditions on a particular transition:

```php
use Ademakanaky\LaravelWorkflows\Contracts\TransitionGuard;

class HasSufficientBudget implements TransitionGuard
{
    public function allows($actor, $instance, $transition, array $data): bool
    {
        return $instance->subject->remaining_budget >= $instance->context['amount'];
    }

    public function message(): string
    {
        return 'The remaining budget is insufficient.';
    }
}
```

Register a safe alias in `config/workflows.php`:

```php
'guards' => [
    'sufficient-budget' => App\Workflows\HasSufficientBudget::class,
],
```

Reference the alias on a transition:

```php
[
    'action' => 'approve',
    'from' => 'finance-review',
    'to' => 'approved',
    'guards' => ['sufficient-budget'],
]
```

Guards are resolved through Laravel's container and must implement `TransitionGuard`. Class names remain supported for code-managed definitions, while aliases give an administration interface a finite allow-list it can safely display.

## Assignment and authorization

The package intentionally has no dependency on Spatie Permission or a particular `User` model. Implement `AssignmentStrategy` to choose an assignee whenever a workflow enters a non-final state:

```php
use Ademakanaky\LaravelWorkflows\Contracts\AssignmentStrategy;

class LeastBusyApprover implements AssignmentStrategy
{
    public function assign($instance, $state, $actor): ?Model
    {
        return User::role($state->metadata['role'] ?? 'approver')
            ->withCount(['workflowTasks' => fn ($query) => $query->where('status', 'open')])
            ->orderBy('workflow_tasks_count')
            ->first();
    }
}
```

Configure it:

```php
'assignment_strategy' => App\Workflows\LeastBusyApprover::class,
```

That global strategy is the fallback. To let code or an administration interface choose a policy for a particular state, register aliases and reference one in the definition:

```php
'assignment_strategies' => [
    'least-busy-approver' => App\Workflows\LeastBusyApprover::class,
],

// Inside a state's array definition:
'manager-review' => [
    'assignment_strategy' => 'least-busy-approver',
    'metadata' => ['role' => 'manager'],
],
```

Guard and assignment-strategy references are verified when a definition is published, so an unavailable extension cannot become a runtime-only failure.

The default `TaskTransitionAuthorizer` allows unassigned tasks and restricts assigned tasks to their assignee. Replace it with any class implementing `TransitionAuthorizer` to integrate gates, roles, teams, tenants, or service actors:

```php
'transition_authorizer' => App\Workflows\AuthorizeWorkflowTransition::class,
```

Applications remain responsible for authorizing access to their HTTP controllers and for preventing untrusted callers from invoking workflow management operations.

Actor models may use the `ParticipatesInWorkflows` trait to obtain `startedWorkflowInstances()`, `assignedWorkflowTasks()`, and `workflowActions()` relationships.

Trusted application services may manually assign or unassign the current task. The operation is idempotent and recorded in workflow history:

```php
$instance = Workflow::assign(
    instance: $instance,
    assignee: $reviewer,
    actor: $administrator,
    data: ['reason' => 'Delegated during leave'],
    idempotencyKey: 'assignment-456',
);
```

## Task inbox and notifications

Add `ParticipatesInWorkflows` to the model that may receive workflow tasks:

```php
use Ademakanaky\LaravelWorkflows\Concerns\ParticipatesInWorkflows;

class User extends Authenticatable
{
    use ParticipatesInWorkflows;
}
```

An authenticated user's pending-work page can use the facade-backed inbox. It returns only open tasks assigned to that actor and eager loads the workflow state, definition, and subject:

```php
use Ademakanaky\LaravelWorkflows\Facades\Workflow;

$tasks = Workflow::inbox($request->user())->paginate(20);
$pendingCount = Workflow::pendingCount($request->user());
```

The count is suitable for navigation and menu badges. The same API is injectable when a facade is not desired:

```php
use Ademakanaky\LaravelWorkflows\WorkflowInbox;

$tasks = app(WorkflowInbox::class)->paginate($request->user(), perPage: 20);
$pendingCount = app(WorkflowInbox::class)->count($request->user());
```

The actor trait also exposes `pendingWorkflowTasks()`. Task queries may be composed directly:

```php
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;

$tasks = WorkflowTask::query()
    ->open()
    ->assignedTo($request->user())
    ->get();

$overdue = WorkflowTask::query()
    ->assignedTo($request->user())
    ->overdue()
    ->get();
```

Render the permitted actions for each inbox item and submit the selected transition through the normal runtime API:

```php
$available = $task->instance->availableTransitions($request->user());

$instance = $task->instance->transition(
    action: 'approve',
    actor: $request->user(),
    data: ['comment' => $request->string('comment')->toString()],
);
```

For proactive alerts, implement `WorkflowTaskNotifier`. Its methods run after the enclosing database transaction commits, so notifications are never sent for rolled-back work. The default implementation does nothing.

```php
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowTaskNotifier;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use App\Notifications\WorkflowApprovalRequested;
use Illuminate\Database\Eloquent\Model;

class ApplicationWorkflowNotifier implements WorkflowTaskNotifier
{
    public function opened(WorkflowTask $task, ?Model $actor): void
    {
        $task->assignee?->notify(new WorkflowApprovalRequested($task));
    }

    public function assigned(WorkflowTask $task, ?Model $previousAssignee, ?Model $actor): void
    {
        $task->assignee?->notify(new WorkflowApprovalRequested($task));
    }

    public function completed(WorkflowTask $task, ?Model $actor): void {}

    public function cancelled(WorkflowTask $task, ?Model $actor): void {}
}
```

Register it in `config/workflows.php`:

```php
'task_notifier' => App\Workflows\ApplicationWorkflowNotifier::class,
```

The notifier can send Laravel database, mail, broadcast, Slack, or other notifications. Queue the application's notification when delivery should happen asynchronously. Applications may instead listen directly for `WorkflowTaskOpened`, `WorkflowTaskAssigned`, `WorkflowTaskCompleted`, and `WorkflowTaskCancelled`.

Only assigned tasks appear in a personal inbox. Configure an `AssignmentStrategy` or explicitly call `Workflow::assign()` when a state requires an individual actor to take action.

## History and events

Every start and transition creates an immutable `WorkflowTransitionLog` containing the states, action, actor, data, and idempotency key.

```php
$history = $instance->logs()
    ->with(['fromState', 'toState', 'actor'])
    ->oldest('created_at')
    ->get();
```

The package dispatches:

- `WorkflowStarting` inside the transaction, before an instance is created
- `WorkflowStarted` after commit
- `WorkflowTransitioning` inside the transaction, before state mutation
- `WorkflowTransitioned` after commit
- `WorkflowCompleted` after commit when a final state is entered
- `WorkflowTaskOpened` after a new task commits
- `WorkflowTaskAssigned` after an assignment commits
- `WorkflowTaskCompleted` after its transition commits
- `WorkflowTaskCancelled` after cancellation commits
- `WorkflowCancelled` after cancellation commits
- `WorkflowDefinitionPublishing` before a definition version is persisted
- `WorkflowDefinitionPublished` after a definition version commits

Listeners for the two pre-mutation events may throw an exception to abort and roll back the operation. Post-commit events are suitable for notifications, webhooks, and queued automation.

## Administration-package integration

The runtime package contains supported authoring seams so a separate administration package never needs to edit published records directly.

Definitions have an ownership source:

- `code` definitions are produced by `workflow:sync` and are read-only to database authoring tools.
- `database` definitions are published by an administration interface and cannot be overwritten by `workflow:sync`.

An administration package publishes a validated blueprint through the contract:

```php
use Ademakanaky\LaravelWorkflows\Contracts\DefinitionPublisher;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;

$version = app(DefinitionPublisher::class)->publish(
    $blueprint,
    WorkflowDefinitionSource::Database,
);
```

Drafts can be checked without writing anything by resolving `DefinitionValidator` and calling `validate($blueprint)`. This is the same validator used by `workflow:validate` and `DefinitionPublisher`, so preview and publication cannot drift onto different rule sets.

`WorkflowDefinitionExporter` converts any published version back to the same canonical array schema used by `WorkflowBlueprint::fromArray()`. This supports visual editing, cloning, import/export, diffs, and draft publication without coupling the admin package to internal tables.

`WorkflowExtensionRegistry` exposes the registered guards and assignment strategies that an interface may safely offer as dropdown choices. State-level assignment policies and transition guards are part of the canonical import/export schema. Mutable drafts and the visual interface belong to the separate admin package; only validated publication crosses into the runtime core.

## Custom models

Every package model is replaceable in `config/workflows.php`. Custom models should extend the corresponding package model so its relationships and casts remain available.

## Safety characteristics

- Definitions are validated before persistence.
- Exactly one initial state and at least one final state are required.
- Unknown, duplicate, unreachable, dead-end, non-terminating, and final-state outgoing transitions are rejected.
- Referenced guards and assignment strategies must exist before publication.
- Running instances retain their original definition version.
- Transitions use row locks and database transactions.
- Transition history is append-only through the public API.
- Published definitions, versions, states, transitions, and history records reject destructive Eloquent operations.
- Idempotency protects safely retried commands and rejects conflicting key reuse.
- Authorization and assignment are explicit extension points.
- Code-managed and database-managed definition namespaces cannot overwrite one another.

## Testing

```bash
composer install
composer test
composer analyse
composer format
```

The suite uses Orchestra Testbench. CI exercises supported Laravel/PHP combinations with SQLite, runs the feature suite against MySQL and PostgreSQL, audits current dependencies, and installs the package into a clean Laravel application for an end-to-end smoke test.

The supported public API and release guarantees are documented in [docs/STABLE_API.md](docs/STABLE_API.md).

## Roadmap

The first stable release focuses on deterministic sequential workflows. Planned extensions include parallel approval tasks, quorum approvals, deadlines, escalations, scheduled automation, and optional administration/API packages.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md) for responsible disclosure guidance.

## License

Laravel Workflows is released under the MIT License.
