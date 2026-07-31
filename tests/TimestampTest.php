<?php

namespace Pr4w\CashierTracker\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pr4w\CashierTracker\Tests\Support\PaymentDataHarness;

/**
 * Carbon 2 resolved bare timestamps in the app timezone and Carbon 3 in UTC,
 * so an unpinned createFromTimestamp() stored a different wall-clock time
 * depending on which Carbon the host app happened to have.
 */
class TimestampTest extends TestCase
{
    public static function timezones(): array
    {
        return [
            // 1767225600 is 2026-01-01 00:00:00 UTC
            'UTC'              => ['UTC', '2026-01-01 00:00:00'],
            'ahead of UTC'     => ['Europe/Paris', '2026-01-01 01:00:00'],
            'behind UTC'       => ['America/New_York', '2025-12-31 19:00:00'],
        ];
    }

    #[Test]
    #[DataProvider('timezones')]
    public function it_resolves_stripe_timestamps_in_the_app_timezone(string $tz, string $expected): void
    {
        config(['app.timezone' => $tz]);

        $date = (new PaymentDataHarness)->timestampToDate(1767225600);

        $this->assertSame($expected, $date->toDateTimeString());
        $this->assertSame($tz, $date->getTimezone()->getName());
    }

    #[Test]
    public function it_falls_back_to_utc_when_the_app_timezone_is_unset(): void
    {
        config(['app.timezone' => null]);

        $date = (new PaymentDataHarness)->timestampToDate(1767225600);

        $this->assertSame('2026-01-01 00:00:00', $date->toDateTimeString());
        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    #[Test]
    public function it_prefers_the_paid_at_transition_over_the_created_timestamp(): void
    {
        config(['app.timezone' => 'UTC']);

        (new PaymentDataHarness)->storeInvoice($this->invoicePayload([
            'created'            => 1767225600,               // 2026-01-01
            'status_transitions' => ['paid_at' => 1767312000], // 2026-01-02
        ]));

        $this->assertSame('2026-01-02 00:00:00', $this->payment()->paid_at->toDateTimeString());
    }

    #[Test]
    public function it_stores_the_billing_period_when_present(): void
    {
        config(['app.timezone' => 'UTC']);

        (new PaymentDataHarness)->storeInvoice($this->invoicePayload([
            'period_start' => 1767225600,
            'period_end'   => 1769904000,
        ]));

        $payment = $this->payment();

        $this->assertSame('2026-01-01 00:00:00', $payment->period_start->toDateTimeString());
        $this->assertSame('2026-02-01 00:00:00', $payment->period_end->toDateTimeString());
    }

    #[Test]
    public function it_leaves_the_period_null_when_absent(): void
    {
        (new PaymentDataHarness)->storeInvoice($this->invoicePayload());

        $this->assertNull($this->payment()->period_start);
        $this->assertNull($this->payment()->period_end);
    }
}
