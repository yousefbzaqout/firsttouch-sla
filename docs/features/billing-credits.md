# Billing & AI credits

Plan gating, credit ledger, mock Stripe locally, and AI routing fallback when credits hit zero.

## Code map

- `app/Filament/Pages/CreditTopUpPage.php`
- `app/Services/Billing/`
- Payment webhook `POST /api/v1/payments/webhook/{driver}`
