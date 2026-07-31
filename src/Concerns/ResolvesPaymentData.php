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
     * Convert a Stripe Unix timestamp to a date.
     *
     * The timezone is pinned explicitly: Carbon 2 resolved bare timestamps
     * in the app timezone while Carbon 3 resolves them in UTC, and Laravel
     * 11 ships either one. Without this, the same payload lands at a
     * different wall-clock time depending on the installed Carbon. The app
     * timezone is used so `paid_at` stays comparable to the `created_at`
     * Eloquent writes alongside it.
     */
    protected function timestampToDate(int|string $timestamp): Carbon
    {
        // `?:` rather than a config() default: the key is normally present,
        // and a present-but-null value would skip the default entirely.
        return Carbon::createFromTimestamp($timestamp, config('app.timezone') ?: 'UTC');
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

        $paymentIntentId = $this->resolveInvoicePaymentIntentId($invoice);
        $charge          = $this->resolveChargeData($stripe, $paymentIntentId);
        $amount          = (int) ($invoice['amount_paid'] ?? 0);

        ($this->paymentModel())::updateOrCreate(
            ['stripe_id' => $invoice['id']],
            array_merge([
                'type'                     => 'invoice',
                'stripe_customer_id'       => $invoice['customer'] ?? null,
                'stripe_payment_intent_id' => $paymentIntentId,
                'customer_email'           => $invoice['customer_email'] ?? null,
                'amount'                   => $amount,
                'subtotal'                 => $invoice['subtotal'] ?? null,
                'tax'                      => $this->resolveInvoiceTax($invoice),
                'fee'                      => $charge['fee'],
                'currency'                 => $invoice['currency'] ?? 'eur',
                'billing_reason'           => $invoice['billing_reason'] ?? null,
                'livemode'                 => $invoice['livemode'] ?? true,
                'period_start'             => isset($invoice['period_start'])
                    ? $this->timestampToDate($invoice['period_start'])
                    : null,
                'period_end'               => isset($invoice['period_end'])
                    ? $this->timestampToDate($invoice['period_end'])
                    : null,
                'paid_at'                  => $this->timestampToDate($paidAt),
                'meta'                     => [
                    'number'         => $invoice['number'] ?? null,
                    'subscription'   => $this->resolveInvoiceSubscriptionId($invoice),
                    'hosted_invoice' => $invoice['hosted_invoice_url'] ?? null,
                ],
            ],
                $this->refundAttributes($charge['refunded'], $amount),
                $this->resolveBillable($invoice['customer'] ?? null) ?? []
            )
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

        $charge = $this->resolveChargeData($stripe, $pi['id'] ?? null);
        $amount = (int) ($pi['amount_received'] ?? $pi['amount'] ?? 0);

        ($this->paymentModel())::updateOrCreate(
            ['stripe_id' => $pi['id']],
            array_merge([
                'type'                     => 'payment_intent',
                'stripe_customer_id'       => $pi['customer'] ?? null,
                'stripe_payment_intent_id' => $pi['id'] ?? null,
                'customer_email'           => $pi['receipt_email'] ?? null,
                'amount'                   => $amount,
                'subtotal'                 => null,
                'tax'                      => null,
                'fee'                      => $charge['fee'],
                'currency'                 => $pi['currency'] ?? 'eur',
                'billing_reason'           => null,
                'livemode'                 => $pi['livemode'] ?? true,
                'paid_at'                  => $this->timestampToDate($pi['created'] ?? now()->timestamp),
                'meta'                     => [
                    'description' => $pi['description'] ?? null,
                ],
            ],
                $this->refundAttributes($charge['refunded'], $amount),
                $this->resolveBillable($pi['customer'] ?? null) ?? []
            )
        );
    }

    /**
     * Resolve Stripe fees and the refunded total (both in cents) from a
     * PaymentIntent's charge. Both come out of the same retrieve, so
     * tracking refunds during a backfill costs no extra API call.
     *
     * Best-effort: returns nulls on any miss instead of throwing, so a
     * backfill is never aborted by a single unresolvable payment.
     *
     * @return array{fee: ?int, refunded: ?int}
     */
    protected function resolveChargeData(?StripeClient $stripe, ?string $paymentIntentId): array
    {
        $miss = ['fee' => null, 'refunded' => null];

        if (! $stripe || ! $paymentIntentId) {
            return $miss;
        }

        try {
            $pi = $stripe->paymentIntents->retrieve($paymentIntentId, [
                'expand' => ['latest_charge.balance_transaction'],
            ]);

            return [
                'fee'      => $pi->latest_charge->balance_transaction->fee ?? null,
                'refunded' => $pi->latest_charge->amount_refunded ?? null,
            ];
        } catch (\Throwable $e) {
            return $miss;
        }
    }

    /**
     * Attributes derived from a refund, omitted entirely when the refunded
     * total could not be resolved (the webhook path, which passes no Stripe
     * client). Writing them unconditionally would let a redelivered payment
     * webhook reset a refund already recorded by the charge.refunded
     * listener; omitting the keys leaves the stored values untouched and
     * lets the column defaults apply on insert.
     */
    protected function refundAttributes(?int $refunded, int $amount): array
    {
        if ($refunded === null) {
            return [];
        }

        return [
            'refunded_amount' => $refunded,
            'status'          => $this->resolveStatus($amount, $refunded),
        ];
    }

    protected function resolveStatus(int $amount, int $refunded): string
    {
        if ($refunded <= 0) {
            return 'succeeded';
        }

        return $refunded >= $amount ? 'refunded' : 'partially_refunded';
    }

    /**
     * Apply a refund to an already-tracked payment. Returns false when no
     * tracked row matches, which is not an error: tracking may have been
     * enabled after the payment, and the backfill will pick it up.
     *
     * `amount_refunded` on the charge is cumulative across every refund, so
     * it is assigned rather than incremented — a redelivered webhook then
     * re-applies the same total instead of double-counting.
     */
    protected function recordRefund(array $charge): bool
    {
        $paymentIntentId = $this->resolveChargePaymentIntentId($charge);
        $refunded        = $charge['amount_refunded'] ?? null;

        if (! $paymentIntentId || $refunded === null) {
            return false;
        }

        $payment = ($this->paymentModel())::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->first();

        if (! $payment) {
            return false;
        }

        $payment->update([
            'refunded_amount' => (int) $refunded,
            'status'          => $this->resolveStatus((int) $payment->amount, (int) $refunded),
        ]);

        return true;
    }

    /**
     * Extract the PaymentIntent id from a charge, tolerating both the
     * id-only and the expanded-object shape.
     */
    protected function resolveChargePaymentIntentId(array $charge): ?string
    {
        $paymentIntent = $charge['payment_intent'] ?? null;

        if (is_string($paymentIntent)) {
            return $paymentIntent;
        }

        return $paymentIntent['id'] ?? null;
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

    /**
     * Resolve the total tax amount (cents) from an invoice, across API
     * versions. Basil (Cashier 16) moved tax off the top-level `tax`
     * field into an aggregated `total_taxes` array. Pre-Basil exposed a
     * flat `tax` integer.
     */
    protected function resolveInvoiceTax(array $invoice): ?int
    {
        // Basil: total_taxes is an array of tax breakdown objects.
        if (! empty($invoice['total_taxes']) && is_array($invoice['total_taxes'])) {
            $sum = 0;
            foreach ($invoice['total_taxes'] as $t) {
                $sum += $t['amount'] ?? 0;
            }
            return $sum;
        }

        // Pre-Basil fallback: flat tax field.
        if (isset($invoice['tax']) && is_numeric($invoice['tax'])) {
            return (int) $invoice['tax'];
        }

        return null;
    }

    /**
     * Resolve the subscription id an invoice belongs to, across API
     * versions. Basil (Cashier 16) moved it off the top-level
     * `subscription` field into `parent.subscription_details.subscription`.
     * Handles both the id-only and the expanded-object shape.
     */
    protected function resolveInvoiceSubscriptionId(array $invoice): ?string
    {
        // Basil: nested under the invoice parent.
        $subscription = $invoice['parent']['subscription_details']['subscription'] ?? null;

        // Pre-Basil fallback: flat subscription field.
        $subscription ??= $invoice['subscription'] ?? null;

        if (is_string($subscription)) {
            return $subscription;
        }

        return $subscription['id'] ?? null;
    }
}
