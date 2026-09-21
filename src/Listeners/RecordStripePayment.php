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
            $object  = $payload['data']['object'] ?? null;

            if (! is_array($object)) {
                return;
            }

            if ($type === 'invoice.payment_succeeded' && $this->tracksInvoices()) {
                $this->storeInvoice($object, $this->webhookStripeClient());
                return;
            }

            if ($type === 'payment_intent.succeeded' && $this->tracksPaymentIntents()) {
                $this->storePaymentIntent($object, $this->webhookStripeClient());
                return;
            }

            // Refunds apply to whichever source produced the row, so this is
            // not gated on the tracked-source config.
            if ($type === 'charge.refunded') {
                if (! $this->recordRefund($object)) {
                    Log::debug('[cashier-tracker] Refund did not match a tracked payment', [
                        'charge' => $object['id'] ?? null,
                    ]);
                }

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