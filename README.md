# cashier-tracker

Local tracking of Laravel Cashier (Stripe) payments. Every payment is
recorded in a local table so revenue can be queried without going
through the Stripe dashboard.

-   Captures future payments through the Cashier webhook (listener is
    auto-registered and never blocks the webhook).
-   Imports history through a backfill command (read-only on the Stripe
    side, replayable without creating duplicates).
-   Resolves the net-of-tax amount, tax, Stripe fees and the true net.
-   Attaches each payment to the billable model (User) through an
    optional trait.

Compatibility: Laravel 11/12, Cashier 15/16, PHP 8.2+.

## Installation

Private package, not published on Packagist. Declare the VCS repository
in the host project's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/Pr4w/cashier-tracker"
    }
]
```

Then:

```bash
composer require pr4w/cashier-tracker:^0.1.0 -W
php artisan vendor:publish --tag=cashier-tracker-config
php artisan migrate
```

`-W` is required when Cashier is already locked in the host project's
`composer.lock` (the normal case).

## Configuration

`config/cashier-tracker.php`:

-   `source`: `invoices` (subscriptions, default), `payment_intents`
    (one-off sales), or `both`. Under `both`, payment intents attached
    to an invoice are skipped, so subscription revenue is never counted
    twice.
-   `model`: the Payment model, overridable.
-   `display_currency`: currency shown (indicative only; amounts are
    stored in cents).
-   `table`: table name.

Environment overrides: `CASHIER_TRACKER_SOURCE`,
`CASHIER_TRACKER_CURRENCY`.

For a project that only sells subscriptions (the most common case),
leave this on `invoices`.

## Backfilling history

```bash
# Recent sample: validate the mapping before the full run
php artisan cashier-tracker:backfill --since=2026-01-01

# Full history
php artisan cashier-tracker:backfill
```

The backfill resolves Stripe fees through the path
invoice → payments → payment_intent → charge → balance_transaction
(recent Stripe API). One API call per payment, so it is slow over a
large history. Best-effort: an unresolved fee leaves `fee` at `null`
without interrupting the backfill.

Idempotent on `stripe_id`: replaying the backfill updates existing rows
instead of creating duplicates. Useful for enriching retroactively
(for example after adding the billable association).

## Attaching payments to a user (optional)

On the billable model (typically `App\Models\User`):

```php
use Pr4w\CashierTracker\Concerns\HasPayments;

class User extends Authenticatable
{
    use Billable;      // Cashier
    use HasPayments;   // this package
}
```

Available methods:

-   `trackedPayments()`: morphMany relation to the payments.
-   `totalPaid()`: total collected (cents), excluding test payments.
-   `netPaid()`: true net (collected - fees - refunded), excluding test
    payments.
-   `paidInvoicesCount()`: number of paid invoices.

The association (`billable_type` / `billable_id`) is resolved on write
through `Cashier::findBillable()`. Replaying the backfill after adding
the trait fills in history retroactively.

## Model

`Pr4w\CashierTracker\Models\Payment`

-   `scopeLive()`: excludes test payments (`livemode = false`).
-   `scopePaidBetween($from, $to)`: filters on `paid_at`.
-   `net_amount` (accessor, not stored): `amount - fee - refunded_amount`.
-   `decimal_amount` (accessor): amount in the main currency unit.

Amounts are always stored in cents. `net_amount` is not a column: it is
a computed accessor, so it cannot be used in a `where()`. To aggregate
it, sum the columns in SQL rather than hydrating the collection:

```php
Payment::live()->selectRaw(
    'COALESCE(SUM(amount), 0) - COALESCE(SUM(fee), 0) - COALESCE(SUM(refunded_amount), 0) as aggregate'
)->value('aggregate');
```

The subtractions stay outside the `SUM()` calls: the amount columns are
unsigned, and MySQL rejects unsigned integer subtraction that yields a
negative result.

## Webhook (future payments)

The listener is auto-registered on
`Laravel\Cashier\Events\WebhookReceived`; there is nothing to wire up.
It only fires if the Cashier webhook endpoint is configured on the
Stripe side and listens to at least `invoice.payment_succeeded` (and
`payment_intent.succeeded` if `source` includes one-off sales). It is
wrapped in a try/catch: a tracking failure never interrupts webhook
processing.

## Verification

```php
// tinker
\Pr4w\CashierTracker\Models\Payment::live()->sum('amount') / 100;
$p = \Pr4w\CashierTracker\Models\Payment::first();
[$p->amount, $p->fee, $p->net_amount];
```

Compare the `live()` total against the "Gross volume" figure in the
Stripe dashboard over the same period. Common sources of discrepancy:
mixed currencies (no conversion) or invoices that are not `paid`
(skipped deliberately).

## Known limitations

-   No currency conversion: a gross total adds up amounts across all
    currencies.
-   Stripe dates (`paid_at`, `period_start`, `period_end`) are resolved
    in `app.timezone`, matching the `created_at` Eloquent writes. On an
    app running a non-UTC timezone that has already moved to Carbon 3,
    rows written before this version were interpreted as UTC; replaying
    the backfill realigns the history.
-   Fees are resolved best-effort: `fee` may be `null` when the balance
    transaction cannot be retrieved.
-   `net_amount` is an accessor: usable on a loaded model, but not in a
    `where()`. Aggregates go through the columns (see `netPaid()`).
