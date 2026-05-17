<?php

namespace Pr4w\CashierTracker\Listeners;

use Laravel\Cashier\Events\WebhookReceived;
use Pr4w\CashierTracker\Concerns\ResolvesPaymentData;

class RecordStripePayment
{
    use ResolvesPaymentData;

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type    = $payload['type'] ?? null;

        if ($type === 'invoice.payment_succeeded' && $this->tracksInvoices()) {
            $this->storeInvoice($payload['data']['object']);
            return;
        }

        if ($type === 'payment_intent.succeeded' && $this->tracksPaymentIntents()) {
            $this->storePaymentIntent($payload['data']['object']);
            return;
        }
    }
}