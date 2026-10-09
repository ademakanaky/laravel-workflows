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

Applications upgrading from a release where the original migration was already published with `load_migrations` disabled can publish only the additive administration migration:

```bash
php artisan vendor:publish --tag=workflows-administration-migration
php artisan migrate
```

Applications upgrading from 1.1 with package migrations disabled must also publish the additive 1.2 migration:

```bash
php artisan vendor:publish --tag=workflows-v1-2-migration
php artisan migrate
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

For the common maker/checker rule, register the package's built-in guard and place it only on transitions that must not be performed by the workflow initiator:

```php
use Ademakanaky\LaravelWorkflows\Guards\ActorIsNotWorkflowInitiator;

'guards' => [
    'maker-checker' => ActorIsNotWorkflowInitiator::class,
],

// Transition definition:
'guards' => ['maker-checker'],
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

### Optional Spatie Permission integration

When the consuming application uses `spatie/laravel-permission`, the package includes adapters that preserve task-assignment rules while adding role candidates and permission checks. The Spatie package remains optional.

```php
use Ademakanaky\LaravelWorkflows\Integrations\Spatie\SpatieParticipantResolver;
use Ademakanaky\LaravelWorkflows\Integrations\Spatie\SpatieTransitionAuthorizer;

'participant_resolver' => SpatieParticipantResolver::class,
'transition_authorizer' => SpatieTransitionAuthorizer::class,
'spatie' => [
    'permission_metadata_key' => 'permission',
    'permission_mode' => 'all', // or "any" for a list of permissions
],
```

Use a Spatie `Role` model as a state candidate, or attach a permission requirement to transition metadata:

```php
$blueprint
    ->candidate('manager-review', $managerRole)
    ->transition(
        'approve',
        'manager-review',
        'approved',
        metadata: ['permission' => 'purchase-requests.approve'],
    );
```

The actor must still be eligible for the current task. Permission metadata may be a string or a list of strings.

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

## Apply approved or rejected requests

Transition action handlers let each workflow or request type apply its own business behavior without putting domain logic in controllers or in this package. Implement `WorkflowActionHandler` and register a safe alias:

```php
use Ademakanaky\LaravelWorkflows\Contracts\WorkflowActionHandler;

class ApplyPurchaseDecision implements WorkflowActionHandler
{
    public function handle($actor, $instance, $transition, array $data): void
    {
        $request = $instance->subject;
        $request->update(['status' => $transition->toState->metadata['outcome']]);
    }
}

// config/workflows.php
'action_handlers' => [
    'apply-purchase-decision' => App\Workflows\ApplyPurchaseDecision::class,
],
```

Attach handlers to the appropriate transitions. Transactional handlers execute before the state is changed and roll back the complete transition when they fail. After-commit handlers execute only after the outer database transaction commits and are suitable for integrations and side effects.

```php
$blueprint->transition(
    'approve',
    'finance-review',
    'approved',
    handlers: ['apply-purchase-decision'],
    afterCommitHandlers: ['send-purchase-to-erp'],
);
```

Handlers and their aliases are validated when a definition is published, included in definition export, and protected by transition idempotency.

Final states can carry an application-defined outcome. Entering one dispatches `WorkflowOutcomeReached` after commit, allowing a single listener to route approved, rejected, cancelled, or custom outcomes across request types:

```php
$blueprint
    ->state('approved', final: true)
    ->state('rejected', final: true)
    ->outcome('approved', 'approved')
    ->outcome('rejected', 'rejected');
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
        $recipients = $task->assignee
            ? collect([$task->assignee])
            : $task->candidates->pluck('candidate')->filter();

        $recipients->each->notify(new WorkflowApprovalRequested($task));
    }

    public function assigned(WorkflowTask $task, ?Model $previousAssignee, ?Model $actor): void
    {
        $task->assignee?->notify(new WorkflowApprovalRequested($task));
    }

    public function completed(WorkflowTask $task, ?Model $actor): void {}

    public function cancelled(WorkflowTask $task, ?Model $actor): void {}

    public function nudged(WorkflowTask $task, ?Model $actor, array $data): void
    {
        $this->opened($task, $actor);
    }
}
```

