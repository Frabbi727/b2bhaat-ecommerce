<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatically check and release delivered escrow funds every hour
Schedule::command('escrow:release-delivered')->hourly();

// Automatically cancel expired unpaid reservations and release stock every 5 minutes
Schedule::command('inventory:release-expired-reservations')->everyFiveMinutes();

// Automatically synchronize active flash sales, banners, and coupon promotions hourly
Schedule::command('promotions:sync-active-campaigns')->hourly();
