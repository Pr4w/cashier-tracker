<?php

namespace Pr4w\CashierTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount'  => 'integer',
        'meta'    => 'array',
        'paid_at' => 'datetime',
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
     * Net réel : encaissé - frais Stripe - remboursé.
     */
    public function getNetAmountAttribute(): int
    {
        return $this->amount - ($this->fee ?? 0) - $this->refunded_amount;
    }

    /**
     * Exclut les paiements de test (Stripe CLI, triggers).
     */
    public function scopeLive($query)
    {
        return $query->where('livemode', true);
    }
}