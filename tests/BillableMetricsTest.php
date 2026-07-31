<?php

namespace Pr4w\CashierTracker\Tests;

use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Models\Payment;
use Pr4w\CashierTracker\Tests\Support\PaymentDataHarness;
use Pr4w\CashierTracker\Tests\Support\User;

class BillableMetricsTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['email' => 'a@example.com', 'stripe_id' => 'cus_1']);
    }

    private function pay(array $attributes): Payment
    {
        static $n = 0;

        return Payment::create(array_replace([
            'type'           => 'invoice',
            'stripe_id'      => 'in_' . ++$n,
            'amount'         => 1000,
            'currency'       => 'eur',
            'livemode'       => true,
            'paid_at'        => now(),
            'billable_type'  => User::class,
            'billable_id'    => $this->user->id,
        ], $attributes));
    }

    #[Test]
    public function total_paid_sums_gross_excluding_test_payments(): void
    {
        $this->pay(['amount' => 1000]);
        $this->pay(['amount' => 2000]);
        $this->pay(['amount' => 9999, 'livemode' => false]);

        $this->assertSame(3000, $this->user->totalPaid());
    }

    #[Test]
    public function net_paid_subtracts_fees_and_refunds(): void
    {
        $this->pay(['amount' => 1000, 'fee' => 59]);
        $this->pay(['amount' => 2000, 'fee' => null]);                       // recorded live
        $this->pay(['amount' => 500, 'fee' => 25, 'refunded_amount' => 500]); // fully refunded
        $this->pay(['amount' => 9999, 'fee' => 999, 'livemode' => false]);    // test mode

        $this->assertSame(3500 - 84 - 500, $this->user->netPaid());
    }

    #[Test]
    public function net_paid_matches_summing_the_accessor_in_php(): void
    {
        $this->pay(['amount' => 1000, 'fee' => 59]);
        $this->pay(['amount' => 2000, 'fee' => null, 'refunded_amount' => 300]);

        $inPhp = $this->user->trackedPayments()
            ->where('livemode', true)
            ->get()
            ->sum(fn (Payment $p) => $p->net_amount);

        $this->assertSame($inPhp, $this->user->netPaid());
    }

    #[Test]
    public function net_paid_can_go_negative_without_erroring(): void
    {
        // NOTE: this asserts the arithmetic, not the engine behaviour. The
        // amount columns are unsigned and MySQL raises "BIGINT UNSIGNED value
        // is out of range" if the subtraction happens *inside* SUM() and goes
        // negative, which is why netPaid() subtracts outside. SQLite has no
        // unsigned integers, so it accepts either form and this test passes
        // either way. Do not read a green suite as licence to fold the
        // subtraction back into the SUM().
        $this->pay(['amount' => 100, 'fee' => 30, 'refunded_amount' => 100]);

        $this->assertSame(-30, $this->user->netPaid());
    }

    #[Test]
    public function the_metrics_are_zero_when_there_are_no_payments(): void
    {
        $this->assertSame(0, $this->user->totalPaid());
        $this->assertSame(0, $this->user->netPaid());
        $this->assertSame(0, $this->user->paidInvoicesCount());
    }

    #[Test]
    public function the_metrics_are_scoped_to_the_billable(): void
    {
        $other = User::create(['email' => 'b@example.com', 'stripe_id' => 'cus_2']);

        $this->pay(['amount' => 1000]);
        Payment::create([
            'type' => 'invoice', 'stripe_id' => 'in_other', 'amount' => 7777,
            'currency' => 'eur', 'livemode' => true, 'paid_at' => now(),
            'billable_type' => User::class, 'billable_id' => $other->id,
        ]);

        $this->assertSame(1000, $this->user->totalPaid());
        $this->assertSame(7777, $other->totalPaid());
    }

    #[Test]
    public function paid_invoices_count_ignores_one_off_sales(): void
    {
        $this->pay(['type' => 'invoice']);
        $this->pay(['type' => 'invoice']);
        $this->pay(['type' => 'payment_intent']);

        $this->assertSame(2, $this->user->paidInvoicesCount());
    }

    #[Test]
    public function payments_are_attached_to_the_billable_on_write(): void
    {
        (new PaymentDataHarness)->storeInvoice($this->invoicePayload());

        $payment = $this->payment();
        $this->assertSame($this->user->id, $payment->billable_id);
        $this->assertTrue($payment->billable->is($this->user));
        $this->assertSame(2000, $this->user->totalPaid());
    }

    #[Test]
    public function an_unknown_stripe_customer_leaves_the_row_unattached(): void
    {
        (new PaymentDataHarness)->storeInvoice($this->invoicePayload(['customer' => 'cus_nobody']));

        $this->assertNull($this->payment()->billable_id);
    }

    #[Test]
    public function the_live_scope_excludes_test_payments(): void
    {
        $this->pay(['amount' => 1000]);
        $this->pay(['amount' => 9999, 'livemode' => false]);

        $this->assertSame(1000, (int) Payment::live()->sum('amount'));
    }

    #[Test]
    public function the_paid_between_scope_filters_on_paid_at(): void
    {
        $this->pay(['amount' => 1000, 'paid_at' => '2026-01-15 00:00:00']);
        $this->pay(['amount' => 2000, 'paid_at' => '2026-03-15 00:00:00']);

        $total = Payment::paidBetween('2026-01-01', '2026-02-01')->sum('amount');

        $this->assertSame(1000, (int) $total);
    }

    #[Test]
    public function decimal_amount_converts_out_of_cents(): void
    {
        $this->assertSame(19.99, $this->pay(['amount' => 1999])->decimal_amount);
    }
}
