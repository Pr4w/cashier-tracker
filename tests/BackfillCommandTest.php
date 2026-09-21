<?php

namespace Pr4w\CashierTracker\Tests;

use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Models\Payment;
use Pr4w\CashierTracker\Tests\Support\FakeStripeClient;
use Stripe\StripeClient;

class BackfillCommandTest extends TestCase
{
    private function stripe(array $invoices = [], array $paymentIntents = [], ?int $fee = 59, bool $fails = false): FakeStripeClient
    {
        $client = new FakeStripeClient(
            fee: $fee,
            refunded: 0,
            fails: $fails,
            invoices: $invoices,
            paymentIntents: $paymentIntents,
        );

        $this->app->bind(StripeClient::class, fn () => $client);

        return $client;
    }

    private function invoiceRow(string $id, int $created = 1767225600): array
    {
        return [
            'id' => $id, 'customer' => 'cus_1', 'amount_paid' => 2000, 'currency' => 'eur',
            'created' => $created, 'livemode' => true,
            'payments' => ['data' => [['payment' => ['payment_intent' => 'pi_' . $id]]]],
        ];
    }

    #[Test]
    public function it_imports_invoices(): void
    {
        $this->stripe(invoices: [$this->invoiceRow('in_1'), $this->invoiceRow('in_2')]);
        config(['cashier-tracker.source' => 'invoices']);

        $this->artisan('cashier-tracker:backfill')
            ->expectsOutputToContain('2 invoices imported.')
            ->assertSuccessful();

        $this->assertSame(2, Payment::count());
        $this->assertSame(59, $this->payment('in_1')->fee);
    }

    #[Test]
    public function it_paginates_beyond_one_page(): void
    {
        // 250 rows with a page size of 100: three requests, no repeats.
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = $this->invoiceRow('in_' . $i);
        }
        $stripe = $this->stripe(invoices: $rows);
        config(['cashier-tracker.source' => 'invoices']);

        $this->artisan('cashier-tracker:backfill')->assertSuccessful();