Register it in `config/workflows.php`:

```php
'task_notifier' => App\Workflows\ApplicationWorkflowNotifier::class,
```

The notifier can send Laravel database, mail, broadcast, Slack, or other notifications. Queue the application's notification when delivery should happen asynchronously. Applications may instead listen directly for `WorkflowTaskOpened`, `WorkflowTaskAssigned`, `WorkflowTaskCompleted`, and `WorkflowTaskCancelled`.

Assigned tasks and unassigned tasks for which the actor is a candidate appear in the personal inbox. Configure candidates, an `AssignmentStrategy`, or explicitly call `Workflow::assign()` when a state requires an actor to take action.

## Administration API

`WorkflowAdministration` is the supported headless boundary for an administration interface. The package deliberately does not prescribe routes, controllers, frontend technology, or authorization policy. A consuming application can build a Blade, Livewire, Inertia, API, Filament, Nova, or custom interface over the same service without a second package. Controllers should use this service or the `WorkflowAdmin` facade instead of updating package tables directly. The host application remains responsible for authorizing administrative routes and actions.

```php
use Ademakanaky\LaravelWorkflows\WorkflowAdministration;

$admin = app(WorkflowAdministration::class);

$definitions = $admin->definitions()->paginate();
$versions = $admin->versions('purchase-approval')->paginate();
$definition = $admin->definition('purchase-approval');
```

### Create and edit drafts

`WorkflowDraft` is a mutable, serializable authoring object for forms and API payloads. Draft persistence remains application-owned, while validation and publication stay inside the package:

```php
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowDraft;

$draft = WorkflowDraft::make('purchase-approval')
    ->name('Purchase approval')
    ->putState('pending', ['initial' => true])
    ->putState('approved', ['final' => true])
    ->outcome('approved', 'approved')
    ->putTransition('approve', 'pending', 'approved')
    ->guards('pending', 'approve', ['maker-checker'])
    ->handlers('pending', 'approve', ['apply-purchase-decision']);

DraftWorkflow::updateOrCreate(
    ['slug' => $draft->slug],
    ['definition' => $draft->toArray()],
);

$draft = WorkflowDraft::fromArray($storedDraft->definition);
$admin->validateDraft($draft); // no database writes
$version = $admin->publishDraft($draft); // immutable database-managed version
```

`$admin->draft($slug)` starts from the active or latest published version when one exists. State and transition removal, candidates, assignment strategies, guards, handlers, outcomes, and metadata are all editable through the draft API. The application decides who may save a draft, review it, and publish it.

### Configure step participants

Candidates are versioned with the workflow definition. One candidate is assigned automatically. Multiple candidates receive the unassigned task in their inbox and an eligible candidate may claim it.

```php
$blueprint
    ->candidates('manager-review', [$managerA, $managerB])
    ->candidate('finance-review', $financeManager)
    ->assignmentStrategy('director-review', 'least-busy-director');

$version = $admin->publish($blueprint);
```

For an existing database-managed definition, this convenience method exports the active version, changes the candidates, and publishes a new immutable version:

```php
$version = $admin->configureStepCandidates(
    slug: 'purchase-approval',
    state: 'manager-review',
    candidates: [$managerA, $managerB],
);
```

Code-managed definitions remain read-only to database administration tools. Change their configuration in code and run `workflow:sync`.

Candidates are polymorphic Eloquent models. A candidate may therefore be a user, team, role, or another application-owned principal. To make team and role tasks appear in each member's inbox, implement `WorkflowParticipantResolver` and configure it as `participant_resolver`. Its `principals()` method returns the user together with their teams or roles, while `matches()` determines whether an actor represents a configured principal.

### Inspect and search processes

```php
$process = $admin->process($instanceId);

$process->currentState;
$process->subject;
$process->currentTask;
$process->currentAssignee;
$process->candidateActors;
$process->availableTransitions;
$process->history;
$process->timeInCurrentStateSeconds;
```

