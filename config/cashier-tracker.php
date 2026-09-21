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
    | Resolve Stripe fees on the webhook
    |--------------------------------------------------------------------------
    | On by default: fees land in real time, so `net_amount` is right without
    | waiting for a backfill.
    |
    | The cost is one Stripe API call per tracked payment, inside the webhook
    | request. The fee is not in the webhook payload itself — it lives on the
    | charge's balance transaction, which Stripe sends as a bare id — so the
    | only way to know it at webhook time is to fetch it.
    |
    | It degrades safely: if the call fails or Stripe is unreachable, the
    | payment is still recorded with a null fee, and a later backfill fills
    | it in.
    |
    | Turn this off if webhook latency matters more than live fees, or if you
    | take payment methods whose balance transaction is not available
    | immediately. `cashier-tracker:backfill` then remains the way fees are
    | resolved.
    */
    'resolve_fees_on_webhook' => env('CASHIER_TRACKER_RESOLVE_FEES', true),

    /*
    |--------------------------------------------------------------------------
    | Currency normalisation
    |--------------------------------------------------------------------------
    | Amounts are always stored in the smallest currency unit (cents).
    | This is only used as a display hint for the widget.
    */
    'display_currency' => env('CASHIER_TRACKER_CURRENCY', 'eur'),

];