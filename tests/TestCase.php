<?php

namespace Pr4w\CashierTracker\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\CashierServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Pr4w\CashierTracker\CashierTrackerServiceProvider;
use Pr4w\CashierTracker\Models\Payment;
use Pr4w\CashierTracker\Tests\Support\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Config applied during boot, for tests that need it set before providers run. */
    protected array $overrideConfig = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cashier::useCustomerModel(User::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            CashierServiceProvider::class,
            CashierTrackerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cashier.secret', 'sk_test_fake');
        $app['config']->set('cashier-tracker.source', 'both');

        // Explicit rather than inherited: the shipped default is true, and a
        // test that resolves fees would otherwise make a real Stripe call.
        // Tests that want live fees enable it and bind a fake client.
        $app['config']->set('cashier-tracker.resolve_fees_on_webhook', false);

        foreach ($this->overrideConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        // The billable table the morph association points at. Package
        // migrations load themselves through the service provider.
        $this->loadMigrationsFrom(__DIR__ . '/Support/migrations');
    }

    /**
     * A paid invoice in the recent (Basil) API shape.
     */
    protected function invoicePayload(array $overrides = []): array
    {
        return array_replace([
            'id'          => 'in_1',
            'customer'    => 'cus_1',
            'amount_paid' => 2000,
            'subtotal'    => 1667,
            'currency'    => 'eur',
            'created'     => 1767225600, // 2026-01-01 00:00:00 UTC
            'livemode'    => true,
            'payments'    => ['data' => [['payment' => ['payment_intent' => 'pi_1']]]],
        ], $overrides);
    }

    protected function chargePayload(array $overrides = []): array
    {
        return array_replace([
            'id'              => 'ch_1',
            'payment_intent'  => 'pi_1',
            'amount_refunded' => 0,
        ], $overrides);
    }

    protected function payment(string $stripeId = 'in_1'): ?Payment
    {
        return Payment::where('stripe_id', $stripeId)->first();
    }
}
