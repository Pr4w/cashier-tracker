<?php

namespace Pr4w\CashierTracker\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasPayments
{
    public function trackedPayments(): MorphMany
    {
        return $this->morphMany(
            config('cashier-tracker.model', \Pr4w\CashierTracker\Models\Payment::class),
            'billable'
        );
    }

    /**
     * Total actually collected for this customer, in cents,
     * excluding test payments.
     */
    public function totalPaid(): int
    {
        return (int) $this->trackedPayments()
            ->where('livemode', true)
            ->sum('amount');
    }

    /**
     * True net: collected - Stripe fees - refunded.
     *
     * The subtractions are kept outside the SUM() calls: the amount columns
     * are unsigned, and MySQL raises an "out of range" error on unsigned
     * integer subtraction that yields a negative result (the case of a full
     * refund once fees are counted). SUM() returns a signed DECIMAL, so the
     * arithmetic is safe at that level.
     */
    public function netPaid(): int
    {
        return (int) $this->trackedPayments()
            ->where('livemode', true)
            ->selectRaw(
                'COALESCE(SUM(amount), 0) - COALESCE(SUM(fee), 0) - COALESCE(SUM(refunded_amount), 0) as aggregate'
            )
            ->value('aggregate');
    }

    public function paidInvoicesCount(): int
    {
        return $this->trackedPayments()
            ->where('livemode', true)
            ->where('type', 'invoice')
            ->count();
    }
}