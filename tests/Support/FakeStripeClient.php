<?php

namespace Pr4w\CashierTracker\Tests\Support;

use Stripe\StripeClient;

/**
 * StripeClient exposes its services through __get, so a declared property
 * shadows the real PaymentIntentService without touching the network.
 */
class FakeStripeClient extends StripeClient
{
    public FakePaymentIntentService $paymentIntents;

    public function __construct(?int $fee = null, ?int $refunded = null, bool $fails = false)
    {
        parent::__construct('sk_test_fake');

        $this->paymentIntents = new FakePaymentIntentService($fee, $refunded, $fails);
    }
}
