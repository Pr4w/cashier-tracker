<?php

namespace Pr4w\CashierTracker\Concerns;

use Illuminate\Support\Carbon;
use Stripe\StripeClient;

trait ResolvesPaymentData
{
    protected function paymentModel(): string
    {
        return config('cashier-tracker.model', \Pr4w\CashierTracker\Models\Payment::class);
    }

    /**
     * Persist a Stripe invoice (array shape from webhook OR SDK object cast to array).
     *
     * $stripe is optional: when provided (backfill), Stripe fees are resolved
     * via the related charge's balance transaction. In the webhook path it is
     * omitted to keep processing fast and resilient.
     */
    protected function storeInvoice(array $invoice, ?StripeClient $stripe = null): void
    {
        $paidAt = $invoice['status_transitions']['paid_at']
            ?? $invoice['created']
            ?? now()->timestamp;

        $fee = $this->resolveFee(
            $stripe,
            $invoice['charge'] ?? null
        );

        ($this->paymentModel())::updateOrCreate(
            ['stripe_id' => $invoice['id']],
            [
                'type'               => 'invoice',
                'stripe_customer_id' => $invoice['customer'] ?? null,
                'customer_email'     => $invoice['customer_email'] ?? null,
                'amount'             => $invoice['amount_paid'] ?? 0,
                'subtotal'           => $invoice['subtotal'] ?? null,
                'tax'                => $invoice['tax'] ?? null,
                'fee'                => $fee,
                'currency'           => $invoice['currency'] ?? 'eur',
                'status'             => 'succeeded',
                'billing_reason'     => $invoice['billing_reason'] ?? null,
                'livemode'           => $invoice['livemode'] ?? true,
                'period_start'       => isset($invoice['period_start'])
                    ? Carbon::createFromTimestamp($invoice['period_start'])
                    : null,
                'period_end'         => isset($invoice['period_end'])
                    ? Carbon::createFromTimestamp($invoice['period_end'])
                    : null,
                'paid_at'            => Carbon::createFromTimestamp($paidAt),
                'meta'               => [
                    'number'         => $invoice['number'] ?? null,
                    'subscription'   => $invoice['subscription'] ?? null,
                    'hosted_invoice' => $invoice['hosted_invoice_url'] ?? null,
                ],
            ]
        );
    }

    /**
     * Persist a Stripe PaymentIntent (one-shot charges).
     */
    protected function storePaymentIntent(array $pi, ?StripeClient $stripe = null): void
    {
        if (($pi['status'] ?? null) !== 'succeeded') {
            return;
        }

        // A PaymentIntent's charge lives under latest_charge (string id)
        // or in the legacy charges.data[0] shape.
        $chargeId = $pi['latest_charge']
            ?? ($pi['charges']['data'][0]['id'] ?? null);

        $fee = $this->resolveFee($stripe, $chargeId);

        ($this->paymentModel())::updateOrCreate(
            ['stripe_id' => $pi['id']],
            [
                'type'               => 'payment_intent',
                'stripe_customer_id' => $pi['customer'] ?? null,
                'customer_email'     => $pi['receipt_email'] ?? null,
                'amount'             => $pi['amount_received'] ?? $pi['amount'] ?? 0,
                'subtotal'           => null, // PaymentIntents carry no tax breakdown
                'tax'                => null,
                'fee'                => $fee,
                'currency'           => $pi['currency'] ?? 'eur',
                'status'             => 'succeeded',
                'billing_reason'     => null,
                'livemode'           => $pi['livemode'] ?? true,
                'paid_at'            => Carbon::createFromTimestamp($pi['created'] ?? now()->timestamp),
                'meta'               => [
                    'description' => $pi['description'] ?? null,
                ],
            ]
        );
    }

    /**
     * Resolve Stripe fees (in cents) from a charge's balance transaction.
     * Best-effort: returns null on any miss instead of throwing, so a
     * backfill is never aborted by a single unresolvable charge.
     */
    protected function resolveFee(?StripeClient $stripe, ?string $chargeId): ?int
    {
        if (! $stripe || ! $chargeId) {
            return null;
        }

        try {
            $charge = $stripe->charges->retrieve($chargeId, [
                'expand' => ['balance_transaction'],
            ]);

            return $charge->balance_transaction->fee ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function tracksInvoices(): bool
    {
        return in_array(config('cashier-tracker.source'), ['invoices', 'both'], true);
    }

    protected function tracksPaymentIntents(): bool
    {
        return in_array(config('cashier-tracker.source'), ['payment_intents', 'both'], true);
    }
}