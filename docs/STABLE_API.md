# Stable API

This document defines the compatibility surface intended for Laravel Workflows 1.x.

## Public services

- `WorkflowManager`
- `DefinitionPublisher`
- `DefinitionValidator`
- `WorkflowDefinitionExporter`
- `WorkflowExtensionRegistry`
- The `Workflow` facade

The method names, required arguments, return types, and documented behavior of these services follow Semantic Versioning after 1.0. New optional arguments and new methods may be added in minor releases.

## Public definition format

The canonical array returned by `WorkflowBlueprint::toArray()` and `WorkflowDefinitionExporter::export()` is a public interchange format. It is the boundary used by configuration, import/export, and the future administration package.

State records in that format may include `assignment_strategy`, containing a registered alias or an implementation class. Transition records may include a `guards` list. Both extension types are resolved and validated before publication.

Required concepts are:

- A unique definition slug
- Exactly one initial state
- At least one reachable final state
- State keys and metadata
- Action-labelled transitions
- Guard aliases or classes
- Transition metadata

New optional keys may be introduced in minor releases. Existing keys will not change meaning during 1.x.

## Public extension contracts

- `AssignmentStrategy`
- `TransitionAuthorizer`
- `TransitionGuard`
- `DefinitionPublisher`
- `DefinitionValidator`

Contracts will not receive new required methods during 1.x.

## Public models and traits

Applications may extend the configured package models. Public relationships, casts, status enums, `HasWorkflows`, and `ParticipatesInWorkflows` are covered by the 1.x compatibility promise.

Published definition records and transition logs are immutable. Database-authored tools must publish through `DefinitionPublisher` rather than modifying these models.

## Events

All event class names and constructor properties are public. Events documented as post-commit will continue to be emitted only after a successful outer transaction commits.

## Database compatibility

The supported database targets are SQLite, MySQL, and PostgreSQL. Polymorphic IDs are stored as strings so integer, UUID, and ULID Eloquent keys are accepted.

## Outside the 1.0 scope

The following are deliberately not promised by the initial stable core:

- Parallel or quorum approval
- Timers, SLA escalation, or scheduled transitions
- A bundled HTTP API
- A bundled visual administration interface
- Arbitrary mutation of published definitions
- Non-Eloquent subjects or actors

These features may arrive as backward-compatible core extensions or separate packages.
