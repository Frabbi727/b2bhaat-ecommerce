<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * PERF-5: people see Dhaka time in the panels and the daily jobs run at Dhaka times, without changing the timezone
 * the database values are stored in (changing that on a live database would shift every stored time).
 */
class DisplayTimezoneTest extends TestCase
{
    public function test_the_panels_show_dhaka_time(): void
    {
        $this->assertSame('Asia/Dhaka', config('app.display_timezone'));
        $this->assertSame('Asia/Dhaka', FilamentTimezone::get());
    }

    public function test_daily_jobs_run_on_dhaka_time(): void
    {
        $daily = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'monetization:process-renewals')
                || str_contains((string) $event->command, 'monetization:check-grace-periods')
                || str_contains((string) $event->command, 'audit:prune'));

        $this->assertCount(3, $daily);
        foreach ($daily as $event) {
            $this->assertSame('Asia/Dhaka', (string) $event->timezone, $event->command);
        }
    }
}
