# Contributing

Thank you for considering a contribution to Laravel Workflows.

## Development setup

```bash
git clone https://github.com/ademakanaky/laravel-workflows.git
cd laravel-workflows
composer install
composer test
```

## Pull requests

1. Open an issue for material API or schema changes before implementation.
2. Keep public APIs backward compatible unless the change targets a documented major release.
3. Add tests for fixes and new behavior.
4. Run `composer test` and `composer format` before submitting.
5. Update the README and changelog when user-facing behavior changes.

Never commit credentials, production data, Composer's `vendor` directory, or generated test caches.
