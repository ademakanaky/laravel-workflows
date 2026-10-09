<?php

namespace Ademakanaky\LaravelWorkflows;

use Ademakanaky\LaravelWorkflows\Data\WorkflowDashboardSummary;
use Ademakanaky\LaravelWorkflows\Data\WorkflowProcessSnapshot;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Definitions\WorkflowDraft;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowDefinitionSource;
use Ademakanaky\LaravelWorkflows\Enums\WorkflowTaskStatus;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionActivated;
use Ademakanaky\LaravelWorkflows\Events\WorkflowDefinitionDeactivated;
use Ademakanaky\LaravelWorkflows\Exceptions\WorkflowException;
use Ademakanaky\LaravelWorkflows\Models\WorkflowDefinition;
use Ademakanaky\LaravelWorkflows\Models\WorkflowInstance;
use Ademakanaky\LaravelWorkflows\Models\WorkflowTask;
use Ademakanaky\LaravelWorkflows\Models\WorkflowVersion;
use Ademakanaky\LaravelWorkflows\Support\WorkflowModelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WorkflowAdministration
{
    public function __construct(
        private readonly DefinitionSynchronizer $definitions,
        private readonly WorkflowDefinitionValidator $validator,
        private readonly WorkflowDefinitionExporter $exporter,
        private readonly WorkflowManager $workflows,
        private readonly WorkflowProcessInspector $inspector,
        private readonly WorkflowDashboard $dashboard,
    ) {}

    /** @return Builder<WorkflowDefinition> */
    public function definitions(): Builder
    {
        $class = WorkflowModelRegistry::definition();

        return $class::query()->with('activeVersion')->orderBy('name');
    }

    public function definition(string $slug): WorkflowDefinition
    {
        return $this->definitions()->where('slug', $slug)->firstOrFail();
    }

    /** @return Builder<WorkflowVersion> */
    public function versions(string $slug): Builder
    {
        $definition = $this->definition($slug);
        $class = WorkflowModelRegistry::version();

        return $class::query()->where('workflow_definition_id', $definition->getKey())->with('states.candidates')->latest('version');
    }

    public function validate(WorkflowBlueprint $blueprint): WorkflowBlueprint
    {
        return $this->validator->validate($blueprint);
    }

    public function publish(WorkflowBlueprint $blueprint): WorkflowVersion
    {
        return $this->definitions->publish($blueprint, WorkflowDefinitionSource::Database);
    }

    public function draft(string $slug): WorkflowDraft
    {
        $class = WorkflowModelRegistry::definition();
        $definition = $class::query()->where('slug', $slug)->first();
        if (! $definition) {
            return WorkflowDraft::make($slug);
        }
        $version = $definition->activeVersion ?? $definition->latestVersion;

        return $version
            ? WorkflowDraft::fromBlueprint($this->exporter->blueprint($version))
            : WorkflowDraft::make($slug);
    }

    public function validateDraft(WorkflowDraft $draft): WorkflowDraft
    {
        $this->validator->validate($draft->blueprint());

        return $draft;
    }

    public function publishDraft(WorkflowDraft $draft): WorkflowVersion
    {
        return $this->publish($this->validateDraft($draft)->blueprint());
    }

    /** @return array<string, mixed> */
    public function export(string $slug, ?int $version = null): array
    {
        $definition = $this->definition($slug);
        $published = $version === null
            ? ($definition->activeVersion ?? $definition->latestVersion)
            : $definition->versions()->where('version', $version)->first();
        if (! $published) {
            throw new WorkflowException("Workflow [{$slug}] has no matching published version.");
        }

        return $this->exporter->export($published);
    }

    /** @param array<int, Model> $candidates */
    public function configureStepCandidates(string $slug, string $state, array $candidates): WorkflowVersion
    {
        $definition = $this->definition($slug);
        $version = $definition->activeVersion ?? $definition->latestVersion;
        if (! $version) {
            throw new WorkflowException("Workflow [{$slug}] has no published version.");
        }

        return $this->publish($this->exporter->blueprint($version)->candidates($state, $candidates));
    }

    public function activate(WorkflowVersion|int $version, ?Model $actor = null): WorkflowVersion
    {
        $id = $version instanceof WorkflowVersion ? $version->getKey() : $version;

        return DB::transaction(function () use ($id, $actor): WorkflowVersion {
            $versionClass = WorkflowModelRegistry::version();
            $published = $versionClass::query()->whereKey($id)->with('definition')->lockForUpdate()->firstOrFail();
            $published->definition->update(['active_version_id' => $published->getKey(), 'is_active' => true]);
            $result = $published->refresh()->load('definition', 'states.candidates', 'transitions');
            DB::afterCommit(fn () => event(new WorkflowDefinitionActivated($result, $actor)));

            return $result;
        });
    }

    public function deactivate(string $slug, ?Model $actor = null): WorkflowDefinition
    {
        return DB::transaction(function () use ($slug, $actor): WorkflowDefinition {
            $class = WorkflowModelRegistry::definition();
            $definition = $class::query()->where('slug', $slug)->lockForUpdate()->firstOrFail();
            $definition->update(['is_active' => false]);
            $result = $definition->refresh()->load('activeVersion');
            DB::afterCommit(fn () => event(new WorkflowDefinitionDeactivated($result, $actor)));

            return $result;
        });
    }

    /** @return Builder<WorkflowInstance> */
    public function processes(): Builder
    {
        $class = WorkflowModelRegistry::instance();

        return $class::query()->with(['definition', 'currentState', 'subject', 'tasks.assignee'])->latest('created_at');
    }

    /** @return Builder<WorkflowTask> */
    public function tasks(): Builder
    {
        $class = WorkflowModelRegistry::task();

        return $class::query()->with($this->taskRelations())->latest('id');
    }

    public function process(WorkflowInstance|string $instance): WorkflowProcessSnapshot
    {
        return $this->inspector->inspect($instance);
    }

    /** @param array<string, mixed> $data */
    public function reassign(WorkflowTask|int|string $task, ?Model $assignee, ?Model $actor = null, array $data = [], ?string $idempotencyKey = null): WorkflowInstance
    {
        $currentTask = $this->findOpenTask($task);

        $instance = $currentTask->instance;

        return $this->workflows->assign($instance, $assignee, $actor, $data, $idempotencyKey);
    }

    /** @param array<string, mixed> $data */
    public function unassign(WorkflowTask|int|string $task, ?Model $actor = null, array $data = [], ?string $idempotencyKey = null): WorkflowInstance
    {
        return $this->reassign($task, null, $actor, $data, $idempotencyKey);
    }

    /** @param array<string, mixed> $data */
    public function claim(WorkflowTask|int|string $task, Model $actor, array $data = [], ?string $idempotencyKey = null): WorkflowInstance
    {
        return $this->workflows->claim($task, $actor, $data, $idempotencyKey);
    }

    /** @param array<string, mixed> $data */
    public function release(WorkflowTask|int|string $task, Model $actor, array $data = [], ?string $idempotencyKey = null): WorkflowInstance
    {
        return $this->workflows->release($task, $actor, $data, $idempotencyKey);
    }

    /** @param array<string, mixed> $data */
    public function nudge(WorkflowTask|int|string $task, ?Model $actor = null, array $data = [], ?string $idempotencyKey = null): WorkflowInstance
    {
        return $this->workflows->nudge($task, $actor, $data, $idempotencyKey);
    }

    /** @param array<string, mixed> $data */
    public function cancel(WorkflowInstance|string $instance, ?Model $actor = null, array $data = [], ?string $idempotencyKey = null): WorkflowInstance
    {
        return $this->workflows->cancel($instance, $actor, $data, $idempotencyKey);
    }

    public function dashboard(): WorkflowDashboardSummary
    {
        return $this->dashboard->summary();
    }

    private function findOpenTask(WorkflowTask|int|string $task): WorkflowTask
    {
        $id = $task instanceof WorkflowTask ? $task->getKey() : $task;
        $class = WorkflowModelRegistry::task();
        $current = $class::query()->whereKey($id)->with('instance')->firstOrFail();
        $instance = $current->instance;
        if ($current->status !== WorkflowTaskStatus::Open
            || $current->workflow_state_id !== $instance->current_state_id) {
            throw new WorkflowException('Administrative assignment is only available for the current open task.');
        }

        return $current;
    }

    /** @return list<string> */
    private function taskRelations(): array
    {
        return ['assignee', 'candidates.candidate', 'state', 'instance.definition', 'instance.currentState', 'instance.subject'];
    }
}
