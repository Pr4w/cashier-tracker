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
            $this->resolveInvoicePaymentIntentId($invoice)
        );

        ($this->paymentModel())::updateOrCreate(
            ['stripe_id' => $invoice['id']],
            array_merge([
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
            ], $this->resolveBillable($invoice['customer'] ?? null) ?? [])
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

        // Skip payment intents that belong to an invoice: the invoice is the
        // canonical record (it carries the tax breakdown) and is tracked
        // separately. Recording both would double-count subscription revenue.
        if (! empty($pi['invoice'])) {
            return;
        }

        $fee = $this->resolveFee($stripe, $pi['id'] ?? null);

        ($this->paymentModel())::updateOrCreate(
            ['stripe_id' => $pi['id']],
            array_merge([
                'type'               => 'payment_intent',
                'stripe_customer_id' => $pi['customer'] ?? null,
                'customer_email'     => $pi['receipt_email'] ?? null,
                'amount'             => $pi['amount_received'] ?? $pi['amount'] ?? 0,
                'subtotal'           => null,
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
            ], $this->resolveBillable($invoice['customer'] ?? null) ?? [])
        );
    }

    /**
     * Resolve Stripe fees (in cents) from a PaymentIntent's charge.
     * Best-effort: returns null on any miss instead of throwing, so a
     * backfill is never aborted by a single unresolvable payment.
     */
    protected function resolveFee(?StripeClient $stripe, ?string $paymentIntentId): ?int
    {
        if (! $stripe || ! $paymentIntentId) {
            return null;
        }

        try {
            $pi = $stripe->paymentIntents->retrieve($paymentIntentId, [
                'expand' => ['latest_charge.balance_transaction'],
            ]);

            return $pi->latest_charge->balance_transaction->fee ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Extract the PaymentIntent id that settled an invoice, across API
     * versions. Recent Stripe API (Cashier 16) exposes it via the
     * invoice.payments[].payment.payment_intent path; older versions
     * exposed invoice.payment_intent or invoice.charge directly.
     */
    protected function resolveInvoicePaymentIntentId(array $invoice): ?string
    {
        // Recent API: payments collection on the invoice.
        $payment = $invoice['payments']['data'][0] ?? null;
        if ($payment) {
            $pi = $payment['payment']['payment_intent'] ?? null;
            if (is_string($pi)) {
                return $pi;
            }
            if (is_array($pi) && isset($pi['id'])) {
                return $pi['id'];
            }
        }

        // Older API fallbacks.
        if (! empty($invoice['payment_intent']) && is_string($invoice['payment_intent'])) {
            return $invoice['payment_intent'];
        }

        return null;
    }

    protected function tracksInvoices(): bool
    {
        return in_array(config('cashier-tracker.source'), ['invoices', 'both'], true);
    }

    protected function tracksPaymentIntents(): bool
    {
        return in_array(config('cashier-tracker.source'), ['payment_intents', 'both'], true);
    }

    protected function resolveBillable(?string $stripeCustomerId): ?array
    {
        if (! $stripeCustomerId) {
            return null;
        }

        $billable = \Laravel\Cashier\Cashier::findBillable($stripeCustomerId);

        if (! $billable) {
            return null;
        }

        return [
            'billable_type' => $billable->getMorphClass(),
            'billable_id'   => $billable->getKey(),
        ];
    }
}