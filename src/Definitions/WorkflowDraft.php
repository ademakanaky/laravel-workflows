<?php

namespace Ademakanaky\LaravelWorkflows\Definitions;

use Ademakanaky\LaravelWorkflows\Exceptions\DefinitionValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class WorkflowDraft
{
    /** @var array<string, mixed> */
    private array $definition;

    /** @param array<string, mixed> $definition */
    private function __construct(public readonly string $slug, array $definition)
    {
        $this->definition = $definition;
    }

    public static function make(string $slug): self
    {
        return new self($slug, [
            'slug' => $slug,
            'name' => Str::headline($slug),
            'description' => null,
            'metadata' => [],
            'states' => [],
            'transitions' => [],
        ]);
    }

    public static function fromBlueprint(WorkflowBlueprint $blueprint): self
    {
        return new self($blueprint->slug, $blueprint->toArray());
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $slug = $definition['slug'] ?? null;
        if (! is_string($slug) || trim($slug) === '') {
            throw new DefinitionValidationException(['A workflow draft requires a non-empty slug.']);
        }

        return new self($slug, array_merge(self::make($slug)->toArray(), $definition));
    }

    public function name(string $name): self
    {
        $this->definition['name'] = $name;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->definition['description'] = $description;

        return $this;
    }

    /** @param array<string, mixed> $metadata */
    public function metadata(array $metadata): self
    {
        $this->definition['metadata'] = $metadata;

        return $this;
    }

    /** @param array<string, mixed> $state */
    public function putState(string $key, array $state = []): self
    {
        $states = is_array($this->definition['states'] ?? null) ? $this->definition['states'] : [];
        $states[$key] = array_merge([
            'name' => Str::headline($key),
            'description' => null,
            'initial' => false,
            'final' => false,
            'assignment_strategy' => null,
            'candidates' => [],
            'metadata' => [],
        ], $state);
        $this->definition['states'] = $states;

        return $this;
    }

    public function removeState(string $key): self
    {
        $states = is_array($this->definition['states'] ?? null) ? $this->definition['states'] : [];
        unset($states[$key]);
        $this->definition['states'] = $states;
        $transitions = is_array($this->definition['transitions'] ?? null) ? $this->definition['transitions'] : [];
        $this->definition['transitions'] = array_values(array_filter(
            $transitions,
            fn ($transition): bool => ! is_array($transition) || (($transition['from'] ?? null) !== $key && ($transition['to'] ?? null) !== $key)
        ));

        return $this;
    }

    /** @param array<string, mixed> $transition */
    public function putTransition(string $action, string $from, string $to, array $transition = []): self
    {
        $this->removeTransition($from, $action);
        $transitions = is_array($this->definition['transitions'] ?? null) ? $this->definition['transitions'] : [];
        $transitions[] = array_merge([
            'action' => $action,
            'name' => Str::headline($action),
            'from' => $from,
            'to' => $to,
            'guards' => [],
            'handlers' => [],
            'after_commit_handlers' => [],
            'metadata' => [],
        ], $transition);
        $this->definition['transitions'] = $transitions;

        return $this;
    }

    public function removeTransition(string $from, string $action): self
    {
        $transitions = is_array($this->definition['transitions'] ?? null) ? $this->definition['transitions'] : [];
        $this->definition['transitions'] = array_values(array_filter(
            $transitions,
            fn ($transition): bool => ! is_array($transition) || ($transition['from'] ?? null) !== $from || ($transition['action'] ?? null) !== $action
        ));

        return $this;
    }

    /** @param array<int, Model|array{type: string, id: string|int}> $candidates */
    public function candidates(string $state, array $candidates): self
    {
        return $this->updateState($state, function (array $definition) use ($candidates): array {
            $definition['candidates'] = array_map(function (Model|array $candidate): array {
                return $candidate instanceof Model
                    ? ['type' => $candidate->getMorphClass(), 'id' => (string) $candidate->getKey()]
                    : $candidate;
            }, $candidates);

            return $definition;
        });
    }

    public function assignmentStrategy(string $state, ?string $strategy): self
    {
        return $this->updateState($state, function (array $definition) use ($strategy): array {
            $definition['assignment_strategy'] = $strategy;

            return $definition;
        });
    }

    public function outcome(string $state, string $outcome): self
    {
        return $this->updateState($state, function (array $definition) use ($outcome): array {
            $metadata = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
            $metadata['outcome'] = $outcome;
            $definition['metadata'] = $metadata;

            return $definition;
        });
    }

    /**
     * @param  list<string>  $handlers
     * @param  list<string>  $afterCommitHandlers
     */
    public function handlers(string $from, string $action, array $handlers = [], array $afterCommitHandlers = []): self
    {
        return $this->updateTransition($from, $action, function (array $transition) use ($handlers, $afterCommitHandlers): array {
            $transition['handlers'] = $handlers;
            $transition['after_commit_handlers'] = $afterCommitHandlers;

            return $transition;
        });
    }

    /** @param list<string> $guards */
    public function guards(string $from, string $action, array $guards): self
    {
        return $this->updateTransition($from, $action, function (array $transition) use ($guards): array {
            $transition['guards'] = $guards;

            return $transition;
        });
    }

    public function blueprint(): WorkflowBlueprint
    {
        return WorkflowBlueprint::fromArray($this->slug, $this->definition);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->definition;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $callback */
    private function updateState(string $state, callable $callback): self
    {
        $states = is_array($this->definition['states'] ?? null) ? $this->definition['states'] : [];
        if (! isset($states[$state]) || ! is_array($states[$state])) {
            throw new DefinitionValidationException(["Workflow draft does not contain state [{$state}]."]);
        }
        $states[$state] = $callback($states[$state]);
        $this->definition['states'] = $states;

        return $this;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $callback */
    private function updateTransition(string $from, string $action, callable $callback): self
    {
        $transitions = is_array($this->definition['transitions'] ?? null) ? $this->definition['transitions'] : [];
        foreach ($transitions as $index => $transition) {
            if (is_array($transition) && ($transition['from'] ?? null) === $from && ($transition['action'] ?? null) === $action) {
                $transitions[$index] = $callback($transition);
                $this->definition['transitions'] = array_values($transitions);

                return $this;
            }
        }

        throw new DefinitionValidationException(["Workflow draft does not contain transition [{$from}::{$action}]."]);
    }
}
