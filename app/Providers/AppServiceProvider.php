<?php

namespace App\Providers;

use App\Events\Finance\PayoutStatusUpdatedEvent;
use App\Events\Finance\RefundSettledEvent;
use App\Events\Orders\ConsignmentStatusUpdatedEvent;
use App\Events\Orders\OrderPlacedEvent;
use App\Events\Promotions\FlashSaleSubmissionDecidedEvent;
use App\Events\Sellers\SellerKycDecidedEvent;
use App\Events\Sellers\SellerRegisteredEvent;
use App\Events\Sellers\WholesaleKycDecidedEvent;
use App\Listeners\Audit\AuthAuditListener;
use App\Listeners\Finance\SendPayoutStatusEmailListener;
use App\Listeners\Finance\SendRefundSettledEmailListener;
use App\Listeners\Orders\SendOrderConfirmationEmailListener;
use App\Listeners\Orders\SendParcelTrackingEmailListener;
use App\Listeners\Orders\SendSellerNewConsignmentEmailListener;
use App\Listeners\Promotions\SendFlashSaleSubmissionEmailListener;
use App\Listeners\Sellers\SendSellerKycDecisionEmailListener;
use App\Listeners\Sellers\SendSellerRegistrationEmailListener;
use App\Listeners\Sellers\SendWholesaleDecisionEmailListener;
use App\Models\AuditLog;
use App\Policies\AuditLogPolicy;
use App\Services\Accounting\AccountResolverService;
use App\Services\Audit\AuditContext;
use App\Services\Promotions\FlashSalePricing;
use App\Services\Sms\Contracts\SmsGatewayInterface;
use App\Services\Sms\SmsManager;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        $this->app->scoped(AuditContext::class);

        $this->app->scoped(AccountResolverService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('public-lookup', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        Event::listen(OrderPlacedEvent::class, SendOrderConfirmationEmailListener::class);
        Event::listen(OrderPlacedEvent::class, SendSellerNewConsignmentEmailListener::class);
        Event::listen(ConsignmentStatusUpdatedEvent::class, SendParcelTrackingEmailListener::class);
        Event::listen(RefundSettledEvent::class, SendRefundSettledEmailListener::class);
        Event::listen(PayoutStatusUpdatedEvent::class, SendPayoutStatusEmailListener::class);
        Event::listen(SellerRegisteredEvent::class, SendSellerRegistrationEmailListener::class);
        Event::listen(SellerKycDecidedEvent::class, SendSellerKycDecisionEmailListener::class);
        Event::listen(WholesaleKycDecidedEvent::class, SendWholesaleDecisionEmailListener::class);
        Event::listen(FlashSaleSubmissionDecidedEvent::class, SendFlashSaleSubmissionEmailListener::class);
        Event::listen(Login::class, [AuthAuditListener::class, 'handleLogin']);
        Event::listen(Failed::class, [AuthAuditListener::class, 'handleFailed']);
        Event::listen(Logout::class, [AuthAuditListener::class, 'handleLogout']);
    }
}
