# Contributing

Thanks for your interest in FirstTouch SLA.

This repository is primarily a **portfolio / reference implementation**. Contributions are welcome for clarity, tests, and hardening — keep changes focused.

## Local workflow

1. Fork & clone
2. Boot with Sail (see root `README.md`)
3. Create a branch: `feat/...` or `fix/...`
4. Run quality gates before opening a PR:

```bash
./vendor/bin/sail test
./vendor/bin/sail exec laravel.test ./vendor/bin/phpstan analyse --memory-limit=1G
```

## Expectations

- Keep `declare(strict_types=1);` on PHP files
- Prefer enums, DTOs, and services over fat controllers / Filament closures
- Do not commit `.env`, secrets, `vendor/`, `node_modules/`, or `composer.phar`
- Add or update Pest tests for behaviour changes

## Pull requests

- Explain **why**, not only what
- Link related issues if any
- Keep diffs reviewable (prefer smaller PRs)
