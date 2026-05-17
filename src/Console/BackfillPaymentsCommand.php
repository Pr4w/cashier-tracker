<?php

namespace Pr4w\CashierTracker\Console;

use Illuminate\Console\Command;
use Pr4w\CashierTracker\Concerns\ResolvesPaymentData;
use Stripe\StripeClient;

class BackfillPaymentsCommand extends Command
{
    use ResolvesPaymentData;

    protected $signature = 'cashier-tracker:backfill
                            {--since= : Only import payments created after this date (Y-m-d)}';

    protected $description = 'Backfill historical Stripe payments into the local tracker table.';

    public function handle(): int
    {
        $stripe = new StripeClient(config('cashier.secret'));

        $since = $this->option('since')
            ? strtotime($this->option('since'))
            : null;

        if ($this->tracksInvoices()) {
            $this->backfillInvoices($stripe, $since);
        }

        if ($this->tracksPaymentIntents()) {
            $this->backfillPaymentIntents($stripe, $since);
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }

    private function backfillInvoices(StripeClient $stripe, ?int $since): void
    {
        $this->info('Backfilling invoices…');
        $params = [
            'status' => 'paid',
            'limit'  => 100,
            'expand' => ['data.payments'],
        ];

        if ($since) {
            $params['created'] = ['gte' => $since];
        }

        $count = 0;

        do {
            $invoices = $stripe->invoices->all($params);

            foreach ($invoices->data as $invoice) {
                $this->storeInvoice($invoice->toArray(), $stripe);
                $count++;
            }

            $last = end($invoices->data);
            $params['starting_after'] = $last ? $last->id : null;
        } while ($invoices->has_more && $params['starting_after']);

        $this->line("  → {$count} invoices imported.");
    }

    private function backfillPaymentIntents(StripeClient $stripe, ?int $since): void
    {
        $this->info('Backfilling payment intents…');
        $params = ['limit' => 100];

        if ($since) {
            $params['created'] = ['gte' => $since];
        }

        $count = 0;

        do {
            $intents = $stripe->paymentIntents->all($params);

            foreach ($intents->data as $pi) {
                $this->storePaymentIntent($pi->toArray(), $stripe);
                $count++;
            }

            $last = end($intents->data);
            $params['starting_after'] = $last ? $last->id : null;
        } while ($intents->has_more && $params['starting_after']);

        $this->line("  → {$count} payment intents processed.");
    }
}