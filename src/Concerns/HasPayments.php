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
     * Total réellement encaissé pour ce client, en centimes,
     * hors paiements de test.
     */
    public function totalPaid(): int
    {
        return (int) $this->trackedPayments()
            ->where('livemode', true)
            ->sum('amount');
    }

    /**
     * Net réel : encaissé - frais Stripe - remboursé.
     */
    public function netPaid(): int
    {
        return (int) $this->trackedPayments()
            ->where('livemode', true)
            ->get()
            ->sum(fn ($p) => $p->net_amount);
    }

    public function paidInvoicesCount(): int
    {
        return $this->trackedPayments()
            ->where('livemode', true)
            ->where('type', 'invoice')
            ->count();
    }
}