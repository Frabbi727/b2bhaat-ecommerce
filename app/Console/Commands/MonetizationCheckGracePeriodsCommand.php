<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\OrderNotificationService;
use Illuminate\Console\Command;

class MonetizationCheckGracePeriodsCommand extends Command
{
    protected $signature = 'monetization:check-grace-periods';

    protected $description = 'Identify overdue subscriptions and transition them to grace period';

    public function handle(
        StoreConfigurationService $config,
        OrderNotificationService $notifications
    ): int {
        if (! $config->isMonetizationEnabled()) {
            return self::SUCCESS;
        }

        $graceDays = $config->getMonetizationGracePeriodDays();

        // Subscriptions that have passed their ends_at, are still 'active', and have an unpaid invoice
        $overdueSubscriptions = SellerSubscription::with('seller.sellerProfile')
            ->where('status', SellerSubscription::STATUS_ACTIVE)
            ->where('ends_at', '<', now())
            ->get();

        $count = 0;

        foreach ($overdueSubscriptions as $subscription) {
            $seller = $subscription->seller;
            if (! $seller) {
                continue;
            }

            $hasUnpaidInvoice = SellerInvoice::where('subscription_id', $subscription->id)
                ->where('status', SellerInvoice::STATUS_UNPAID)
                ->exists();

            if ($hasUnpaidInvoice) {
                $graceEnds = now()->addDays($graceDays);

                $subscription->update([
                    'status' => SellerSubscription::STATUS_GRACE_PERIOD,
                    'grace_period_ends_at' => $graceEnds,
                ]);

                $seller->sellerProfile?->update([
                    'monetization_status' => 'grace_period',
                ]);

                $notifications->sendGracePeriodWarning($seller, $subscription, $graceDays);
                $count++;
            }
        }

        $this->info("Moved {$count} subscriptions to grace period.");

        return self::SUCCESS;
    }
}
