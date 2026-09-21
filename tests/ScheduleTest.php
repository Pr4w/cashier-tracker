<?php

namespace Pr4w\CashierTracker\Tests;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class ScheduleTest extends TestCase
{
    /** @return list<string> cron expressions for our command */
    private function scheduled(): array
    {
        return array_values(array_map(
            fn ($event) => $event->expression,
            array_filter(
                $this->app->make(Schedule::class)->events(),
                fn ($event) => str_contains($event->command ?? '', 'cashier-tracker:backfill')
            )
        ));
    }

    #[Test]
    public function the_shipped_default_is_weekly(): void
    {
        $config = require __DIR__ . '/../config/cashier-tracker.php';

        $this->assertSame('weekly', $config['reconcile_fees']);
    }

    #[Test]
    public function it_schedules_the_sweep_without_the_app_wiring_anything(): void
    {
        $this->assertSame(['0 0 * * 0'], $this->scheduled());
    }

    #[Test]
    public function it_schedules_only_the_missing_fee_sweep_not_a_full_backfill(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'cashier-tracker:backfill'));

        $this->assertStringContainsString('--only-missing-fees', $event->command);
    }

    public static function frequencies(): array
    {
        return [
            'hourly'  => ['hourly', '0 * * * *'],
            'daily'   => ['daily', '0 0 * * *'],
            'weekly'  => ['weekly', '0 0 * * 0'],
            'monthly' => ['monthly', '0 0 1 * *'],
        ];
    }

    #[Test]
    #[DataProvider('frequencies')]
    public function the_frequency_is_configurable(string $frequency, string $expression): void
    {
        $this->refreshApplicationWith(['cashier-tracker.reconcile_fees' => $frequency]);

        $this->assertSame([$expression], $this->scheduled());
    }

    #[Test]
    public function setting_it_to_false_disables_it(): void
    {
        $this->refreshApplicationWith(['cashier-tracker.reconcile_fees' => false]);

        $this->assertSame([], $this->scheduled());
    }

    #[Test]
    public function an_unknown_frequency_is_ignored_rather_than_fatal(): void
    {
        // A typo in config must not take the whole application down: an
        // unknown frequency would otherwise be a call to an undefined method
        // on the schedule. Booting at all is most of the assertion. (The
        // provider also logs a warning, which cannot be spied on here because
        // it happens during boot, before a spy could be installed.)
        $this->refreshApplicationWith(['cashier-tracker.reconcile_fees' => 'fortnightly']);

        $this->assertSame([], $this->scheduled());
    }

    /**
     * The schedule is registered during boot, so the config has to be in place
     * before the application starts rather than set afterwards.
     */
    private function refreshApplicationWith(array $config): void
    {
        $this->overrideConfig = $config;

        $this->refreshApplication();
    }
}
