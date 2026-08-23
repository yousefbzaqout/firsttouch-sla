<?php

declare(strict_types=1);

use App\Http\Controllers\MockPaymentCheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::redirect('/register', '/admin/register');

Route::get('/payments/mock/checkout', [MockPaymentCheckoutController::class, 'show'])
    ->name('payments.mock.checkout');

Route::post('/payments/mock/checkout/pay', [MockPaymentCheckoutController::class, 'pay'])
    ->name('payments.mock.pay');

Route::get('/payments/mock/checkout/cancel', [MockPaymentCheckoutController::class, 'cancel'])
    ->name('payments.mock.cancel');
