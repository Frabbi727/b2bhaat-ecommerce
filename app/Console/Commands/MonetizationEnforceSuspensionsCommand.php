<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Services\Monetization\MonetizationSuspensionService;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\OrderNotificationService;
use Illuminate\Console\Command;

class MonetizationEnforceSuspensionsCommand extends Command
{
    protected $signature = 'monetization:enforce-suspensions';

    protected $description = 'Enforce suspensions for sellers whose grace period has expired';

    public function handle(
        StoreConfigurationService $config,
        MonetizationSuspensionService $suspensionService,
        OrderNotificationService $notifications
    ): int {
        if (! $config->isMonetizationEnabled()) {
            return self::SUCCESS;
        }

        // Subscriptions in grace period where grace_period_ends_at < now() and invoice is still unpaid
        $expiredGraceSubscriptions = SellerSubscription::with('seller.sellerProfile')
            ->where('status', SellerSubscription::STATUS_GRACE_PERIOD)
            ->where('grace_period_ends_at', '<', now())
            ->get();

        $count = 0;

        foreach ($expiredGraceSubscriptions as $subscription) {
            $seller = $subscription->seller;
            if (! $seller) {
                continue;
            }

            $hasUnpaidInvoice = SellerInvoice::where('subscription_id', $subscription->id)
                ->where('status', SellerInvoice::STATUS_UNPAID)
                ->exists();

            if ($hasUnpaidInvoice) {
                $reason = "Subscription past grace period (expired on {$subscription->grace_period_ends_at?->format('d M Y')})";

                $suspensionService->suspendSeller($seller, $reason, null);
                $notifications->sendAccountSuspendedNotice($seller, $reason);
                $count++;
            }
        }

        $this->info("Suspended {$count} delinquent sellers.");

        return self::SUCCESS;
    }
}
