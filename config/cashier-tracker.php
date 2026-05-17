<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Table name
    |--------------------------------------------------------------------------
    */
    'table' => 'cashier_tracker_payments',

    /*
    |--------------------------------------------------------------------------
    | Payment model
    |--------------------------------------------------------------------------
    | Override this if you want to extend the base Payment model.
    */
    'model' => \Pr4w\CashierTracker\Models\Payment::class,

    /*
    |--------------------------------------------------------------------------
    | Tracked source
    |--------------------------------------------------------------------------
    | 'invoices'        => subscription billing (invoice.payment_succeeded)
    | 'payment_intents' => one-shot charges (payment_intent.succeeded)
    | 'both'            => track both
    */
    'source' => env('CASHIER_TRACKER_SOURCE', 'both'),

    /*
    |--------------------------------------------------------------------------
    | Currency normalisation
    |--------------------------------------------------------------------------
    | Amounts are always stored in the smallest currency unit (cents).
    | This is only used as a display hint for the widget.
    */
    'display_currency' => env('CASHIER_TRACKER_CURRENCY', 'eur'),

];