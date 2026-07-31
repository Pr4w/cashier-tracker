<?php

namespace Pr4w\CashierTracker\Tests;

use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;
use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Models\Payment;

/**
 * Exercises the listener through the event the service provider wires it to,
 * so the registration itself is covered as well as the routing.
 */
class WebhookListenerTest extends TestCase
{
    private function fire(string $type, ?array $object): void
    {
        $payload = ['type' => $type];

        if ($object !== null) {
            $payload['data'] = ['object' => $object];
        }

        event(new WebhookReceived($payload));
    }

    #[Test]
    public function it_records_an_invoice_payment(): void
    {
        $this->fire('invoice.payment_succeeded', $this->invoicePayload());

        $payment = $this->payment();
        $this->assertNotNull($payment);
        $this->assertSame('invoice', $payment->type);
        $this->assertSame(2000, $payment->amount);
    }

    #[Test]
    public function it_records_a_one_off_payment_intent(): void
    {
        $this->fire('payment_intent.succeeded', [
            'id' => 'pi_solo', 'status' => 'succeeded', 'amount_received' => 1000,
            'currency' => 'eur', 'created' => 1767225600, 'livemode' => true,
        ]);

        $this->assertSame('payment_intent', $this->payment('pi_solo')->type);
    }

    #[Test]
    public function it_skips_a_payment_intent_that_belongs_to_an_invoice(): void
    {
        // The invoice is the canonical record; counting both would double the
        // subscription revenue.
        $this->fire('payment_intent.succeeded', [
            'id' => 'pi_1', 'status' => 'succeeded', 'amount_received' => 1000,
            'currency' => 'eur', 'created' => 1767225600, 'invoice' => 'in_1',
        ]);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function it_skips_an_unsuccessful_payment_intent(): void
    {
        $this->fire('payment_intent.succeeded', [
            'id' => 'pi_x', 'status' => 'requires_payment_method', 'amount' => 1000,
            'currency' => 'eur', 'created' => 1767225600,
        ]);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function it_applies_a_refund(): void
    {
        $this->fire('invoice.payment_succeeded', $this->invoicePayload());
        $this->fire('charge.refunded', $this->chargePayload(['amount_refunded' => 600]));

        $payment = $this->payment();
        $this->assertSame(600, $payment->refunded_amount);
        $this->assertSame('partially_refunded', $payment->status);
    }

    #[Test]
    public function an_unmatched_refund_is_logged_and_ignored(): void
    {
        Log::spy();

        $this->fire('charge.refunded', $this->chargePayload([
            'payment_intent' => 'pi_unknown', 'amount_refunded' => 100,
        ]));

        $this->assertSame(0, Payment::count());
        Log::shouldHaveReceived('debug')->once();
    }

    #[Test]
    public function a_malformed_payload_is_ignored_without_noise(): void
    {
        Log::spy();

        $this->fire('charge.refunded', null);

        $this->assertSame(0, Payment::count());
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('debug');
    }

    #[Test]
    public function an_unrelated_event_is_ignored(): void
    {
        $this->fire('customer.created', ['id' => 'cus_1']);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function a_tracking_failure_never_breaks_webhook_handling(): void
    {
        Log::spy();

        // No id: the write blows up inside the listener, which must swallow it.
        $this->fire('invoice.payment_succeeded', ['customer' => 'cus_1', 'amount_paid' => 100]);

        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function the_source_config_gates_which_events_are_tracked(): void
    {
        config(['cashier-tracker.source' => 'invoices']);

        $this->fire('payment_intent.succeeded', [
            'id' => 'pi_solo', 'status' => 'succeeded', 'amount_received' => 1000,
            'currency' => 'eur', 'created' => 1767225600,
        ]);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function refunds_are_tracked_regardless_of_the_source_config(): void
    {
        config(['cashier-tracker.source' => 'invoices']);

        $this->fire('invoice.payment_succeeded', $this->invoicePayload());
        $this->fire('charge.refunded', $this->chargePayload(['amount_refunded' => 100]));

        $this->assertSame(100, $this->payment()->refunded_amount);
    }
}
