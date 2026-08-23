# Security Policy

## Supported versions

This repository is a portfolio / reference implementation. Security fixes are applied on a best-effort basis to the `main` branch.

| Branch | Supported |
|--------|-----------|
| `main` | ✅ |

## Reporting a vulnerability

If you discover a security issue, **do not open a public GitHub issue**.

Please email the maintainer privately with:

- A short description of the impact
- Steps to reproduce (PoC if available)
- Affected routes / components if known

You should receive an acknowledgement within a few days. Please allow reasonable time for assessment before any public disclosure.

## Built-in security posture

FirstTouch SLA ships with several deliberate safeguards:

- **Tenant isolation** — Eloquent global scopes (`tenant_id`) on tenant-owned models
- **Role-based Filament access** — sales reps cannot reach admin settings, billing, or team management
- **Webhook HMAC verification** — Meta / TikTok / Google / Snapchat / universal / website drivers fail closed when secrets are missing or invalid
- **Encrypted secrets at rest** — webhook & outbound secrets stored with Laravel encrypted casts
- **Safe enum hydration** — corrupt legacy enum values are coerced instead of crashing the admin UI
- **Mock payment driver** — local demos never require live Stripe credentials

## Secrets & configuration

Never commit:

- `.env` / `.env.production`
- API keys, bot tokens, Stripe secrets, webhook HMAC secrets
- Personal access tokens or database dumps with PII

Use `.env.example` and `.env.production.example` as templates only.