        $this->assertSame(250, Payment::count());
        $this->assertSame(3, $stripe->invoices->calls);
    }

    #[Test]
    public function it_passes_since_through_to_stripe(): void
    {
        $stripe = $this->stripe(invoices: [$this->invoiceRow('in_1')]);
        config(['cashier-tracker.source' => 'invoices']);

        $this->artisan('cashier-tracker:backfill', ['--since' => '2026-01-01'])->assertSuccessful();

        $this->assertSame(strtotime('2026-01-01'), $stripe->invoices->seenParams[0]['created']['gte']);
    }

    #[Test]
    public function an_unparseable_since_fails_loudly_instead_of_importing_everything(): void
    {
        $stripe = $this->stripe(invoices: [$this->invoiceRow('in_1')]);

        $this->artisan('cashier-tracker:backfill', ['--since' => 'not-a-date'])
            ->expectsOutputToContain('Could not parse')
            ->assertFailed();

        $this->assertSame(0, $stripe->invoices->calls, 'nothing should have been fetched');
        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function it_reports_payment_intents_it_skipped(): void
    {
        config(['cashier-tracker.source' => 'payment_intents']);
        $this->stripe(paymentIntents: [
            ['id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 1000, 'currency' => 'eur', 'created' => 1767225600],
            ['id' => 'pi_2', 'status' => 'canceled', 'amount' => 500, 'currency' => 'eur', 'created' => 1767225600],
            ['id' => 'pi_3', 'status' => 'succeeded', 'amount' => 500, 'currency' => 'eur', 'created' => 1767225600, 'invoice' => 'in_9'],
        ]);

        $this->artisan('cashier-tracker:backfill')
            ->expectsOutputToContain('1 payment intents imported, 2 skipped')
            ->assertSuccessful();

        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function it_warns_when_charge_lookups_failed(): void
    {
        config(['cashier-tracker.source' => 'invoices']);
        $this->stripe(invoices: [$this->invoiceRow('in_1')], fails: true);

        $this->artisan('cashier-tracker:backfill')
            ->expectsOutputToContain('1 charge lookup(s) failed')
            ->assertSuccessful();

        // The payment is still recorded; only the fee is missing.
        $this->assertSame(1, Payment::count());
        $this->assertNull($this->payment('in_1')->fee);
    }

    #[Test]
    public function only_missing_fees_skips_the_stripe_listing_entirely(): void
    {
        Payment::create([
            'type' => 'invoice', 'stripe_id' => 'in_1', 'stripe_payment_intent_id' => 'pi_1',
            'amount' => 2000, 'currency' => 'eur', 'livemode' => true, 'paid_at' => now(), 'fee' => null,
        ]);
        Payment::create([
            'type' => 'invoice', 'stripe_id' => 'in_2', 'stripe_payment_intent_id' => 'pi_2',
            'amount' => 3000, 'currency' => 'eur', 'livemode' => true, 'paid_at' => now(), 'fee' => 42,
        ]);

        $stripe = $this->stripe(fee: 59);

        $this->artisan('cashier-tracker:backfill', ['--only-missing-fees' => true])
            ->expectsOutputToContain('1 fees resolved.')
            ->assertSuccessful();

        $this->assertSame(0, $stripe->invoices->calls, 'must not list anything');
        $this->assertSame(1, $stripe->paymentIntents->calls, 'one call for the one missing fee');
        $this->assertSame(59, $this->payment('in_1')->fee);
        $this->assertSame(42, $this->payment('in_2')->fee, 'a known fee must not be re-fetched or changed');
    }

    #[Test]
    public function only_missing_fees_costs_nothing_when_everything_is_resolved(): void
    {
        Payment::create([
            'type' => 'invoice', 'stripe_id' => 'in_1', 'stripe_payment_intent_id' => 'pi_1',
            'amount' => 2000, 'currency' => 'eur', 'livemode' => true, 'paid_at' => now(), 'fee' => 59,
        ]);

        $stripe = $this->stripe(fee: 99);

        $this->artisan('cashier-tracker:backfill', ['--only-missing-fees' => true])->assertSuccessful();

        // This is what makes it safe to schedule frequently.
        $this->assertSame(0, $stripe->paymentIntents->calls);
        $this->assertSame(0, $stripe->invoices->calls);
    }

    #[Test]
    public function only_missing_fees_recovers_a_payment_intent_id_from_the_invoice(): void
    {
        // Written before the migration that added the column. Without
        // recovery these rows are invisible to the sweep forever.
        Payment::create([
            'type' => 'invoice', 'stripe_id' => 'in_1', 'stripe_payment_intent_id' => null,
            'amount' => 2000, 'currency' => 'eur', 'livemode' => true, 'paid_at' => now(), 'fee' => null,
        ]);

        $stripe = $this->stripe(invoices: [$this->invoiceRow('in_1')], fee: 59);

        $this->artisan('cashier-tracker:backfill', ['--only-missing-fees' => true])
            ->expectsOutputToContain('1 payment intent id(s) recovered.')
            ->expectsOutputToContain('1 fees resolved.')
            ->assertSuccessful();

        $payment = $this->payment('in_1');
        $this->assertSame('pi_in_1', $payment->stripe_payment_intent_id);
        $this->assertSame(59, $payment->fee);
    }

    #[Test]
    public function a_legacy_payment_intent_row_is_recovered_without_an_api_call(): void
    {
        // For these rows stripe_id IS the payment intent id, so the column can
        // be filled in locally.
        Payment::create([
            'type' => 'payment_intent', 'stripe_id' => 'pi_1', 'stripe_payment_intent_id' => null,
            'amount' => 2000, 'currency' => 'eur', 'livemode' => true, 'paid_at' => now(), 'fee' => null,
        ]);

        $stripe = $this->stripe(fee: 59);

        $this->artisan('cashier-tracker:backfill', ['--only-missing-fees' => true])->assertSuccessful();

        $this->assertSame('pi_1', $this->payment('pi_1')->stripe_payment_intent_id);
        $this->assertSame(0, $stripe->invoices->retrieves, 'no lookup needed for this shape');
        $this->assertSame(59, $this->payment('pi_1')->fee);
    }

    #[Test]
    public function rows_it_cannot_recover_are_reported_not_silently_skipped(): void
    {
        // Invoice gone from Stripe: unrecoverable. Saying nothing here is what
        // made "0 fees resolved" read as "nothing to do".
        Payment::create([
            'type' => 'invoice', 'stripe_id' => 'in_vanished', 'stripe_payment_intent_id' => null,
            'amount' => 2000, 'currency' => 'eur', 'livemode' => true, 'paid_at' => now(), 'fee' => null,
        ]);

        $this->stripe(fee: 59);

        $this->artisan('cashier-tracker:backfill', ['--only-missing-fees' => true])
            ->expectsOutputToContain('1 row(s) still have no payment intent id and were skipped.')
            ->expectsOutputToContain('php artisan cashier-tracker:backfill')
            ->assertSuccessful();
    }
}