Administration queries support normal Eloquent pagination and task/process scopes:

```php
$processes = $admin->processes()
    ->running()
    ->forWorkflow('purchase-approval')
    ->inState('manager-review')
    ->assignedTo($manager)
    ->paginate();

$tasks = $admin->tasks()
    ->open()
    ->forWorkflow('purchase-approval')
    ->inState('manager-review')
    ->paginate();

$overdue = $admin->tasks()->overdue()->paginate();
$unassigned = $admin->tasks()->open()->unassigned()->paginate();
```

### Claim, release, reassign, and nudge

All responsibility changes and reminders are recorded in the immutable workflow history.

```php
$admin->claim($task, $candidate, idempotencyKey: 'claim-123');
$admin->release($task, $candidate, idempotencyKey: 'release-123');
$admin->reassign($task, $newAssignee, $administrator, ['reason' => 'Covering leave']);
$admin->unassign($task, $administrator);
$admin->nudge($task, $administrator, ['message' => 'Approval is overdue'], 'nudge-123');
```

Nudging updates `last_nudged_at` and `nudge_count`, creates a `nudge` history record, dispatches `WorkflowTaskNudged` after commit, and invokes `WorkflowTaskNotifier::nudged()`.

### Activate and deactivate definitions

Publishing a new definition version activates it for new process instances. Existing processes remain pinned to their original version. An administrator may explicitly activate an older version or prevent new starts:

```php
$admin->activate($version, $administrator);
$admin->deactivate('purchase-approval', $administrator);
```

Deactivation does not interrupt already running instances.

### Dashboard summaries

```php
$dashboard = $admin->dashboard();

$dashboard->counts;              // definitions, processes, open/overdue/unassigned tasks
$dashboard->byWorkflow;          // open task counts
$dashboard->byState;             // open task counts
$dashboard->byAssignee;          // desk workload
$dashboard->averageStateSeconds; // completed-task turnaround time
```

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
- `WorkflowOutcomeReached` after commit when a final state declares an outcome
- `WorkflowTaskOpened` after a new task commits
- `WorkflowTaskAssigned` after an assignment commits
- `WorkflowTaskCompleted` after its transition commits
- `WorkflowTaskCancelled` after cancellation commits
- `WorkflowTaskClaimed` after a candidate claims a task
- `WorkflowTaskReleased` after an assignee releases a task
- `WorkflowTaskNudged` after an audited reminder commits
- `WorkflowDefinitionActivated` after a version is activated
- `WorkflowDefinitionDeactivated` after a definition is deactivated
- `WorkflowCancelled` after cancellation commits
- `WorkflowDefinitionPublishing` before a definition version is persisted
- `WorkflowDefinitionPublished` after a definition version commits

Listeners for the two pre-mutation events may throw an exception to abort and roll back the operation. Post-commit events are suitable for notifications, webhooks, and queued automation.

## Headless administration integration

The package contains the complete runtime and authoring seams required by a custom administration experience; a dedicated UI package is not required.

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

`WorkflowExtensionRegistry` exposes the registered guards, assignment strategies, and action handlers that an interface may safely offer as dropdown choices. State-level assignment policies, transition guards, and action handlers are part of the canonical import/export schema. `WorkflowDraft` provides the mutable editing layer, while only validated publication crosses into immutable runtime records.

## Custom models

Every package model is replaceable in `config/workflows.php`. Custom models should extend the corresponding package model so its relationships and casts remain available.

## Safety characteristics

- Definitions are validated before persistence.
- Exactly one initial state and at least one final state are required.
- Unknown, duplicate, unreachable, dead-end, non-terminating, and final-state outgoing transitions are rejected.
- Referenced guards, assignment strategies, and action handlers must exist before publication.
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

The stable core focuses on deterministic sequential workflows. Planned extensions include parallel approval tasks, quorum approvals, deadlines, escalations, and scheduled automation.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

See [SECURITY.md](SECURITY.md) for responsible disclosure guidance.

## License

Laravel Workflows is released under the MIT License.
