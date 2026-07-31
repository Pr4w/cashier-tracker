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
     *
     * Les soustractions sont faites en dehors des SUM() : les colonnes de
     * montants sont unsigned, et MySQL lève une erreur "out of range" sur
     * une soustraction d'entiers unsigned dont le résultat est négatif
     * (cas d'un remboursement total, frais compris). SUM() renvoie un
     * DECIMAL signé, l'arithmétique est donc sûre à ce niveau.
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
