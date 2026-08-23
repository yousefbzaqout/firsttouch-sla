# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-08-23

### Added

- Multi-tenant lead distribution with Filament v5 admin / sales console
- Business-hours SLA clock, breach sweep, and escalation buffers
- Multi-channel signed webhooks (Meta, TikTok, Google, Snap, website, universal)
- AI RAG knowledge bases (PostgreSQL + pgvector) with confidence gate + credits
- Telegram ops alerts with claim / status callbacks
- n8n notification driver for custom automations
- Billing & AI credit ledger (mock Stripe path locally)
- Team RBAC (owner vs sales) and Developer API (Sanctum)
- Public marketing gallery (`docs/marketing/`) and CI (Pest parallel + PHPStan Level 8)

### Security

- Fail-closed webhook secret verification
- Tenant isolation via global scopes

[1.0.0]: https://github.com/yousefbzaqout/firsttouch-sla/releases/tag/v1.0.0
