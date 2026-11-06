<?php

namespace App\Providers;

use App\Services\Promotions\FlashSalePricing;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\SmsManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every panel record has a numeric id. Anything else (e.g. /seller/sub-orders/create, which has no page)
        // is a plain 404 instead of PostgreSQL rejecting the text as a bigint and showing a 500.
        // Set in register() because the panel routes are created before boot() runs.
        Route::pattern('record', '[0-9]+');

        $this->app->singleton('sms', function ($app) {
            return new SmsManager($app);
        });

        $this->app->bind(SmsGatewayInterface::class, function ($app) {
            return $app['sms']->driver();
        });

        // One instance per request (and per queued job), so remembered campaign offers never go stale across requests
        $this->app->scoped(FlashSalePricing::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('public-lookup', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
