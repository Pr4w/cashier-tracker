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
-   Self-schedules a weekly sweep for any fee it could not resolve live, so
    there is nothing to wire into the host app.
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
    (one-off sales), or `both`. Under `both`, a payment intent that settled
    an invoice is skipped, so subscription revenue is never counted twice
    (see "One payment, two webhooks" below).
-   `resolve_fees_on_webhook`: resolve Stripe fees live, `true` by default.
    One Stripe API call per tracked payment. See "Fees" below.
-   `reconcile_fees`: how often the package schedules its own missing-fee
    sweep. `'weekly'` by default; `'hourly'`, `'daily'`, `'monthly'`, or
    `false` to disable.
-   `reconcile_environments`: environments that sweep runs in,
    `['production']` by default. `null` to run everywhere.
-   `model`: the Payment model, overridable.
-   `table`: table name.

Environment overrides: `CASHIER_TRACKER_SOURCE`,
`CASHIER_TRACKER_RESOLVE_FEES`, `CASHIER_TRACKER_RECONCILE`.

For a project that only sells subscriptions (the most common case),
leave this on `invoices`.

## Backfilling history

```bash
# Recent sample: validate the mapping before the full run
php artisan cashier-tracker:backfill --since=2026-01-01

# Full history
php artisan cashier-tracker:backfill

# Cheap sweep: only fill in fees that are still unknown
php artisan cashier-tracker:backfill --only-missing-fees
```

| Option | Effect |
| --- | --- |
| `--since=Y-m-d` | Only consider payments created on or after this date. Fails with a message if the date cannot be parsed, rather than silently importing everything. |
| `--only-missing-fees` | Skip the Stripe listing entirely and re-resolve only rows whose `fee` is null. One API call per genuinely-missing fee, none otherwise. Rows with no `stripe_payment_intent_id` (written before that column existed) have their id recovered first, and any it cannot recover are reported rather than skipped in silence. |

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
-   `scopeMissingFee()`: rows a backfill has not resolved fees for yet.
-   `hasResolvedFee()`: whether `fee` is known, as opposed to genuinely zero.
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
same reason, a redelivered *payment* webhook resets neither a refund nor
a fee: those fields are written only when their value was actually
resolved, so a webhook that cannot resolve them leaves the stored values
alone.

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

## One payment, two webhooks

A subscription charge fires both `invoice.payment_succeeded` and
`payment_intent.succeeded` for the same movement of money. Only the invoice
is recorded: it is the row carrying tax, billing reason and period.

Recognising the pair is not as simple as it was. The Basil API (2025-03-31,
which Cashier 16 pins) removed `PaymentIntent.invoice`, along with
`Charge.invoice`. Nothing on a payment intent points at an invoice any more
— the relation is only navigable the other way, through `Invoice.payments`
— so retrieving the payment intent from Stripe would not answer it either.

The package uses its own invoice row instead, which stores the payment
intent id for exactly this join, and it works in both directions because
webhook order is not guaranteed:

-   A payment intent whose invoice is already recorded is skipped.
-   An invoice that arrives second deletes the standalone payment-intent row
    written before it.

That second rule also means **replaying the backfill cleans up duplicates
written by earlier versions** — no separate command:

```bash
php artisan cashier-tracker:backfill
```

Payment intents with no invoice, i.e. genuine one-off sales, are untouched.

## Fees

Stripe does not put the fee in the webhook payload. It lives on the
charge's **balance transaction**, which the event carries as a bare id, and
Stripe never auto-expands nested objects in webhook events — "Objects sent
in events are always in their minimal form". So the only way to know a fee
at webhook time is to fetch it.

The package does that by default: `resolve_fees_on_webhook` is `true`, so
each tracked payment costs one extra Stripe call
(`paymentIntents->retrieve` expanding `latest_charge.balance_transaction`)
inside the webhook request, and `fee` is correct immediately.

```php
// config/cashier-tracker.php
'resolve_fees_on_webhook' => env('CASHIER_TRACKER_RESOLVE_FEES', true),
```

It degrades safely. If the call fails, Stripe is unreachable, or the client
cannot even be built, the payment is still recorded with `fee = null` — the
fee is written only when it was actually resolved, so nothing is lost and a
later backfill fills it in.

