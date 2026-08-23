/**
 * FirstTouch SLA — k6 Webhook & Auth Load Test
 * =============================================================================
 *
 * Prerequisites
 *   - Sail stack running (`./vendor/bin/sail up -d`)
 *   - k6 installed locally (https://k6.io/docs/get-started/installation/)
 *   - A real tenant UUID and matching Meta webhook secret
 *     (global WEBHOOKS_META_SECRET or tenant `meta_webhook_secret`)
 *
 * Quick start (against Sail on localhost:80)
 *   export BASE_URL=http://localhost
 *   export TENANT_ID=<uuid-from-tenants-table>
 *   export META_WEBHOOK_SECRET=<same-secret-used-by-that-tenant-or-global>
 *   k6 run tests/K6/webhook_load_test.js
 *
 * Optional env
 *   BASE_URL              default http://localhost
 *   TENANT_ID             required for webhook scenario
 *   META_WEBHOOK_SECRET   required (HMAC key)
 *   TIKTOK_WEBHOOK_SECRET optional — enables TikTok scenario when set
 *   AUTH_EMAIL            default loadtest@example.com (forgot-password probe)
 *
 * Example with inline env:
 *   TENANT_ID=... META_WEBHOOK_SECRET=... k6 run tests/K6/webhook_load_test.js
 *
 * =============================================================================
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { hmac } from 'k6/crypto';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost').replace(/\/$/, '');
const TENANT_ID = __ENV.TENANT_ID || '';
const META_SECRET = __ENV.META_WEBHOOK_SECRET || '';
const TIKTOK_SECRET = __ENV.TIKTOK_WEBHOOK_SECRET || '';
const AUTH_EMAIL = __ENV.AUTH_EMAIL || 'loadtest@example.com';

const webhookSuccess = new Rate('webhook_success');
const http5xx = new Rate('http_5xx');
const webhookDuration = new Trend('webhook_req_duration', true);

function randomString(length) {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  let out = '';
  for (let i = 0; i < length; i += 1) {
    out += chars[Math.floor(Math.random() * chars.length)];
  }
  return out;
}

export const options = {
  stages: [
    { duration: '30s', target: 50 }, // ramp-up 0 → 50 VUs
    { duration: '1m', target: 50 }, // plateau / stress
    { duration: '15s', target: 0 }, // ramp-down 50 → 0
  ],
  thresholds: {
    // 95% of requests faster than 200ms
    http_req_duration: ['p(95)<200'],
    webhook_req_duration: ['p(95)<200'],
    // Success rate for webhook/auth checks (200/202) > 99%
    webhook_success: ['rate>0.99'],
    // Built-in check rate (same success intent)
    checks: ['rate>0.99'],
    // HTTP 5xx < 1%
    http_5xx: ['rate<0.01'],
  },
};

function requireConfig() {
  if (!TENANT_ID) {
    throw new Error('TENANT_ID is required. Export a tenant UUID before running k6.');
  }
  if (!META_SECRET) {
    throw new Error('META_WEBHOOK_SECRET is required for Meta HMAC signing.');
  }
}

function metaLeadPayload() {
  const leadId = `k6-${__VU}-${__ITER}-${randomString(8)}`;

  return {
    entry: [
      {
        changes: [
          {
            value: {
              leadgen_data: {
                leadgen_id: leadId,
                full_name: `K6 Lead ${leadId}`,
                phone_number: `+9665${String(10000000 + (__VU * 1000 + __ITER) % 89999999)}`,
                email: `k6.${leadId}@example.com`,
                campaign_id: 'k6-campaign',
                form_id: 'k6-form',
              },
            },
          },
        ],
      },
    ],
  };
}

function tiktokLeadPayload() {
  const leadId = `k6-tt-${__VU}-${__ITER}-${randomString(8)}`;

  return {
    id: leadId,
    name: `K6 TikTok Lead ${leadId}`,
    phone: `+9665${String(20000000 + (__VU * 1000 + __ITER) % 79999999)}`,
    email: `k6.tt.${leadId}@example.com`,
  };
}

function signMetaBody(body, secret) {
  return `sha256=${hmac('sha256', secret, body, 'hex')}`;
}

function signTikTokBody(body, secret) {
  return hmac('sha256', secret, body, 'hex');
}

function recordResult(res) {
  const ok = res.status === 200 || res.status === 202;
  webhookSuccess.add(ok);
  http5xx.add(res.status >= 500);
  webhookDuration.add(res.timings.duration);

  check(res, {
    'status is 200 or 202': (r) => r.status === 200 || r.status === 202,
    'not 5xx': (r) => r.status < 500,
    'duration < 200ms': (r) => r.timings.duration < 200,
  });
}

function postMetaWebhook() {
  const payload = metaLeadPayload();
  const body = JSON.stringify(payload);
  const url = `${BASE_URL}/api/v1/webhooks/meta/${TENANT_ID}`;

  const res = http.post(url, body, {
    headers: {
      'Content-Type': 'application/json',
      'X-Hub-Signature-256': signMetaBody(body, META_SECRET),
    },
    tags: { endpoint: 'meta_webhook' },
  });

  recordResult(res);
}

function postTikTokWebhook() {
  if (!TIKTOK_SECRET) {
    return;
  }

  const payload = tiktokLeadPayload();
  const body = JSON.stringify(payload);
  const url = `${BASE_URL}/api/v1/webhooks/tiktok/${TENANT_ID}`;

  const res = http.post(url, body, {
    headers: {
      'Content-Type': 'application/json',
      'X-TikTok-Signature': signTikTokBody(body, TIKTOK_SECRET),
    },
    tags: { endpoint: 'tiktok_webhook' },
  });

  recordResult(res);
}

function postAuthForgotPassword() {
  const url = `${BASE_URL}/api/v1/auth/forgot-password`;
  const body = JSON.stringify({ email: AUTH_EMAIL });

  const res = http.post(url, body, {
    headers: { 'Content-Type': 'application/json' },
    tags: { endpoint: 'auth_forgot_password' },
  });

  // Auth probe: accept 200 or validation/not-found style 4xx as non-5xx health.
  const ok = res.status === 200 || res.status === 202 || (res.status >= 400 && res.status < 500);
  webhookSuccess.add(ok);
  http5xx.add(res.status >= 500);
  webhookDuration.add(res.timings.duration);

  check(res, {
    'auth endpoint not 5xx': (r) => r.status < 500,
  });
}

export function setup() {
  requireConfig();

  return {
    baseUrl: BASE_URL,
    tenantId: TENANT_ID,
    tiktokEnabled: Boolean(TIKTOK_SECRET),
  };
}

export default function () {
  // ~80% Meta webhooks, ~10% TikTok (if configured), ~10% auth probe
  const roll = Math.random();

  if (roll < 0.8) {
    postMetaWebhook();
  } else if (roll < 0.9 && TIKTOK_SECRET) {
    postTikTokWebhook();
  } else if (roll < 0.9) {
    postMetaWebhook();
  } else {
    postAuthForgotPassword();
  }

  sleep(0.1);
}
