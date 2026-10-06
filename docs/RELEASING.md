# Release Checklist

## Before 1.0.0

- Confirm all documented APIs have tests.
- Run the Laravel 9, 10, 11, 12, and 13 compatibility matrix.
- Run SQLite, MySQL, and PostgreSQL suites.
- Run `composer analyse` at the configured level with no baseline.
- Run Pint in check mode.
- Run `composer validate --strict` and `composer audit`.
- Run the clean Laravel application CI job, which installs the package from a local Composer repository.
- Verify automatic package discovery, migrations, published configuration, and both Artisan commands.
- Verify definition export/import round trips.
- Review migration index lengths against MySQL utf8mb4 limits.
- Review every event's transaction timing.
- Confirm README examples execute without modification.
- Review the changelog, security policy, and stable API document.

## Release procedure

1. Move unreleased changelog entries into a dated release-candidate section.
2. Run the complete quality suite.
3. Commit with a clean working tree.
4. Create and push a signed `v1.0.0-rc.1` tag.
5. Confirm CI succeeds on the tag and install the tagged release into a fresh Laravel application.
6. Register or refresh the package on Packagist and exercise the release candidate through Composer.
7. Resolve any release-blocking defects without changing the documented stable API unnecessarily.
8. Move the changelog entries into a dated `1.0.0` section and remove the pre-stable warning from `README.md`.
9. Create and push a signed `v1.0.0` tag.
10. Confirm CI succeeds on the stable tag and install the tagged release into a fresh Laravel application.

No tag or public release should be created from a tree with ignored local changes that affect dependency resolution.
