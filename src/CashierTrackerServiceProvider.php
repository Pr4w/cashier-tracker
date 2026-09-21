<?php

namespace Pr4w\CashierTracker;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
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

            $this->scheduleFeeReconciliation();
        }
    }

    /**
     * Schedule the missing-fee sweep, so a host app gets it without wiring
     * anything into its own console routes.
     *
     * Registered through app->booted(): the scheduler is not bound yet while
     * providers are booting.
     */
    private function scheduleFeeReconciliation(): void
    {
        $frequency = config('cashier-tracker.reconcile_fees');

        if (! $frequency) {
            return;
        }

        if (! in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
            Log::warning('[cashier-tracker] Ignoring unknown reconcile_fees frequency', [
                'frequency' => $frequency,
                'expected'  => 'hourly, daily, weekly, monthly, or false',
            ]);

            return;
        }

        $environments = config('cashier-tracker.reconcile_environments', ['production']);

        $this->app->booted(function () use ($frequency, $environments) {
            $event = $this->app->make(Schedule::class)
                ->command('cashier-tracker:backfill --only-missing-fees')
                ->{$frequency}()
                // It talks to Stripe; never let a slow run stack on itself.
                ->withoutOverlapping();

            // Null means every environment. Otherwise keep it out of staging
            // and local, where a test Stripe key against production-shaped
            // data would just fail on every row, every run.
            if ($environments !== null) {
                $event->environments($environments);
            }
        });
    }
}
