<?php

declare(strict_types=1);

return [

    'default_driver' => env('PAYMENT_DRIVER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Credit top-up packages (USD)
    |--------------------------------------------------------------------------
    */
    'packages' => [
        [
            'credits' => 1000,
            'amount' => 20.00,
            'label' => '1,000 leads',
        ],
        [
            'credits' => 3000,
            'amount' => 50.00,
            'label' => '3,000 leads',
        ],
        [
            'credits' => 100,
            'amount' => 9.99,
            'label' => '100 credits',
        ],
        [
            'credits' => 500,
            'amount' => 39.99,
            'label' => '500 credits',
        ],
    ],

];
