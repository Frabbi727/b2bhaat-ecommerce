<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatically check and release delivered escrow funds every hour
Schedule::command('escrow:release-delivered')->hourly()->withoutOverlapping()->onOneServer();

// Automatically cancel expired unpaid reservations and release stock every 5 minutes
Schedule::command('inventory:release-expired-reservations')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Automatically synchronize flash sale campaign statuses every minute
Schedule::command('flash-sales:sync-statuses')->everyMinute()->withoutOverlapping()->onOneServer();

// Automatically synchronize active banners and coupon promotions hourly
Schedule::command('promotions:sync-active-campaigns')->hourly()->withoutOverlapping()->onOneServer();

// Monetization automation: Daily renewals at 00:05
Schedule::command('monetization:process-renewals')->dailyAt('00:05')->withoutOverlapping()->onOneServer();

// Monetization automation: Daily grace period check at 01:00
Schedule::command('monetization:check-grace-periods')->dailyAt('01:00')->withoutOverlapping()->onOneServer();

// Monetization automation: Hourly suspension enforcement for expired grace accounts
Schedule::command('monetization:enforce-suspensions')->hourly()->withoutOverlapping()->onOneServer();

// Automatically prune historical audit logs older than retention period (default 180 days)
Schedule::command('audit:prune --force')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
