<?php

namespace App\Providers;

use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\SmsManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('sms', function ($app) {
            return new SmsManager($app);
        });

        $this->app->bind(SmsGatewayInterface::class, function ($app) {
            return $app['sms']->driver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
