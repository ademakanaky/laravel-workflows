<?php

namespace Ademakanaky\LaravelWorkflows\Tests\Unit;

use Ademakanaky\LaravelWorkflows\Definitions\WorkflowBlueprint;
use Ademakanaky\LaravelWorkflows\Exceptions\DefinitionValidationException;
use PHPUnit\Framework\TestCase;

class WorkflowBlueprintTest extends TestCase
{
    public function test_it_requires_exactly_one_initial_state(): void
    {
        $this->expectException(DefinitionValidationException::class);

        WorkflowBlueprint::make('approval')
            ->state('draft')
            ->state('approved', final: true)
            ->validate();
    }

    public function test_it_rejects_unknown_transition_states(): void
    {
        $this->expectException(DefinitionValidationException::class);

        WorkflowBlueprint::make('approval')
            ->state('draft', initial: true)
            ->transition('submit', 'draft', 'missing')
            ->validate();
    }

    public function test_it_rejects_duplicate_actions_from_the_same_state(): void
    {
        $this->expectException(DefinitionValidationException::class);

        WorkflowBlueprint::make('approval')
            ->state('draft', initial: true)
            ->state('approved', final: true)
            ->state('rejected', final: true)
            ->transition('decide', 'draft', 'approved')
            ->transition('decide', 'draft', 'rejected')
            ->validate();
    }

    public function test_checksum_is_stable_regardless_of_state_insertion_order(): void
    {
        $first = WorkflowBlueprint::make('approval')
            ->state('draft', initial: true)
            ->state('approved', final: true)
            ->transition('approve', 'draft', 'approved');

        $second = WorkflowBlueprint::make('approval')
            ->state('approved', final: true)
            ->state('draft', initial: true)
            ->transition('approve', 'draft', 'approved');

        $this->assertSame($first->checksum(), $second->checksum());
    }

    public function test_it_rejects_unreachable_states(): void
    {
        $this->expectException(DefinitionValidationException::class);

        WorkflowBlueprint::make('approval')
            ->state('draft', initial: true)
            ->state('approved', final: true)
            ->state('orphan')
            ->transition('approve', 'draft', 'approved')
            ->validate();
    }

    public function test_array_definitions_report_malformed_admin_payloads_as_validation_errors(): void
    {
        try {
            WorkflowBlueprint::fromArray('approval', [
                'name' => ['not-a-string'],
                'states' => 'not-an-array',
                'transitions' => 'not-an-array',
            ]);
            $this->fail('Malformed payloads must be rejected.');
        } catch (DefinitionValidationException $exception) {
            $this->assertContains('The workflow name must be a string.', $exception->errors);
            $this->assertContains('The workflow states must be an array.', $exception->errors);
            $this->assertContains('The workflow transitions must be an array.', $exception->errors);
        }
    }

    public function test_array_definitions_report_missing_transition_fields_without_php_errors(): void
    {
        try {
            WorkflowBlueprint::fromArray('approval', [
                'states' => [
                    ['initial' => true],
                    'invalid-state',
                ],
                'transitions' => [
                    ['action' => 'submit', 'from' => 'draft'],
                    'invalid-transition',
                ],
            ]);
            $this->fail('Malformed payloads must be rejected.');
        } catch (DefinitionValidationException $exception) {
            $this->assertCount(4, $exception->errors);
            $this->assertStringContainsString('non-empty string key', $exception->getMessage());
            $this->assertStringContainsString('non-empty string [to]', $exception->getMessage());
        }
    }

    public function test_every_non_final_state_must_lead_to_a_final_state(): void
    {
        try {
            WorkflowBlueprint::make('approval')
                ->state('draft', initial: true)
                ->state('loop-a')
                ->state('loop-b')
                ->state('approved', final: true)
                ->transition('enter-loop', 'draft', 'loop-a')
                ->transition('next', 'loop-a', 'loop-b')
                ->transition('again', 'loop-b', 'loop-a')
                ->validate();
            $this->fail('A closed cycle must be rejected.');
        } catch (DefinitionValidationException $exception) {
            $this->assertStringContainsString('has no path to a final state', $exception->getMessage());
        }
    }

    public function test_identifiers_must_be_safe_for_routes_and_configuration(): void
    {
        $this->expectException(DefinitionValidationException::class);

        WorkflowBlueprint::make('approval flow')
            ->state('needs review', initial: true)
            ->state('approved', final: true)
            ->transition('approve now', 'needs review', 'approved')
            ->validate();
    }
}
