<?php

namespace Pr4w\CashierTracker\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Models\Payment;
use Pr4w\CashierTracker\Tests\Support\FakeStripeClient;
use Pr4w\CashierTracker\Tests\Support\PaymentDataHarness;

class RefundTest extends TestCase
{
    private PaymentDataHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harness = new PaymentDataHarness;
    }

    #[Test]
    public function it_stores_the_payment_intent_as_the_refund_join_key(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());

        // Charge.invoice was removed in Basil, so the payment intent is the
        // only link back to an invoice-keyed row that holds across versions.
        $this->assertSame('pi_1', $this->payment()->stripe_payment_intent_id);
    }

    #[Test]
    public function a_one_off_payment_intent_row_references_its_own_id(): void
    {
        $this->harness->storePaymentIntent([
            'id' => 'pi_solo', 'status' => 'succeeded', 'amount_received' => 1000,
            'currency' => 'eur', 'created' => 1767225600, 'livemode' => true,
        ]);

        $this->assertSame('pi_solo', $this->payment('pi_solo')->stripe_payment_intent_id);
    }

    #[Test]
    public function it_applies_a_partial_refund(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());

        $this->assertTrue($this->harness->recordRefund($this->chargePayload(['amount_refunded' => 500])));

        $payment = $this->payment();
        $this->assertSame(500, $payment->refunded_amount);
        $this->assertSame('partially_refunded', $payment->status);
        $this->assertSame(1500, $payment->net_amount);
    }

    #[Test]
    public function it_applies_a_full_refund(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());
        $this->harness->recordRefund($this->chargePayload(['amount_refunded' => 2000]));

        $payment = $this->payment();
        $this->assertSame('refunded', $payment->status);
        $this->assertSame(0, $payment->net_amount);
    }

    #[Test]
    public function a_redelivered_refund_does_not_double_count(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());

        // amount_refunded is cumulative, so replaying the same webhook must
        // re-apply the same total rather than add to it.
        $this->harness->recordRefund($this->chargePayload(['amount_refunded' => 500]));
        $this->harness->recordRefund($this->chargePayload(['amount_refunded' => 500]));

        $this->assertSame(500, $this->payment()->refunded_amount);
    }

    #[Test]
    public function a_redelivered_payment_webhook_does_not_wipe_a_refund(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());
        $this->harness->recordRefund($this->chargePayload(['amount_refunded' => 500]));

        // The webhook path resolves no refund total; it must therefore leave
        // the refund columns alone instead of writing nulls over them.
        $this->harness->storeInvoice($this->invoicePayload());

        $payment = $this->payment();
        $this->assertSame(500, $payment->refunded_amount);
        $this->assertSame('partially_refunded', $payment->status);
    }

    #[Test]
    public function it_accepts_an_expanded_payment_intent_on_the_charge(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());

        $applied = $this->harness->recordRefund($this->chargePayload([
            'payment_intent'  => ['id' => 'pi_1'],
            'amount_refunded' => 250,
        ]));

        $this->assertTrue($applied);
        $this->assertSame(250, $this->payment()->refunded_amount);
    }

    #[Test]
    public function a_refund_for_an_untracked_payment_creates_nothing(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());

        $applied = $this->harness->recordRefund($this->chargePayload([
            'payment_intent' => 'pi_untracked', 'amount_refunded' => 100,
        ]));

        $this->assertFalse($applied);
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function a_charge_without_a_payment_intent_is_ignored(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());

        $this->assertFalse($this->harness->recordRefund([
            'id' => 'ch_1', 'amount_refunded' => 100,
        ]));
        $this->assertSame(0, $this->payment()->refunded_amount);
    }

    public static function statuses(): array
    {
        return [
            'no refund'        => [2000, 0, 'succeeded'],
            'partial refund'   => [2000, 500, 'partially_refunded'],
            'full refund'      => [2000, 2000, 'refunded'],
            'over-refund'      => [2000, 2500, 'refunded'],
            'zero-value row'   => [0, 0, 'succeeded'],
        ];
    }

    #[Test]
    #[DataProvider('statuses')]
    public function it_derives_status_from_the_amounts(int $amount, int $refunded, string $expected): void
    {
        $this->assertSame($expected, $this->harness->resolveStatus($amount, $refunded));
    }

    #[Test]
    public function backfilling_resolves_the_fee_and_the_refund_in_one_api_call(): void
    {
        $stripe = new FakeStripeClient(fee: 59, refunded: 750);

        $this->harness->storeInvoice($this->invoicePayload(), $stripe);

        $payment = $this->payment();
        $this->assertSame(59, $payment->fee);
        $this->assertSame(750, $payment->refunded_amount);
        $this->assertSame('partially_refunded', $payment->status);
        $this->assertSame(1, $stripe->paymentIntents->calls, 'fee and refund should share one retrieve');
    }

    #[Test]
    public function a_stripe_failure_during_backfill_leaves_the_row_intact(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());
        $this->harness->recordRefund($this->chargePayload(['amount_refunded' => 750]));

        // Best-effort: an unreachable Stripe must not abort the backfill, and
        // must not clobber what is already known.
        $this->harness->storeInvoice($this->invoicePayload(), new FakeStripeClient(fails: true));

        $payment = $this->payment();
        $this->assertSame(750, $payment->refunded_amount);
        $this->assertNull($payment->fee);
    }

    #[Test]
    public function the_webhook_path_leaves_the_fee_unresolved(): void
    {
        $this->harness->storeInvoice($this->invoicePayload());

        $this->assertNull($this->payment()->fee);
        $this->assertSame(0, $this->payment()->refunded_amount);
        $this->assertSame('succeeded', $this->payment()->status);
    }
}
