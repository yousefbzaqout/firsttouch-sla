<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Billing\StripeBillingService;
use App\Services\Payments\CreditPurchaseService;
use App\Services\Payments\PaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class PaymentWebhookController
{
    public function __invoke(
        Request $request,
        string $driver,
        PaymentManager $paymentManager,
        CreditPurchaseService $creditService,
        StripeBillingService $stripeBilling,
    ): JsonResponse {
        if ($driver === 'mock' && ! app()->environment('local')) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if ($driver === 'stripe') {
            try {
                $payload = $request->all();
                $payload['_signature'] = (string) $request->header('Stripe-Signature', $request->header('X-Signature', ''));
                $payload['_raw_body'] = $request->getContent();

                $stripeBilling->handlePaymentSuccessWebhook($payload);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['error' => $exception->getMessage()], 400);
            } catch (Throwable $exception) {
                report($exception);

                return response()->json(['error' => 'payment_webhook_failed'], 500);
            }

            return response()->json(['status' => 'processed'], 200);
        }

        try {
            $gateway = $paymentManager->resolve($driver);

            $signature = (string) $request->header('Stripe-Signature', $request->header('X-Signature', ''));
            $payload = $request->all();

            $response = $gateway->handleWebhook($payload, $signature);

            if (! $response->success) {
                return response()->json(['error' => $response->errorMessage], 400);
            }

            $tenantId = (string) ($payload['metadata']['tenant_id'] ?? $payload['tenant_id'] ?? '');

            if ($tenantId === '') {
                return response()->json(['error' => 'missing_tenant_id'], 400);
            }

            $creditService->processSuccessfulPayment($tenantId, $driver, $response);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'payment_webhook_failed'], 500);
        }

        return response()->json(['status' => 'processed'], 200);
    }
}
