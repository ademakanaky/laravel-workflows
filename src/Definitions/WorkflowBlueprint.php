<?php

namespace Ademakanaky\LaravelWorkflows\Definitions;

use Ademakanaky\LaravelWorkflows\Exceptions\DefinitionValidationException;
use Illuminate\Support\Str;

/**
 * @phpstan-type StateDefinition array{key: string, name: string, initial: bool, final: bool, description: string|null, metadata: array<string, mixed>, assignment_strategy: string|null}
 * @phpstan-type TransitionDefinition array{action: string, from: string, to: string, name: string, guards: list<string>, metadata: array<string, mixed>}
 */
final class WorkflowBlueprint
{
    /** @var array<string, StateDefinition> */
    private array $states = [];

    /** @var list<TransitionDefinition> */
    private array $transitions = [];

    private string $name;

    private ?string $description = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    private function __construct(public readonly string $slug)
    {
        $this->name = Str::headline($slug);
    }

    public static function make(string $slug): self
    {
        return new self($slug);
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(string $slug, array $definition): self
    {
        $errors = [];
        $name = $definition['name'] ?? Str::headline($slug);
        $description = $definition['description'] ?? null;
        $metadata = $definition['metadata'] ?? [];
        $states = $definition['states'] ?? [];
        $transitions = $definition['transitions'] ?? [];

        if (! is_string($name)) {
            $errors[] = 'The workflow name must be a string.';
        }
        if ($description !== null && ! is_string($description)) {
            $errors[] = 'The workflow description must be a string or null.';
        }
        if (! is_array($metadata)) {
            $errors[] = 'The workflow metadata must be an array.';
        }
        if (! is_array($states)) {
            $errors[] = 'The workflow states must be an array.';
        }
        if (! is_array($transitions)) {
            $errors[] = 'The workflow transitions must be an array.';
        }

        if ($errors !== []) {
            throw new DefinitionValidationException($errors);
        }

        $blueprint = self::make($slug)
            ->name($name)
            ->description($description)
            ->metadata($metadata);

        foreach ($states as $key => $state) {
            if (! is_array($state)) {
                $errors[] = "State [{$key}] must be an array.";

                continue;
            }

            $stateKey = is_string($key) ? $key : ($state['key'] ?? null);
            if (! is_string($stateKey) || $stateKey === '') {
                $errors[] = "State at index [{$key}] must contain a non-empty string key.";

                continue;
            }
            if (isset($state['name']) && ! is_string($state['name'])) {
                $errors[] = "State [{$stateKey}] name must be a string.";

                continue;
            }
            if (isset($state['description']) && ! is_string($state['description'])) {
                $errors[] = "State [{$stateKey}] description must be a string or null.";

                continue;
            }
            if (isset($state['metadata']) && ! is_array($state['metadata'])) {
                $errors[] = "State [{$stateKey}] metadata must be an array.";

                continue;
            }
            if (isset($state['assignment_strategy']) && (! is_string($state['assignment_strategy']) || $state['assignment_strategy'] === '')) {
                $errors[] = "State [{$stateKey}] assignment strategy must be a non-empty string or null.";

                continue;
            }
            if (isset($state['initial']) && ! is_bool($state['initial'])) {
                $errors[] = "State [{$stateKey}] initial flag must be a boolean.";

                continue;
            }
            if (isset($state['final']) && ! is_bool($state['final'])) {
                $errors[] = "State [{$stateKey}] final flag must be a boolean.";

                continue;
            }

            $blueprint->state(
                key: $stateKey,
                name: $state['name'] ?? null,
                initial: (bool) ($state['initial'] ?? false),
                final: (bool) ($state['final'] ?? false),
                description: $state['description'] ?? null,
                assignmentStrategy: $state['assignment_strategy'] ?? null,
                metadata: $state['metadata'] ?? [],
            );
        }

        foreach ($transitions as $index => $transition) {
            if (! is_array($transition)) {
                $errors[] = "Transition at index [{$index}] must be an array.";

                continue;
            }

            $transitionIsInvalid = false;
            foreach (['action', 'from', 'to'] as $field) {
                if (! isset($transition[$field]) || ! is_string($transition[$field]) || $transition[$field] === '') {
                    $errors[] = "Transition at index [{$index}] must contain a non-empty string [{$field}].";
                    $transitionIsInvalid = true;
                }
            }
            if (isset($transition['name']) && ! is_string($transition['name'])) {
                $errors[] = "Transition at index [{$index}] name must be a string.";
                $transitionIsInvalid = true;
            }
            if (isset($transition['guards']) && ! is_array($transition['guards'])) {
                $errors[] = "Transition at index [{$index}] guards must be an array.";
                $transitionIsInvalid = true;
            }
            if (isset($transition['metadata']) && ! is_array($transition['metadata'])) {
                $errors[] = "Transition at index [{$index}] metadata must be an array.";
                $transitionIsInvalid = true;
            }
            if ($transitionIsInvalid) {
                continue;
            }

            $blueprint->transition(
                action: $transition['action'],
                from: $transition['from'],
                to: $transition['to'],
                name: $transition['name'] ?? null,
                guards: $transition['guards'] ?? [],
                metadata: $transition['metadata'] ?? [],
            );
        }

        if ($errors !== []) {
            throw new DefinitionValidationException($errors);
        }

        return $blueprint;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** @param array<string, mixed> $metadata */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /** @param array<string, mixed> $metadata */
    public function state(
        string $key,
        ?string $name = null,
        bool $initial = false,
        bool $final = false,
        ?string $description = null,
        array $metadata = [],
        ?string $assignmentStrategy = null,
    ): self {
        $this->states[$key] = [
            'key' => $key,
            'name' => $name ?? Str::headline($key),
            'initial' => $initial,
            'final' => $final,
            'description' => $description,
            'metadata' => $metadata,
            'assignment_strategy' => $assignmentStrategy,
        ];

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $guards
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        string $action,
        string $from,
        string $to,
        ?string $name = null,
        array $guards = [],
        array $metadata = [],
    ): self {
        $normalizedGuards = [];
        foreach ($guards as $guard) {
            if (! is_string($guard) || trim($guard) === '') {
                throw new DefinitionValidationException([
                    "Transition [{$action}] guards must be non-empty strings.",
                ]);
            }
            $normalizedGuards[] = $guard;
        }

        $this->transitions[] = [
            'action' => $action,
            'from' => $from,
            'to' => $to,
            'name' => $name ?? Str::headline($action),
            'guards' => $normalizedGuards,
            'metadata' => $metadata,
        ];

        return $this;
    }

    public function validate(): self
    {
        $errors = [];
        $initialStates = array_filter($this->states, fn (array $state): bool => $state['initial']);

        if ($this->slug === '') {
            $errors[] = 'A workflow slug is required.';
        } elseif (! $this->isValidIdentifier($this->slug)) {
            $errors[] = "Workflow slug [{$this->slug}] contains unsupported characters.";
        }

        if (trim($this->name) === '') {
            $errors[] = "Workflow [{$this->slug}] must have a name.";
        }

        if ($this->states === []) {
            $errors[] = "Workflow [{$this->slug}] must contain at least one state.";
        }

        if (count($initialStates) !== 1) {
            $errors[] = "Workflow [{$this->slug}] must contain exactly one initial state.";
        }

        if (count(array_filter($this->states, fn (array $state): bool => $state['final'])) === 0) {
            $errors[] = "Workflow [{$this->slug}] must contain at least one final state.";
        }

        $outgoing = [];
        $reverse = [];
        $seenTransitions = [];
        foreach ($this->states as $stateKey => $state) {
            if (! $this->isValidIdentifier($stateKey)) {
                $errors[] = "State key [{$stateKey}] contains unsupported characters.";
            }
            if (trim($state['name']) === '') {
                $errors[] = "State [{$stateKey}] must have a name.";
            }
        }

        foreach ($this->transitions as $transition) {
            if (! $this->isValidIdentifier($transition['action'])) {
                $errors[] = "Transition action [{$transition['action']}] contains unsupported characters.";
            }
            if (trim($transition['name']) === '') {
                $errors[] = "Transition [{$transition['action']}] must have a name.";
            }
            if (! isset($this->states[$transition['from']])) {
                $errors[] = "Transition [{$transition['action']}] references unknown state [{$transition['from']}].";
            }
            if (! isset($this->states[$transition['to']])) {
                $errors[] = "Transition [{$transition['action']}] references unknown state [{$transition['to']}].";
            }
            if (($this->states[$transition['from']]['final'] ?? false) === true) {
                $errors[] = "Final state [{$transition['from']}] cannot have outgoing transitions.";
            }

            $key = $transition['from'].'::'.$transition['action'];
            if (isset($seenTransitions[$key])) {
                $errors[] = "State [{$transition['from']}] contains duplicate action [{$transition['action']}].";
            }
            $seenTransitions[$key] = true;

            if (isset($this->states[$transition['from']], $this->states[$transition['to']])) {
                $outgoing[$transition['from']] = true;
                $reverse[$transition['to']][] = $transition['from'];
            }
        }

        foreach ($this->states as $stateKey => $state) {
            if (! $state['final'] && ! isset($outgoing[$stateKey])) {
                $errors[] = "Non-final state [{$stateKey}] must have at least one outgoing transition.";
            }
        }

        if (count($initialStates) === 1) {
            $initialKey = array_key_first($initialStates);
            $reachable = [$initialKey => true];
            $queue = [$initialKey];

            while ($queue !== []) {
                $from = array_shift($queue);
                foreach ($this->transitions as $transition) {
                    if ($transition['from'] !== $from || ! isset($this->states[$transition['to']], $this->states[$transition['from']])) {
                        continue;
                    }
                    if (! isset($reachable[$transition['to']])) {
                        $reachable[$transition['to']] = true;
                        $queue[] = $transition['to'];
                    }
                }
            }

            foreach (array_keys($this->states) as $stateKey) {
                if (! isset($reachable[$stateKey])) {
                    $errors[] = "State [{$stateKey}] is unreachable from the initial state.";
                }
            }
        }

        $canReachFinal = [];
        $queue = [];
        foreach ($this->states as $stateKey => $state) {
            if ($state['final']) {
                $canReachFinal[$stateKey] = true;
                $queue[] = $stateKey;
            }
        }
        while ($queue !== []) {
            $to = array_shift($queue);
            foreach ($reverse[$to] ?? [] as $from) {
                if (! isset($canReachFinal[$from])) {
                    $canReachFinal[$from] = true;
                    $queue[] = $from;
                }
            }
        }
        foreach (array_keys($this->states) as $stateKey) {
            if (! isset($canReachFinal[$stateKey])) {
                $errors[] = "State [{$stateKey}] has no path to a final state.";
            }
        }

        if ($errors !== []) {
            throw new DefinitionValidationException($errors);
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $states = $this->states;
        ksort($states);
        $transitions = $this->transitions;
        usort($transitions, fn (array $left, array $right): int => [$left['from'], $left['action']] <=> [$right['from'], $right['action']]);

        return $this->canonicalize([
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'states' => $states,
            'transitions' => $transitions,
        ]);
    }

    public function checksum(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function isValidIdentifier(string $value): bool
    {
        return strlen($value) <= 255
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1;
    }
}
