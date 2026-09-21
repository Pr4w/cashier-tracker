# Changelog

Notable changes per release. This package is pre-1.0, so the minor segment
carries breaking changes: `^0.2.0` will not pick up `0.3.0`.

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
