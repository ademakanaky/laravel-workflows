# Stable API

This document defines the compatibility surface intended for Laravel Workflows 1.x.

## Public services

- `WorkflowManager`
- `DefinitionPublisher`
- `DefinitionValidator`
- `WorkflowDefinitionExporter`
- `WorkflowExtensionRegistry`
- `WorkflowInbox`
- `WorkflowAdministration`
- `WorkflowProcessInspector`
- `WorkflowDashboard`
- The `Workflow` facade
- The `WorkflowAdmin` facade

The method names, required arguments, return types, and documented behavior of these services follow Semantic Versioning after 1.0. New optional arguments and new methods may be added in minor releases.

## Public definition format

The canonical array returned by `WorkflowBlueprint::toArray()` and `WorkflowDefinitionExporter::export()` is a public interchange format. It is the boundary used by configuration, import/export, drafts, and application-owned administration interfaces.

State records in that format may include `assignment_strategy`, containing a registered alias or an implementation class. Transition records may include `guards`, `handlers`, and `after_commit_handlers` lists. These extension types are resolved and validated before publication. Final states may contain a non-empty `metadata.outcome` value.

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
- `WorkflowTaskNotifier`
- `WorkflowParticipantResolver`
- `WorkflowActionHandler`

Contracts will not receive new required methods during 1.x.

Transactional action handlers execute before workflow state mutation and participate in the transition transaction. After-commit handlers run only after the successful outer transaction commits. These execution boundaries are part of the 1.x compatibility promise.

## Public models and traits

Applications may extend the configured package models. Public relationships, casts, status enums, `HasWorkflows`, and `ParticipatesInWorkflows` are covered by the 1.x compatibility promise.

`WorkflowTask` query scopes `open()`, `assignedTo()`, and `overdue()` are public. The facade methods `inbox()` and `pendingCount()` and the corresponding `WorkflowInbox` service provide the supported pending-work query boundary for application and administration interfaces.

The administration API includes mutable serializable `WorkflowDraft` instances, draft validation and publication, definition/version listing, database-managed publication, candidate configuration through new immutable versions, version activation and definition deactivation, process inspection, process/task query scopes, claim/release/reassignment, audited nudges, and dashboard summaries. Administration clients must use these services instead of mutating published records.

Published definition records and transition logs are immutable. Database-authored tools must publish through `DefinitionPublisher` rather than modifying these models.

## Events

All event class names and constructor properties are public. Events documented as post-commit will continue to be emitted only after a successful outer transaction commits.

Task lifecycle events include `WorkflowTaskOpened`, `WorkflowTaskAssigned`, `WorkflowTaskCompleted`, and `WorkflowTaskCancelled`. The configured `WorkflowTaskNotifier` is invoked at the same post-commit boundary.

`WorkflowTaskClaimed`, `WorkflowTaskReleased`, `WorkflowTaskNudged`, `WorkflowDefinitionActivated`, and `WorkflowDefinitionDeactivated` are also public post-commit events.

`WorkflowOutcomeReached` is a public post-commit event emitted when a workflow enters a final state containing outcome metadata.

## Database compatibility

The supported database targets are SQLite, MySQL, and PostgreSQL. Polymorphic IDs are stored as strings so integer, UUID, and ULID Eloquent keys are accepted.

## Outside the 1.0 scope

The following are deliberately not promised by the initial stable core:

- Parallel or quorum approval
- Timers, SLA escalation, or scheduled transitions
- A bundled HTTP API
- A bundled visual administration interface (applications build one over the headless administration API)
- Arbitrary mutation of published definitions
- Non-Eloquent subjects or actors

These features may arrive as backward-compatible core extensions or separate packages.
