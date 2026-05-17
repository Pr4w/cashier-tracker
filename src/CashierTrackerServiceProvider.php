<?php

namespace Pr4w\CashierTracker;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Events\WebhookReceived;
use Pr4w\CashierTracker\Console\BackfillPaymentsCommand;
use Pr4w\CashierTracker\Listeners\RecordStripePayment;

class CashierTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/cashier-tracker.php',
            'cashier-tracker'
        );
    }

    public function boot(): void
    {
        // Migrations: loaded automatically, publishable if customisation needed.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/cashier-tracker.php' => config_path('cashier-tracker.php'),
        ], 'cashier-tracker-config');

        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'cashier-tracker-migrations');

        // Auto-register the webhook listener.
        Event::listen(WebhookReceived::class, RecordStripePayment::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillPaymentsCommand::class,
            ]);
        }
    }
}