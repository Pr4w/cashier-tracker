<?php

namespace Pr4w\CashierTracker\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;
use Pr4w\CashierTracker\Concerns\ResolvesPaymentData;
use Stripe\StripeClient;

class BackfillPaymentsCommand extends Command
{
    use ResolvesPaymentData;

    protected $signature = 'cashier-tracker:backfill
                            {--since= : Only consider payments created on or after this date (Y-m-d)}
                            {--only-missing-fees : Skip the Stripe listing and only fill in fees that are still unknown}';

    protected $description = 'Backfill historical Stripe payments into the local tracker table.';

    public function handle(): int
    {
        try {
            $since = $this->resolveSince();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        // Cashier's factory rather than a hand-built client: it pins Cashier's
        // own Stripe API version, which is what the payload shapes depend on.
        $stripe = Cashier::stripe();

        if ($this->option('only-missing-fees')) {
            $this->reconcileMissingFees($stripe, $since);
        } else {
            if ($this->tracksInvoices()) {
                $this->backfillInvoices($stripe, $since);
            }

            if ($this->tracksPaymentIntents()) {
                $this->backfillPaymentIntents($stripe, $since);
            }
        }

        if ($unresolved = $this->unresolvedCharges()) {
            $this->warn("  {$unresolved} charge lookup(s) failed; those fees are still unknown.");
            $this->line('  Re-run with --only-missing-fees to retry just those.');
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }

    /**
     * strtotime() returns false on unparseable input, which is falsy and would
     * silently widen the run to all of history. Fail loudly instead.
     */
    private function resolveSince(): ?int
    {
        $since = $this->option('since');

        if ($since === null || $since === '') {
            return null;
        }

        $timestamp = strtotime($since);

        if ($timestamp === false) {
            throw new \InvalidArgumentException(
                "Could not parse --since=\"{$since}\". Use a date such as 2026-01-01."
            );
        }

        return $timestamp;
    }

    private function backfillInvoices(StripeClient $stripe, ?int $since): void
    {
        $this->info('Backfilling invoices…');

        $stored = $this->eachPage(
            fn (array $params) => $stripe->invoices->all($params),
            [
                'status' => 'paid',
                'expand' => ['data.payments', 'data.total_taxes'],
            ],
            $since,
            function (array $invoice) use ($stripe): bool {
                $this->storeInvoice($invoice, $stripe);

                return true;
            }
        );

        $this->line("  → {$stored} invoices imported.");
    }

    private function backfillPaymentIntents(StripeClient $stripe, ?int $since): void
    {
        $this->info('Backfilling payment intents…');

        $seen   = 0;
        $stored = $this->eachPage(
            fn (array $params) => $stripe->paymentIntents->all($params),
            [],
            $since,
            function (array $pi) use ($stripe, &$seen): bool {
                $seen++;

                // storePaymentIntent() skips unsuccessful intents and any that
                // belong to an invoice, so "seen" and "stored" differ.
                $before = ($this->paymentModel())::where('stripe_id', $pi['id'] ?? '')->exists();
                $this->storePaymentIntent($pi, $stripe);

                return $before || ($this->paymentModel())::where('stripe_id', $pi['id'] ?? '')->exists();
            }
        );

        $skipped = $seen - $stored;
        $this->line("  → {$stored} payment intents imported"
            . ($skipped > 0 ? ", {$skipped} skipped (unsuccessful, or already billed by an invoice)." : '.'));
    }

    /**
     * Walk a Stripe list endpoint page by page. Both backfills paginate
     * identically; only the endpoint and what they do with each row differ.
     *
     * @param  callable(array): \Stripe\Collection  $fetch
     * @param  callable(array): bool  $handle  returns whether the row was stored
     */
    private function eachPage(callable $fetch, array $params, ?int $since, callable $handle): int
    {
        $params['limit'] = 100;

        if ($since) {
            $params['created'] = ['gte' => $since];
        }

        $stored = 0;

        do {
            $page = $fetch($params);

            foreach ($page->data as $object) {
                if ($handle($object->toArray())) {
                    $stored++;
                }
            }

            $last = $page->data ? $page->data[count($page->data) - 1] : null;
            $params['starting_after'] = $last?->id;
        } while ($page->has_more && $params['starting_after']);

        return $stored;
    }

    /**
     * Fill in fees for rows that do not have one yet, without listing anything
     * from Stripe. On a healthy installation this touches nothing, which makes
     * it cheap enough to schedule: it costs one Stripe call per payment whose
     * fee is genuinely missing, and none at all otherwise.
     */
    private function reconcileMissingFees(StripeClient $stripe, ?int $since): void
    {
        $this->info('Resolving missing fees…');

        // Rows written before the stripe_payment_intent_id migration have no
        // join key, so the sweep cannot see them at all. Recover it first,
        // otherwise they stay invisible to every future run.
        if ($recovered = $this->recoverPaymentIntentIds($stripe, $since)) {
            $this->line("  → {$recovered} payment intent id(s) recovered.");
        }

        $query = ($this->paymentModel())::query()
            ->whereNull('fee')
            ->whereNotNull('stripe_payment_intent_id');

        if ($since) {
            $query->where('paid_at', '>=', Carbon::createFromTimestamp($since, config('app.timezone') ?: 'UTC'));
        }

        $resolved = 0;

        // chunkById, not chunk: each update removes the row from the result
        // set, which would make offset paging skip rows.
        $query->chunkById(100, function ($payments) use ($stripe, &$resolved) {
            foreach ($payments as $payment) {
                $charge = $this->resolveChargeData($stripe, $payment->stripe_payment_intent_id);

                $attributes = array_merge(
                    $this->feeAttributes($charge['fee']),
                    $this->refundAttributes($charge['refunded'], (int) $payment->amount)
                );

                if ($attributes === []) {
                    continue;
                }

                $payment->update($attributes);

                if ($charge['fee'] !== null) {
                    $resolved++;
                }
            }
        });

        $this->line("  → {$resolved} fees resolved.");

        $stranded = $this->scopeToSince(
            ($this->paymentModel())::query()->whereNull('fee')->whereNull('stripe_payment_intent_id'),
            $since
        )->count();

        if ($stranded) {
            // Saying nothing here is what makes "0 fees resolved" misleading
            // on a table full of missing fees.
            $this->warn("  {$stranded} row(s) still have no payment intent id and were skipped.");
            $this->line('  Their invoice could not be read back from Stripe. A full backfill rewrites them:');
            $this->line('  php artisan cashier-tracker:backfill' . ($since ? ' --since=' . date('Y-m-d', $since) : ''));
        }
    }

    /**
     * Fill in stripe_payment_intent_id for rows predating the migration that
     * introduced it, so the sweep stops skipping them silently.
     *
     * Payment-intent rows need no API call: their stripe_id is the payment
     * intent id. Invoice rows are read back from Stripe once.
     */
    private function recoverPaymentIntentIds(StripeClient $stripe, ?int $since): int
    {
        $recovered = $this->scopeToSince(
            ($this->paymentModel())::query()
                ->whereNull('stripe_payment_intent_id')
                ->where('type', 'payment_intent'),
            $since
        )->update(['stripe_payment_intent_id' => DB::raw('stripe_id')]);

        $this->scopeToSince(
            ($this->paymentModel())::query()
                ->whereNull('stripe_payment_intent_id')
                ->where('type', 'invoice'),
            $since
        )->chunkById(100, function ($payments) use ($stripe, &$recovered) {
            foreach ($payments as $payment) {
                try {
                    $invoice = $stripe->invoices->retrieve($payment->stripe_id, [
                        'expand' => ['payments'],
                    ])->toArray();
                } catch (\Throwable $e) {
                    continue;
                }

                if ($id = $this->resolveInvoicePaymentIntentId($invoice)) {
                    $payment->update(['stripe_payment_intent_id' => $id]);
                    $recovered++;
                }
            }
        });

        return $recovered;
    }

    private function scopeToSince($query, ?int $since)
    {
        return $since
            ? $query->where('paid_at', '>=', Carbon::createFromTimestamp($since, config('app.timezone') ?: 'UTC'))
            : $query;
    }
}
