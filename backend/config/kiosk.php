<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Checkout
    |--------------------------------------------------------------------------
    |
    | How long a checkout can be paid. Midtrans QRIS defaults to 15 minutes, so
    | the application expires the checkout on the same clock.
    |
    */

    'checkout_ttl_minutes' => (int) env('CHECKOUT_TTL_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Settlement split
    |--------------------------------------------------------------------------
    |
    | The operator keeps PLATFORM_FEE_PERCENT of every paid tenant order. The
    | tenant receives the remainder. The split is computed in whole rupiah and
    | stored on the tenant order when the payment is confirmed.
    |
    */

    'platform_fee_percent' => (int) env('PLATFORM_FEE_PERCENT', 20),

    'order_code_prefix' => env('ORDER_CODE_PREFIX', 'GMFS'),

];
