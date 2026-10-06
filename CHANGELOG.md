# Changelog

All notable changes to this project will be documented here. The project follows Semantic Versioning.

## Unreleased

### Added

- Versioned workflow definitions and graph validation.
- Polymorphic workflow instances, actors, subjects, and assignees.
- Transaction-safe transitions with row locking and idempotency.
- Assignment and transition-authorization contracts.
- Transition guards, tasks, immutable history, and lifecycle events.
- Laravel auto-discovery, publishable configuration and migrations, Artisan commands, and Testbench coverage.
- UUID/ULID-compatible polymorphic subjects, actors, and assignees.
- Strict idempotency conflict detection and audited cancellation.
- Immutable published records and actor-side relationship traits.
- Definition ownership, publication contracts, canonical export, and extension registries for the future admin package.
- State-level assignment strategy aliases and publish-time extension validation.
- Admin-safe payload validation and graph termination checks.
- Immutable version-level names and descriptions for reliable historical export and diffs.
- A shared non-writing definition validator for CLI and administration interfaces.
- Larastan configuration and SQLite/MySQL/PostgreSQL CI coverage.
- Laravel 9 and 10 compatibility, including dedicated Testbench CI coverage.
- Isolated test schemas for persistent MySQL and PostgreSQL CI databases.
