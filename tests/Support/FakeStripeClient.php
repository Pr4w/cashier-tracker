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

    public FakeListService $invoices;

    /** Set to serve rows from the paymentIntents list endpoint. */
    public ?FakeListService $paymentIntentList = null;

    public function __construct(
        ?int $fee = null,
        ?int $refunded = null,
        bool $fails = false,
        array $invoices = [],
        array $paymentIntents = [],
    ) {
        parent::__construct('sk_test_fake');

        $this->paymentIntents = new FakePaymentIntentService($fee, $refunded, $fails);
        $this->invoices       = new FakeListService($invoices);

        // The command calls paymentIntents->all() and paymentIntents->retrieve();
        // the retrieve double carries the list behaviour so both live on one
        // property, as they do on the real client.
        $this->paymentIntents->list = new FakeListService($paymentIntents);
    }
}
