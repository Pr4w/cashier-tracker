# Changelog

Notable changes per release. This package is pre-1.0, so the minor segment
carries breaking changes: `^0.2.0` will not pick up `0.3.0`.

## 0.5.1

### Fixed

-   **Every subscription payment was recorded twice** — once as an `invoice`
    row, once as a `payment_intent` row — inflating revenue, net and distinct
    customer counts on any total not filtered to `type = 'invoice'`.

    The guard that skipped a payment intent belonging to an invoice tested
    `PaymentIntent.invoice`, which the Basil API removed along with
    `Charge.invoice`. The condition was permanently false, so the guard never
    fired. Recognition now goes through the invoice row's
    `stripe_payment_intent_id`, in both directions, since webhook order is
    not guaranteed. The pre-Basil field is still honoured for Cashier 15.

    **Replay the backfill to clear duplicates already in your table:**

    ```bash
    php artisan cashier-tracker:backfill
    ```

    An invoice now deletes any standalone payment-intent row for the same
    payment, so a replay is the cleanup. Genuine one-off sales are untouched.

    Reported from production against 0.5.0.

## 0.5.0

### Changed

-   The scheduled fee sweep now runs **in production only** by default, via a
    new `reconcile_environments` key (`['production']`). It previously ran in
    every environment.

    It makes outbound Stripe calls, and a staging or local environment
    pointed at a copy of the production database with a test Stripe key would
    retry every unresolved fee on every run and fail every time — noise and
    pointless load.

    If you were relying on it running outside production, set
    `reconcile_environments` to `null` (everywhere) or list the environments
    you want.

## 0.4.1

### Added

-   The package now schedules `cashier-tracker:backfill --only-missing-fees`
    itself, weekly by default (`reconcile_fees`,
    `CASHIER_TRACKER_RECONCILE`). Nothing needs adding to the host app's
    console routes. Set it to `'hourly'`, `'daily'`, `'monthly'`, or `false`
    to schedule it yourself. Runs `withoutOverlapping()`.

    This is the safety net for what live fee resolution cannot catch. It only
    selects rows where `fee` is null, so it never re-fetches a fee the webhook
    already resolved, and makes no API calls at all when nothing is missing.

## 0.4.0

### Added

-   `cashier-tracker:backfill --only-missing-fees`: re-resolves only rows
    whose `fee` is null, without listing anything from Stripe. One API call
    per genuinely-missing fee, none otherwise.
-   Failed charge lookups are logged at debug level and counted; the backfill
    reports the total rather than failing silently.
-   `LICENSE` and this changelog.

### Changed

-   `--since` fails with a clear message on an unparseable date. `strtotime()`
    returns `false`, which is falsy, so a typo previously widened the run to
    all of history.
-   The payment-intent backfill reports imported and skipped separately. It
    previously counted every row it saw as imported, though it skips
    unsuccessful intents and any already billed by an invoice.
-   The backfill uses `Cashier::stripe()` instead of building its own client,
    inheriting Cashier's pinned Stripe API version.
-   `resolveBillable()` is memoised per run, removing one database query per
    payment during a backfill.
-   Pagination lived once per endpoint; both backfills now share one
    implementation.

### Removed

-   The `display_currency` config key, which nothing read.
    `CASHIER_TRACKER_CURRENCY` no longer has any effect.

## 0.3.0

### Changed

-   Stripe fees are now resolved on the webhook by default
    (`resolve_fees_on_webhook`, `CASHIER_TRACKER_RESOLVE_FEES`). Each tracked
    payment makes one extra Stripe API call inside the webhook request, and
    `fee` is correct immediately instead of only after a backfill. Set it to
    `false` to restore the previous behaviour.
-   `cashier-tracker:backfill` uses `Cashier::stripe()` rather than building
    its own client, so it inherits Cashier's pinned Stripe API version.
-   `--since` now fails with a clear message on an unparseable date. It
    previously fell through to "no filter" and silently imported all history.
-   The payment-intent backfill reports how many rows it skipped rather than
    counting every row it saw as imported.
-   `resolveBillable()` is memoised per run, removing one database query per
    payment during a backfill.

### Added

-   `--only-missing-fees` on the backfill: fills in fees for rows that do not
    have one, without listing anything from Stripe. Costs one API call per
    genuinely-missing fee and none at all otherwise, which makes it cheap
    enough to schedule.
-   Failed charge lookups are logged at debug level and counted; the backfill
    reports the total instead of failing silently.

### Removed

-   The `display_currency` config key, which nothing read.
    `CASHIER_TRACKER_CURRENCY` no longer has any effect.

## 0.2.1

### Fixed

-   A redelivered webhook, or a backfill pass that hit an unreachable Stripe,
    reset an already-resolved `fee` to null. Fees are now written only when
    actually resolved, matching how refunds already behaved.

### Added

-   `Payment::hasResolvedFee()` and `Payment::scopeMissingFee()`, so an
    unresolved fee is distinguishable from a genuine zero. `net_amount` treats
    an unresolved fee as zero and therefore overstates net revenue.

## 0.2.0

### Breaking

-   Requires PHP 8.3+ and Laravel 12 or 13. Laravel 11 is no longer supported:
    every 11.x release is flagged by unpatched security advisories and will not
    resolve under Composer's default policy.
-   The Composer package name changed from `Pr4w/cashier-tracker` to
    `pr4w/cashier-tracker`. The previous name failed Composer's schema, so the
    package could not be required by name at all.

### Added

-   Refund tracking via the `charge.refunded` webhook. `refunded_amount` and
    `status` were previously written by nothing, so "true net" was never net of
    refunds. Adds a `stripe_payment_intent_id` column — run `php artisan
    migrate`, then replay the backfill to correct history.
-   A Testbench test suite.
-   Laravel 13 and stripe-php 18–21 support.

### Fixed

-   Stripe timestamps resolved to different wall-clock times depending on
    whether Carbon 2 or 3 was installed.
-   `meta.subscription` was silently null on Cashier 16, which moved the field
    to `parent.subscription_details.subscription`.
-   `netPaid()` hydrated every row to sum an accessor; it is now one SQL
    aggregate.

## 0.1.1 and earlier

Initial webhook tracking, fee resolution and backfill. See the git history.
