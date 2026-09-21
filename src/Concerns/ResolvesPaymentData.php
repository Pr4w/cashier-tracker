<?php

namespace Pr4w\CashierTracker\Concerns;

use Illuminate\Support\Carbon;
use Stripe\StripeClient;

trait ResolvesPaymentData
{
    /** Charge lookups that failed during this instance's lifetime. */
    protected int $unresolvedCharges = 0;

    /** Per-run memo for billable lookups, keyed by Stripe customer id. */
    protected array $billableCache = [];

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
                $this->feeAttributes($charge['fee']),
                $this->refundAttributes($charge['refunded'], $amount),
                $this->resolveBillable($invoice['customer'] ?? null) ?? []
            )
        );

        $this->discardPaymentIntentRowFor($paymentIntentId);
    }

    /**
     * Drop a standalone row for the payment intent that settled this invoice.
     *
     * Webhook order is not guaranteed, so payment_intent.succeeded may have
     * arrived first and been recorded before any invoice existed to recognise
     * it. The invoice always wins: it holds strictly more information.
     *
     * This also makes a backfill replay clean up rows written before the
     * duplicate was fixed — no separate cleanup command needed.
     */
    protected function discardPaymentIntentRowFor(?string $paymentIntentId): void
    {
        if (! $paymentIntentId) {
            return;
        }

        ($this->paymentModel())::query()
            ->where('type', 'payment_intent')
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->delete();
    }

    /**
     * Whether this payment intent settled an invoice that is tracked
     * separately.
     *
     * The Basil API (2025-03-31, the one Cashier 16 pins) removed
     * PaymentIntent.invoice, along with Charge.invoice. Nothing on a payment
     * intent points at an invoice any more — the relation is only navigable
     * the other way, through Invoice.payments — so retrieving the payment
     * intent from Stripe would not answer this either.
     *
     * What does answer it is our own invoice row, which stores the payment
     * intent id for exactly this kind of join.
     */
    protected function settlesAnInvoice(array $pi): bool
    {
        // Pre-Basil (Cashier 15 and older API versions): the field is still
        // there and is authoritative, including when no invoice row exists.
        if (! empty($pi['invoice'])) {
            return true;
        }

        $id = $pi['id'] ?? null;

        return $id !== null && ($this->paymentModel())::query()
            ->where('type', 'invoice')
            ->where('stripe_payment_intent_id', $id)
            ->exists();
    }

    /**
     * Persist a Stripe PaymentIntent (one-shot charges).
     */
    protected function storePaymentIntent(array $pi, ?StripeClient $stripe = null): void
    {
        if (($pi['status'] ?? null) !== 'succeeded') {
            return;
        }

        // Skip payment intents that settled an invoice: the invoice is the
        // canonical record (it carries tax, billing reason and period) and is
        // tracked separately. Recording both double-counts subscription
        // revenue.
        if ($this->settlesAnInvoice($pi)) {
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
                'currency'                 => $pi['currency'] ?? 'eur',
                'billing_reason'           => null,
                'livemode'                 => $pi['livemode'] ?? true,
                'paid_at'                  => $this->timestampToDate($pi['created'] ?? now()->timestamp),
                'meta'                     => [
                    'description' => $pi['description'] ?? null,
                ],
            ],
                $this->feeAttributes($charge['fee']),
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
            // Deliberately non-fatal, but not invisible: without this there is
            // no way to tell a payment with no fee from one whose lookup failed.
            \Illuminate\Support\Facades\Log::debug('[cashier-tracker] Could not resolve charge data', [
                'payment_intent' => $paymentIntentId,
                'message'        => $e->getMessage(),
            ]);

            $this->unresolvedCharges++;

            return $miss;
        }
    }

    /**
     * How many charge lookups failed on this instance. The backfill reports
     * it so a run that quietly resolved nothing is distinguishable from one
     * that had nothing to resolve.
     */
    public function unresolvedCharges(): int
    {
        return $this->unresolvedCharges;
    }

    /**
     * The Stripe client the webhook path should use, or null to skip fee
     * resolution.
     *
     * On by default. The fee is not in the webhook payload: it lives on the
     * charge's balance transaction, and Stripe never auto-expands nested
     * objects in events, so fetching it is the only way to know it at
     * webhook time.
     *
     * Cashier::stripe() rather than a hand-built client: it pins Cashier's
     * own Stripe API version, and it resolves StripeClient through the
     * container, so an application can bind its own instance.
     *
     * Never throws. Enabling fee resolution must not cost you the payment
     * record itself: if the client cannot be built — no API key configured,
     * for instance — this returns null and the payment is stored without a
     * fee, exactly as it would be with the option off.
     */
    protected function webhookStripeClient(): ?StripeClient
    {
        if (! config('cashier-tracker.resolve_fees_on_webhook')) {
            return null;
        }

        try {
            return \Laravel\Cashier\Cashier::stripe();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The Stripe fee, omitted entirely when it could not be resolved. Same
     * reasoning as refundAttributes(): the webhook path passes no Stripe
     * client, so writing the key unconditionally would let a redelivered
     * payment webhook — or a backfill pass that hit an unreachable Stripe —
     * null out a fee an earlier backfill had already resolved. Omitting the
     * key leaves the stored value untouched and lets the column default
     * apply on insert.
     *
     * @return array{fee?: int}
     */
    protected function feeAttributes(?int $fee): array
    {
        return $fee === null ? [] : ['fee' => $fee];
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
     * invoice.payments[].payment.payment_intent path; older versions exposed
     * a flat invoice.payment_intent.
     *
     * Only the first payment is read. An invoice settled by several payment
     * intents — rare, and not something Cashier produces on its own — will
     * therefore have the fee of its first payment only. There is no
     * invoice.charge fallback: that field predates the versions this package
     * supports.
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

    /**
     * Memoised per instance: a backfill walks many payments belonging to the
     * same handful of customers, and findBillable() is a database query each
     * time. The webhook path builds a fresh listener per event, so nothing is
     * cached across requests.
     */
    protected function resolveBillable(?string $stripeCustomerId): ?array
    {
        if (! $stripeCustomerId) {
            return null;
        }

        if (array_key_exists($stripeCustomerId, $this->billableCache)) {
            return $this->billableCache[$stripeCustomerId];
        }

        $billable = \Laravel\Cashier\Cashier::findBillable($stripeCustomerId);

        return $this->billableCache[$stripeCustomerId] = $billable ? [
            'billable_type' => $billable->getMorphClass(),
            'billable_id'   => $billable->getKey(),
        ] : null;
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
