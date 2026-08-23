# cashier-tracker

Local tracking of Laravel Cashier (Stripe) payments. Every payment is
recorded in a local table so revenue can be queried without going
through the Stripe dashboard.

-   Captures future payments through the Cashier webhook (listener is
    auto-registered and never blocks the webhook).
-   Imports history through a backfill command (read-only on the Stripe
    side, replayable without creating duplicates).
-   Resolves the net-of-tax amount, tax, Stripe fees and the true net.
-   Tracks refunds, so reported revenue reflects money actually kept.
-   Attaches each payment to the billable model (User) through an
    optional trait.

Compatibility: Laravel 12/13, Cashier 15/16, PHP 8.3+.

Laravel 11 support was dropped in 0.2.0: every 11.x release is flagged by
unpatched security advisories, so Composer's default policy will not
resolve it. Stay on 0.1.1 if you need it.

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
composer require pr4w/cashier-tracker:^0.2.0 -W
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

The backfill resolves Stripe fees and refunds through the path
invoice → payments → payment_intent → charge → balance_transaction
(recent Stripe API); both come out of the same call. One API call per
payment, so it is slow over a large history. Best-effort: an unresolved
charge leaves `fee` at `null` without interrupting the backfill.

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

-   `stripe_payment_intent_id`: the payment intent behind the row, on both
    invoice and payment-intent rows. Join key for refunds.
-   `status`: `succeeded`, `partially_refunded`, or `refunded`.
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
It is wrapped in a try/catch: a tracking failure never interrupts
webhook processing.

Events consumed, which the Stripe endpoint must be subscribed to:

| Event | Needed for |
| --- | --- |
| `invoice.payment_succeeded` | subscription payments (`source` includes `invoices`) |
| `payment_intent.succeeded` | one-off sales (`source` includes `payment_intents`) |
| `charge.refunded` | refunds, for either source |

## Refunds

`charge.refunded` updates the matching row's `refunded_amount` and
`status`. Nothing else needs configuring, but two details are worth
knowing.

**The join key.** A refund arrives on a charge, while rows are keyed by
invoice id or payment intent id. `Charge.invoice` was removed in the
Basil API (Cashier 16) whereas `Charge.payment_intent` survives across
versions, so the payment intent is stored on every row
(`stripe_payment_intent_id`) and used as the join. Existing installs
need the second migration for this column:

```bash
php artisan migrate
```

**Idempotency.** `amount_refunded` on the charge is cumulative across
every refund, so it is assigned rather than incremented — a redelivered
webhook re-applies the same total instead of double-counting. For the
same reason, a redelivered *payment* webhook does not reset a refund:
refund fields are only written when the refunded total was actually
resolved.

A refund for a payment that is not tracked (tracking enabled after the
fact) is logged at debug level and ignored; the backfill picks it up.

`status` follows from the amounts:

| `status` | Condition |
| --- | --- |
| `succeeded` | no refund |
| `partially_refunded` | `0 < refunded_amount < amount` |
| `refunded` | `refunded_amount >= amount` |

Backfilling also fills in refunds, at no extra API cost: the refunded
total comes from the same charge retrieval already used for the fee.
Replaying the backfill therefore corrects refund history retroactively.

Not covered: disputes and chargebacks (`charge.dispute.*`), which
withdraw funds but are not refunds, and refund reversals (an async
refund that later fails).

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

## Tests

```bash
composer install
composer test
```

Testbench boots a real Laravel app with both service providers and runs
against SQLite in memory; no Stripe credentials and no network access are
needed. To check the declared version range, resolve the ends explicitly:

```bash
composer update --prefer-lowest   # floor of the supported range
composer update                   # newest Laravel / Cashier / PHPUnit
``` Coverage is concentrated on the parts that have actually broken:
payload shapes across Stripe API versions, timestamp timezone resolution,
refund idempotency under webhook redelivery, and the billable aggregates.

One caveat is recorded in `BillableMetricsTest`: `netPaid()` keeps its
subtractions outside `SUM()` because MySQL rejects unsigned integer
subtraction that goes negative. SQLite has no unsigned integers and
accepts either form, so a green suite does not prove that constraint
holds — don't fold the subtraction back in.

## Known limitations

-   No currency conversion: a gross total adds up amounts across all
    currencies.
-   Stripe dates (`paid_at`, `period_start`, `period_end`) are resolved
    in `app.timezone`, matching the `created_at` Eloquent writes. On an
    app running a non-UTC timezone that has already moved to Carbon 3,
    rows written before this version were interpreted as UTC; replaying
    the backfill realigns the history.
-   Fees are only resolved during a backfill. The webhook path passes no
    Stripe client, by design, to keep webhook handling fast and resilient,
    so rows recorded live carry `fee = null` until a backfill fills them
    in. Refunds are not affected: they are tracked live.
-   Even during a backfill, fees are best-effort: `fee` stays `null` when
    the balance transaction cannot be retrieved.
-   `net_amount` is an accessor: usable on a loaded model, but not in a
    `where()`. Aggregates go through the columns (see `netPaid()`).
