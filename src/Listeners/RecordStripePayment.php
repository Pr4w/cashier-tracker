<?php

namespace Pr4w\CashierTracker\Listeners;

use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;
use Pr4w\CashierTracker\Concerns\ResolvesPaymentData;

class RecordStripePayment
{
    use ResolvesPaymentData;

    public function handle(WebhookReceived $event): void
    {
        try {
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
        } catch (\Throwable $e) {
            // Tracking is non-critical: never let it break webhook handling.
            Log::warning('[cashier-tracker] Failed to record payment', [
                'type'    => $event->payload['type'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}