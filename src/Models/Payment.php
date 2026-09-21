<?php

namespace Pr4w\CashierTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount'          => 'integer',
        'subtotal'        => 'integer',
        'tax'             => 'integer',
        'fee'             => 'integer',
        'refunded_amount' => 'integer',
        'livemode'        => 'boolean',
        'meta'            => 'array',
        'paid_at'         => 'datetime',
        'period_start'    => 'datetime',
        'period_end'      => 'datetime',
    ];

    public function getTable()
    {
        return config('cashier-tracker.table', 'cashier_tracker_payments');
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Amount expressed in the main currency unit (e.g. euros), not cents.
     */
    public function getDecimalAmountAttribute(): float
    {
        return $this->amount / 100;
    }

    public function scopePaidBetween($query, $from, $to)
    {
        return $query->whereBetween('paid_at', [$from, $to]);
    }

    /**
     * True net: collected - Stripe fees - refunded.
     *
     * An unresolved fee counts as zero, so this OVERSTATES the net on any row
     * the backfill has not reached — see hasResolvedFee(). Fees are only
     * resolved during `cashier-tracker:backfill`; the webhook path records
     * them as null by design.
     */
    public function getNetAmountAttribute(): int
    {
        return $this->amount - ($this->fee ?? 0) - $this->refunded_amount;
    }

    /**
     * Whether the Stripe fee is known for this payment.
     *
     * Check this before presenting net_amount as a final figure: a row
     * recorded live by the webhook carries no fee until a backfill pass
     * reaches it, and net_amount cannot distinguish "no fee" from
     * "fee not yet known".
     */
    public function hasResolvedFee(): bool
    {
        return $this->fee !== null;
    }

    /**
     * Rows whose Stripe fee has not been resolved yet, i.e. what a backfill
     * still has to fill in.
     */
    public function scopeMissingFee($query)
    {
        return $query->whereNull('fee');
    }

    /**
     * Excludes test payments (Stripe CLI, triggers).
     */
    public function scopeLive($query)
    {
        return $query->where('livemode', true);
    }
}