**Turn it off** (`CASHIER_TRACKER_RESOLVE_FEES=false`) if webhook latency
matters more than live fees, or if you take payment methods whose balance
transaction is not created synchronously. `cashier-tracker:backfill` then
remains the way fees are resolved.

### When the fee is unknown

Whichever mode you are in, a fee can be missing — the option is off, the
call failed, or the balance transaction did not exist yet. `net_amount`
cannot tell "no fee" from "fee not known", and treats an unresolved fee as
zero:

```php
return $this->amount - ($this->fee ?? 0) - $this->refunded_amount;
```

So on such a row **`net_amount` equals the gross amount and overstates net
revenue** — roughly 1.4% + EUR 0.25 per payment on European card rates.
`netPaid()` behaves the same way via `COALESCE(SUM(fee), 0)`. Nothing looks
wrong; the fee column simply reads as empty. Two helpers make it visible:

```php
$payment->hasResolvedFee()
    ? money($payment->net_amount)
    : '—';                              // not known yet, not zero

Payment::live()->missingFee()->count(); // how much is still outstanding
```

### The weekly sweep, scheduled for you

**There is nothing to add to your app.** The package schedules
`cashier-tracker:backfill --only-missing-fees` itself, weekly by default, as
long as Laravel's scheduler is running.

```php
'reconcile_fees' => env('CASHIER_TRACKER_RECONCILE', 'weekly'),
```

`'hourly'`, `'daily'`, `'weekly'`, `'monthly'`, or `false` to turn it off
and schedule it yourself.

It runs **in production only** by default, because it makes outbound Stripe
calls: a staging or local environment pointed at a copy of the production
database with a test Stripe key would retry every unresolved fee on every
run and fail every time.

```php
'reconcile_environments' => ['production'],   // null to run everywhere
```

The two mechanisms are not redundant, and they do not duplicate work. Per
payment, exactly one Stripe request happens either way — the difference is
*when*, and what each can recover from:

| | Covers | Cannot cover |
| --- | --- | --- |
| Live, on the webhook | Essentially every payment, immediately | Anything that failed: a Stripe blip, a webhook that never arrived, a balance transaction not yet created. Those rows keep a null fee forever. |
| Weekly sweep | Exactly those leftovers | Making data fresher than its schedule |

The sweep only selects rows where `fee is null`, so a payment the webhook
already resolved is never fetched again. On a healthy installation it
matches nothing and makes no API calls at all — which is what makes it safe
to leave running.

It needs `stripe_payment_intent_id` to ask Stripe anything, and rows written
before that column existed do not have one. Those are repaired first:
payment-intent rows locally, since their `stripe_id` *is* the payment intent
id, and invoice rows by reading the invoice back from Stripe once. Whatever
still cannot be recovered is reported with the command to run, instead of
being passed over silently — a sweep reporting `0 fees resolved` on a table
full of missing fees is worse than useless.

It is not the same command as a plain `backfill`, which re-lists everything
from Stripe and re-resolves fees it already has. Reach for that (with
`--since=...`) when you have changed how data is mapped and want history
rewritten, not as routine maintenance.

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

-   With `source` set to `payment_intents` alone, a subscription payment
    intent cannot be told apart from a one-off sale on the Basil API: there
    is no invoice row to recognise it by, and the payload carries nothing
    that identifies one. It will be recorded. Use `invoices` or `both` if you
    sell subscriptions.

-   No currency conversion: a gross total adds up amounts across all
    currencies.
-   Stripe dates (`paid_at`, `period_start`, `period_end`) are resolved
    in `app.timezone`, matching the `created_at` Eloquent writes. On an
    app running a non-UTC timezone that has already moved to Carbon 3,
    rows written before this version were interpreted as UTC; replaying
    the backfill realigns the history.
-   A fee can still be unknown (option off, call failed, balance
    transaction not yet created), and `net_amount` / `netPaid()` then
    overstate net revenue. See "Fees" above.
-   Even during a backfill, fees are best-effort: `fee` stays `null` when
    the balance transaction cannot be retrieved.
-   `net_amount` is an accessor: usable on a loaded model, but not in a
    `where()`. Aggregates go through the columns (see `netPaid()`).
