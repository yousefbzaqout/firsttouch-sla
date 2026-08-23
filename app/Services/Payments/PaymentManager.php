<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentGatewayInterface;
use App\Services\Payments\Drivers\MockPaymentDriver;
use App\Services\Payments\Drivers\StripePaymentDriver;
use InvalidArgumentException;

class PaymentManager
{
    public function resolve(?string $driver = null): PaymentGatewayInterface
    {
        $driver ??= (string) config('payments.default_driver', 'mock');

        return match ($driver) {
            'mock' => new MockPaymentDriver,
            'stripe' => new StripePaymentDriver(
                (string) config('services.stripe.secret'),
                (string) config('services.stripe.webhook_secret'),
            ),
            default => throw new InvalidArgumentException("Unsupported payment driver: {$driver}"),
        };
    }
}
