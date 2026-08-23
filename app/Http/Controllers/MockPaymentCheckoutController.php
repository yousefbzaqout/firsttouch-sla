<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\PaymentResponseDTO;
use App\Services\Payments\CreditPurchaseService;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MockPaymentCheckoutController
{
    public function show(Request $request): View
    {
        $this->assertValidCheckout($request);

        $tenantId = (string) $request->query('tenant_id');
        $credits = (int) $request->query('credits');
        $amount = (float) $request->query('amount');
        $session = (string) $request->query('session');

        $payUrl = URL::temporarySignedRoute(
            'payments.mock.pay',
            now()->addMinutes(30),
            [
                'tenant_id' => $tenantId,
                'credits' => $credits,
                'amount' => $amount,
                'session' => $session,
            ],
        );

        $cancelUrl = URL::temporarySignedRoute(
            'payments.mock.cancel',
            now()->addMinutes(30),
            [
                'tenant_id' => $tenantId,
                'credits' => $credits,
                'amount' => $amount,
                'session' => $session,
            ],
        );

        return view('payments.mock-checkout', [
            'tenantId' => $tenantId,
            'credits' => $credits,
            'amount' => $amount,
            'session' => $session,
            'payUrl' => $payUrl,
            'cancelUrl' => $cancelUrl,
        ]);
    }

    public function pay(Request $request, CreditPurchaseService $creditService): RedirectResponse
    {
        $this->assertValidCheckout($request);

        $tenantId = (string) $request->query('tenant_id');
        $credits = (int) $request->query('credits');
        $amount = (float) $request->query('amount');

        $response = new PaymentResponseDTO(
            success: true,
            transactionId: 'mock_txn_'.Str::uuid()->toString(),
            creditsPurchased: $credits,
            amountPaid: $amount,
            currency: 'USD',
        );

        try {
            $creditService->processSuccessfulPayment($tenantId, 'mock', $response);
        } catch (\Throwable $exception) {
            Log::error('Mock checkout failed to credit tenant', [
                'tenant_id' => $tenantId,
                'error' => $exception->getMessage(),
            ]);

            abort(500, 'Unable to complete mock payment.');
        }

        Notification::make()
            ->title('Payment successful')
            ->body("{$credits} credits were added to your balance.")
            ->success()
            ->send();

        return redirect('/admin/credit-top-up');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->assertValidCheckout($request);

        Notification::make()
            ->title('Payment cancelled')
            ->body('No credits were added. You can try again anytime.')
            ->warning()
            ->send();

        return redirect('/admin/credit-top-up');
    }

    private function assertValidCheckout(Request $request): void
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired checkout session.');
        }

        $tenantId = (string) $request->query('tenant_id', '');
        $credits = (int) $request->query('credits', 0);

        if ($tenantId === '' || $credits < 1) {
            abort(422, 'Invalid checkout payload.');
        }
    }
}
