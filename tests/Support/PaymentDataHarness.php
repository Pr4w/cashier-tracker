<?php

namespace Pr4w\CashierTracker\Tests\Support;

use Pr4w\CashierTracker\Concerns\ResolvesPaymentData;

/**
 * Exposes the trait's protected methods so they can be exercised directly.
 * The listener covers the same code through its public surface; this is for
 * the payload-shape and backfill cases the webhook path cannot reach.
 */
class PaymentDataHarness
{
    use ResolvesPaymentData {
        ResolvesPaymentData::storeInvoice as public;
        ResolvesPaymentData::storePaymentIntent as public;
        ResolvesPaymentData::recordRefund as public;
        ResolvesPaymentData::resolveInvoiceSubscriptionId as public;
        ResolvesPaymentData::resolveInvoiceTax as public;
        ResolvesPaymentData::resolveInvoicePaymentIntentId as public;
        ResolvesPaymentData::resolveStatus as public;
        ResolvesPaymentData::timestampToDate as public;
    }
}
