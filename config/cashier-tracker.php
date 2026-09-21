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
    | Fee reconciliation schedule
    |--------------------------------------------------------------------------
    | The package schedules `cashier-tracker:backfill --only-missing-fees`
    | itself, so there is nothing to add to the host app's console routes.
    |
    | This is the safety net for what live resolution cannot catch: a Stripe
    | blip during the webhook, a webhook that never arrived, or a balance
    | transaction that was not created yet. Without it those rows keep a null
    | fee forever.
    |
    | It is close to free. The command queries local rows where `fee` is null
    | and makes one Stripe call per row it finds — on a healthy installation,
    | none at all. Weekly is plenty for low payment volume; raise it if you
    | want gaps closed sooner.
    |
    | One of: 'hourly', 'daily', 'weekly', 'monthly', or false to disable and
    | schedule it yourself.
    */
    'reconcile_fees' => env('CASHIER_TRACKER_RECONCILE', 'weekly'),

    /*
    |--------------------------------------------------------------------------
    | Environments the sweep runs in
    |--------------------------------------------------------------------------
    | Production only by default. The sweep makes outbound Stripe calls, and
    | a staging or local environment pointed at a copy of the production
    | database with a test Stripe key would retry every unresolved fee on
    | every run and fail every time — noise, and pointless load.
    |
    | Set to null to run it everywhere, or list the environments yourself.
    */
    'reconcile_environments' => ['production'],
];