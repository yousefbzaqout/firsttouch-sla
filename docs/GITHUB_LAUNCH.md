# GitHub publish playbook (FirstTouch SLA)

Practical checklist for **this** repo — OSS / portfolio best practices.

## README formula that converts (30-second scan)

1. **H1 + one-line value** — what problem you solve  
2. **≤5 badges** — CI, language, license, security (clickable)  
3. **Hero visual first** — product proof before architecture essays  
4. **Feature gallery** — one large shot per capability (AI / Telegram / n8n / SLA / billing…)  
5. **Quick start ≤5 commands** — Sail path that actually works  
6. **Deeper docs linked** — architecture, API, security  
7. **No fake claims** — stack versions match `composer.json`

Anti-patterns: 12 badge rows, dark neon-purple mockups, walls of text before a screenshot, committed secrets.

## Push sequence

```bash
git status                    # no .env / vendor / keys
./vendor/bin/sail pint
./vendor/bin/sail test --parallel

git push -u origin main

# GitHub UI / CLI
# About → description + topics
# Actions → confirm CI green
```

## Repo settings that look senior

- Public `main`  
- Topics: `laravel`, `filament`, `multi-tenancy`, `sla`, `webhooks`, `pgvector`, `horizon`, `telegram`, `n8n`  
- Pin the repo; social preview uses the Legion hero when possible  

## Assets layout

```text
docs/marketing/firsttouch-sla-hero-legion.jpg
docs/marketing/feature-01-sla-dashboard.jpg … feature-09-developer-api.jpg
docs/screenshots/01-dashboard.png … 04-billing.png
docs/ARCHITECTURE.md
SECURITY.md
LICENSE
.github/workflows/ci.yml
```
