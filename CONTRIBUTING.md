# Contributing

Thanks for your interest in FirstTouch SLA.

This repository is primarily a **portfolio / reference implementation**. Contributions are welcome for clarity, tests, and hardening — keep changes focused.

## Branching model (Git Flow)

```text
feature/<area>-<short-desc>  →  develop  →  main (release tag)
```

| Branch | Purpose |
|--------|---------|
| `main` | Production-ready releases only |
| `develop` | Integration branch for merged features |
| `feature/*` | One capability or fix per branch |

### Conventional branch names

- `feature/ui-…`, `feature/sla-…`, `feature/telegram-…`, `feature/n8n-…`
- `feature/ai-…`, `feature/webhook-…`, `feature/billing-…`, `feature/team-…`, `feature/api-…`
- `fix/…`, `docs/…`, `chore/…`, `ci/…`

### Conventional Commits

Follow [Conventional Commits](https://www.conventionalcommits.org/):

- `feat(scope): …` — new capability
- `fix(scope): …` — bug fix
- `docs(scope): …` — documentation only
- `chore(scope): …` — tooling / repo hygiene
- `ci(scope): …` — pipeline
- `test(scope): …` — tests
- `refactor(scope): …` — no behaviour change

## Local workflow

1. Fork & clone
2. Boot with Sail (see root `README.md`)
3. Branch from **`develop`**: `git checkout develop && git pull && git checkout -b feature/…`
4. Open a PR **into `develop`** (not directly into `main`)
5. After review, merge with a **merge commit** (no squash for feature history)
6. Releases: PR `develop` → `main`, then annotated tag `vX.Y.Z`

### Quality gates before opening a PR

```bash
./vendor/bin/sail test --parallel
./vendor/bin/sail exec laravel.test ./vendor/bin/phpstan analyse --memory-limit=1G
```

## Expectations

- Keep `declare(strict_types=1);` on PHP files
- Prefer enums, DTOs, and services over fat controllers / Filament closures
- Do not commit `.env`, secrets, `vendor/`, `node_modules/`, `composer.phar`, or `docs/screenshots/`
- Add or update Pest tests for behaviour changes

## Capability docs

See [`docs/features/`](docs/features/) for per-capability code maps used by reviewers.
