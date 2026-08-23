<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout — FirstTouch Credits</title>
    <style>
        :root {
            --bg: #f6f9fc;
            --panel: #ffffff;
            --ink: #0a2540;
            --muted: #425466;
            --line: #e6ebf1;
            --accent: #635bff;
            --accent-hover: #5851ea;
            --success: #0d9488;
            --danger: #c2410c;
            --chip: #fff7ed;
            --chip-ink: #9a3412;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 500px at 10% -10%, #dbeafe 0%, transparent 55%),
                radial-gradient(900px 400px at 100% 0%, #e0e7ff 0%, transparent 50%),
                var(--bg);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .shell {
            width: min(920px, 100%);
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(10, 37, 64, 0.08);
        }

        @media (max-width: 800px) {
            .shell { grid-template-columns: 1fr; }
        }

        .summary {
            padding: 36px 32px;
            background: linear-gradient(165deg, #0a2540 0%, #1a365d 100%);
            color: #f8fafc;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.1);
            font-size: 12px;
            letter-spacing: 0.02em;
            margin-bottom: 28px;
        }

        .badge strong {
            color: #fde68a;
            font-weight: 700;
        }

        .summary h1 {
            margin: 0 0 8px;
            font-size: 28px;
            font-weight: 650;
            letter-spacing: -0.02em;
        }

        .summary p {
            margin: 0;
            color: #cbd5e1;
            line-height: 1.5;
        }

        .line-item {
            margin-top: 36px;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: baseline;
        }

        .line-item .label {
            font-size: 15px;
            color: #e2e8f0;
        }

        .line-item .meta {
            margin-top: 6px;
            font-size: 13px;
            color: #94a3b8;
        }

        .line-item .price {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.03em;
        }

        .panel {
            padding: 36px 32px;
        }

        .panel h2 {
            margin: 0 0 6px;
            font-size: 18px;
        }

        .panel .hint {
            margin: 0 0 24px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }

        .mock-note {
            margin-bottom: 20px;
            padding: 12px 14px;
            border: 1px dashed #fdba74;
            background: var(--chip);
            color: var(--chip-ink);
            border-radius: 10px;
            font-size: 13px;
            line-height: 1.45;
        }

        .field {
            margin-bottom: 14px;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .field input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 15px;
            color: var(--ink);
            background: #fff;
        }

        .field input:disabled {
            background: #f8fafc;
            color: #64748b;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .actions {
            margin-top: 22px;
            display: grid;
            gap: 10px;
        }

        .btn {
            appearance: none;
            border: 0;
            border-radius: 10px;
            padding: 13px 16px;
            font-size: 15px;
            font-weight: 650;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: block;
        }

        .btn-pay {
            background: var(--accent);
            color: #fff;
        }

        .btn-pay:hover { background: var(--accent-hover); }

        .btn-cancel {
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--line);
        }

        .btn-cancel:hover { background: #f8fafc; }

        .footer {
            margin-top: 18px;
            font-size: 12px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="shell">
        <aside class="summary">
            <div class="badge">
                <span>Stripe Mock Gateway</span>
                <strong>TEST MODE</strong>
            </div>
            <h1>Complete your purchase</h1>
            <p>This is a local stand-in for Stripe Checkout. Replace with real Stripe APIs when ready.</p>

            <div class="line-item">
                <div>
                    <div class="label">{{ number_format($credits) }} AI Credits</div>
                    <div class="meta">Session {{ \Illuminate\Support\Str::limit($session, 8, '') }}</div>
                </div>
                <div class="price">${{ number_format($amount, 2) }}</div>
            </div>
        </aside>

        <section class="panel">
            <h2>Payment details</h2>
            <p class="hint">No real card is charged. Confirm to credit the tenant balance.</p>

            <div class="mock-note">
                Mock card data is prefilled for demo only. Future: wire `PAYMENT_DRIVER=stripe` and real Checkout Session APIs.
            </div>

            <div class="field">
                <label>Email</label>
                <input type="email" value="buyer@example.com" disabled>
            </div>

            <div class="field">
                <label>Card number</label>
                <input type="text" value="4242 4242 4242 4242" disabled>
            </div>

            <div class="row">
                <div class="field">
                    <label>Expiry</label>
                    <input type="text" value="12 / 34" disabled>
                </div>
                <div class="field">
                    <label>CVC</label>
                    <input type="text" value="123" disabled>
                </div>
            </div>

            <div class="actions">
                <form method="POST" action="{{ $payUrl }}">
                    @csrf
                    <button class="btn btn-pay" type="submit">
                        Pay ${{ number_format($amount, 2) }}
                    </button>
                </form>

                <a class="btn btn-cancel" href="{{ $cancelUrl }}">Cancel and return</a>
            </div>

            <p class="footer">Powered by FirstTouch mock payments · Tenant {{ \Illuminate\Support\Str::limit($tenantId, 13, '…') }}</p>
        </section>
    </div>
</body>
</html>
