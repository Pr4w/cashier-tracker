<?php

namespace Pr4w\CashierTracker\Tests\Support;

/**
 * Stands in for Stripe's PaymentIntentService. Returns the charge shape
 * that ResolvesPaymentData reads fees and refund totals from, and counts
 * retrievals so tests can assert the backfill makes one call, not two.
 */
class FakePaymentIntentService
{
    public int $calls = 0;

    public function __construct(
        private ?int $fee = null,
        private ?int $refunded = null,
        private bool $fails = false,
    ) {
    }

    public function retrieve($id, $params = null, $opts = null)
    {
        $this->calls++;

        if ($this->fails) {
            throw new \RuntimeException('stripe unavailable');
        }

        return (object) [
            'latest_charge' => (object) [
                'balance_transaction' => (object) ['fee' => $this->fee],
                'amount_refunded'     => $this->refunded,
            ],
        ];
    }
}
