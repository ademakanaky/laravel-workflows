# Release Checklist

## Before 1.0.0

- Confirm all documented APIs have tests.
- Run the Laravel 11, 12, and 13 compatibility matrix.
- Run SQLite, MySQL, and PostgreSQL suites.
- Run `composer analyse` at the configured level with no baseline.
- Run Pint in check mode.
- Run `composer validate --strict` and `composer audit`.
- Install the package into a clean Laravel application from a local Composer repository.
- Verify automatic package discovery, migrations, published configuration, and both Artisan commands.
- Verify definition export/import round trips.
- Review migration index lengths against MySQL utf8mb4 limits.
- Review every event's transaction timing.
- Confirm README examples execute without modification.
- Review the changelog, security policy, and stable API document.

## Release procedure

1. Move unreleased changelog entries into a dated version section.
2. Run the complete quality suite.
3. Commit with a clean working tree.
4. Create a signed `v1.0.0` tag.
5. Push the branch and tag to the public repository.
6. Confirm CI succeeds on the tag.
7. Register or refresh the package on Packagist.
8. Install the tagged release into a fresh Laravel application.

No tag or public release should be created from a tree with ignored local changes that affect dependency resolution.
