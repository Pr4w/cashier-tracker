<?php

namespace Pr4w\CashierTracker\Tests;

use Laravel\Cashier\Events\WebhookReceived;
use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Models\Payment;
use Pr4w\CashierTracker\Tests\Support\FakePaymentIntentService;
use Pr4w\CashierTracker\Tests\Support\FakeStripeClient;
use Stripe\StripeClient;

/**
 * `resolve_fees_on_webhook` trades one Stripe API call per payment for fees
 * that are correct immediately. Stripe never auto-expands nested objects in
 * webhook events, and the fee lives on the charge's balance transaction, so
 * fetching it is the only way to have it at webhook time.
 */
class LiveFeeResolutionTest extends TestCase
{
    private function fakeStripe(?int $fee = 59, bool $fails = false): FakePaymentIntentService
    {
        $client = new FakeStripeClient(fee: $fee, refunded: 0, fails: $fails);

        // Cashier::stripe() resolves StripeClient through the container.
        $this->app->bind(StripeClient::class, fn () => $client);

        return $client->paymentIntents;
    }

    private function fire(string $type, array $object): void
    {
        event(new WebhookReceived(['type' => $type, 'data' => ['object' => $object]]));
    }

    #[Test]
    public function the_shipped_config_default_is_on(): void
    {
        $config = require __DIR__ . '/../config/cashier-tracker.php';

        $this->assertTrue($config['resolve_fees_on_webhook']);
    }

    #[Test]
    public function an_invoice_webhook_resolves_the_fee_when_enabled(): void
    {
        config(['cashier-tracker.resolve_fees_on_webhook' => true]);
        $service = $this->fakeStripe(fee: 59);

        $this->fire('invoice.payment_succeeded', $this->invoicePayload());

        $this->assertSame(59, $this->payment()->fee);
        $this->assertSame(1, $service->calls);
    }

    #[Test]
    public function a_payment_intent_webhook_resolves_the_fee_when_enabled(): void
    {
        config(['cashier-tracker.resolve_fees_on_webhook' => true]);
        $this->fakeStripe(fee: 32);

        $this->fire('payment_intent.succeeded', [
            'id' => 'pi_solo', 'status' => 'succeeded', 'amount_received' => 1000,
            'currency' => 'eur', 'created' => 1767225600, 'livemode' => true,
        ]);

        $this->assertSame(32, $this->payment('pi_solo')->fee);
    }

    #[Test]
    public function nothing_is_fetched_when_disabled(): void
    {
        config(['cashier-tracker.resolve_fees_on_webhook' => false]);
        $service = $this->fakeStripe(fee: 59);

        $this->fire('invoice.payment_succeeded', $this->invoicePayload());

        $this->assertNull($this->payment()->fee);
        $this->assertSame(0, $service->calls, 'disabled must mean no API call at all');
    }

    #[Test]
    public function a_failing_stripe_call_still_records_the_payment(): void
    {
        config(['cashier-tracker.resolve_fees_on_webhook' => true]);
        $this->fakeStripe(fails: true);

        $this->fire('invoice.payment_succeeded', $this->invoicePayload());

        // Degrading to "no fee" is acceptable; losing the payment row is not.
        $payment = $this->payment();
        $this->assertNotNull($payment);
        $this->assertSame(2000, $payment->amount);
        $this->assertNull($payment->fee);
    }

    #[Test]
    public function an_unbuildable_client_still_records_the_payment(): void
    {
        config(['cashier-tracker.resolve_fees_on_webhook' => true]);

        // e.g. no API key configured: constructing the client throws before
        // any request is made. That must not cost us the payment record.
        $this->app->bind(StripeClient::class, function () {
            throw new \RuntimeException('no api key');
        });

        $this->fire('invoice.payment_succeeded', $this->invoicePayload());

        $this->assertNotNull($this->payment());
        $this->assertNull($this->payment()->fee);
    }

    #[Test]
    public function a_live_resolved_fee_survives_a_redelivery_that_cannot_resolve(): void
    {
        config(['cashier-tracker.resolve_fees_on_webhook' => true]);
        $this->fakeStripe(fee: 59);
        $this->fire('invoice.payment_succeeded', $this->invoicePayload());
        $this->assertSame(59, $this->payment()->fee);

        // Option later turned off, webhook redelivered: the fee must persist.
        config(['cashier-tracker.resolve_fees_on_webhook' => false]);
        $this->fire('invoice.payment_succeeded', $this->invoicePayload());

        $this->assertSame(59, $this->payment()->fee);
        $this->assertSame(1, Payment::count());
    }
}
