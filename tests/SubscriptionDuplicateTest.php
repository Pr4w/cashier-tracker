<?php

namespace Pr4w\CashierTracker\Tests;

use Laravel\Cashier\Events\WebhookReceived;
use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Models\Payment;

/**
 * A subscription payment produces two webhooks — invoice.payment_succeeded
 * and payment_intent.succeeded — for one movement of money. Only the invoice
 * may be recorded; it is the row that carries tax, billing reason and period.
 *
 * Every payload here is Basil-shaped: no `invoice` key on the payment intent,
 * because the API removed it. A fixture that still carried one would exercise
 * a branch real traffic never reaches.
 */
class SubscriptionDuplicateTest extends TestCase
{
    private function fire(string $type, array $object): void
    {
        event(new WebhookReceived(['type' => $type, 'data' => ['object' => $object]]));
    }

    /** The payment intent Stripe sends for a subscription charge, Basil shape. */
    private function subscriptionPaymentIntent(): array
    {
        return [
            'id'              => 'pi_1',
            'status'          => 'succeeded',
            'amount_received' => 900,
            'currency'        => 'eur',
            'created'         => 1767225600,
            'livemode'        => true,
            // No 'invoice' key: Basil removed PaymentIntent.invoice.
        ];
    }

    private function subscriptionInvoice(): array
    {
        return $this->invoicePayload([
            'id'             => 'in_1',
            'amount_paid'    => 900,
            'customer_email' => 'client@exemple.com',
            'billing_reason' => 'subscription_create',
            'period_start'   => 1767225600,
            'period_end'     => 1769904000,
            'payments'       => ['data' => [['payment' => ['payment_intent' => 'pi_1']]]],
        ]);
    }

    private function assertSingleCanonicalRow(): void
    {
        $this->assertSame(1, Payment::count(), 'one payment must produce one row');

        $payment = Payment::sole();
        $this->assertSame('invoice', $payment->type);
        $this->assertSame(900, $payment->amount);
        $this->assertSame('client@exemple.com', $payment->customer_email);
        $this->assertSame('subscription_create', $payment->billing_reason);
        $this->assertNotNull($payment->period_start);
    }

    #[Test]
    public function invoice_then_payment_intent_records_one_row(): void
    {
        $this->fire('invoice.payment_succeeded', $this->subscriptionInvoice());
        $this->fire('payment_intent.succeeded', $this->subscriptionPaymentIntent());

        $this->assertSingleCanonicalRow();
    }

    #[Test]
    public function payment_intent_then_invoice_records_one_row(): void
    {
        // Stripe does not guarantee webhook ordering, so the reverse must hold.
        $this->fire('payment_intent.succeeded', $this->subscriptionPaymentIntent());
        $this->fire('invoice.payment_succeeded', $this->subscriptionInvoice());

        $this->assertSingleCanonicalRow();
    }

    #[Test]
    public function a_standalone_payment_intent_is_still_recorded(): void
    {
        $this->fire('payment_intent.succeeded', [
            'id' => 'pi_solo', 'status' => 'succeeded', 'amount_received' => 5000,
            'currency' => 'eur', 'created' => 1767225600, 'livemode' => true,
        ]);

        $this->assertSame(1, Payment::count());
        $this->assertSame('payment_intent', Payment::sole()->type);
    }

    #[Test]
    public function a_pre_basil_payment_intent_naming_its_invoice_is_still_skipped(): void
    {
        // Cashier 15 / older API versions still send the flat field.
        $this->fire('payment_intent.succeeded',
            $this->subscriptionPaymentIntent() + ['invoice' => 'in_1']);

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function replaying_the_invoice_clears_a_duplicate_already_in_the_table(): void
    {
        // Rows written before this was fixed. Replaying the backfill, which
        // re-sends every invoice through storeInvoice(), is the cleanup: no
        // separate command needed.
        Payment::create([
            'type' => 'payment_intent', 'stripe_id' => 'pi_1',
            'stripe_payment_intent_id' => 'pi_1', 'amount' => 900,
            'currency' => 'eur', 'livemode' => true, 'paid_at' => now(),
        ]);
        Payment::create([
            'type' => 'invoice', 'stripe_id' => 'in_1',
            'stripe_payment_intent_id' => 'pi_1', 'amount' => 900,
            'currency' => 'eur', 'livemode' => true, 'paid_at' => now(),
        ]);
        $this->assertSame(2, Payment::count());

        $this->fire('invoice.payment_succeeded', $this->subscriptionInvoice());

        $this->assertSingleCanonicalRow();
    }

    #[Test]
    public function a_standalone_payment_intent_row_is_never_collateral_damage(): void
    {
        $this->fire('payment_intent.succeeded', [
            'id' => 'pi_solo', 'status' => 'succeeded', 'amount_received' => 5000,
            'currency' => 'eur', 'created' => 1767225600, 'livemode' => true,
        ]);

        // An unrelated invoice must not delete it.
        $this->fire('invoice.payment_succeeded', $this->subscriptionInvoice());

        $this->assertSame(2, Payment::count());
        $this->assertNotNull(Payment::where('stripe_id', 'pi_solo')->first());
    }
}
