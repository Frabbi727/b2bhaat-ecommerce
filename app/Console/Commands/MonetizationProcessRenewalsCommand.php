<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Services\Monetization\MonetizationSuspensionService;
use App\Services\Monetization\SellerInvoiceSettlementService;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\OrderNotificationService;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class MonetizationProcessRenewalsCommand extends Command
{
    protected $signature = 'monetization:process-renewals';

    protected $description = 'Process expiring seller subscriptions, generate renewal invoices, and auto-renew via wallet balance';

    public function handle(
        StoreConfigurationService $config,
        OrderNotificationService $notifications,
        MonetizationSuspensionService $suspensionService
    ): int {
        if (! $config->isMonetizationEnabled()) {
            $this->info('Monetization engine is currently disabled.');

            return self::SUCCESS;
        }

        $noticeDays = $config->getMonetizationPreExpiryNoticeDays();
        $targetDate = now()->addDays($noticeDays);

        // Find active subscriptions expiring soon that do not already have an open invoice
        $expiringSubscriptions = SellerSubscription::with(['seller.sellerWallet', 'plan'])
            ->where('status', SellerSubscription::STATUS_ACTIVE)
            ->where('ends_at', '<=', $targetDate)
            ->get();

        $processedCount = 0;

        foreach ($expiringSubscriptions as $subscription) {
            $seller = $subscription->seller;
            if (! $seller) {
                continue;
            }

            // Check if an unpaid invoice already exists for this subscription
            $existingInvoice = SellerInvoice::where('subscription_id', $subscription->id)
                ->where('status', SellerInvoice::STATUS_UNPAID)
                ->first();

            // The next period runs on the plan's terms as they are now; edits made during the current period take
            // effect here (MON-2). An admin's custom price still wins.
            $renewalTerms = $subscription->plan ? SellerSubscription::termsFromPlan($subscription->plan) : null;
            $price = $subscription->custom_subscription_price ?? $renewalTerms['term_subscription_price'] ?? $subscription->termSubscriptionPrice();

            // One bill per subscription period, even if the command runs twice (MON-10).
            $periodKey = SellerInvoice::renewalPeriodKey($subscription->id, $subscription->ends_at->toDateString());
            $existingInvoice ??= SellerInvoice::where('billing_period_key', $periodKey)->first();

            if ($existingInvoice && $existingInvoice->status !== SellerInvoice::STATUS_UNPAID && (float) $price > 0.00) {
                $this->warn("Subscription #{$subscription->id}: this period is already billed (#{$existingInvoice->invoice_number}, {$existingInvoice->status}); no new bill raised.");

                continue;
            }

            if (! $existingInvoice) {
                try {
                    $invoice = SellerInvoice::create([
                        'invoice_number' => 'SINV-'.date('Ymd').'-'.strtoupper(Str::random(6)),
                        'seller_id' => $seller->id,
                        'subscription_id' => $subscription->id,
                        'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
                        'subtotal' => (float) $price,
                        'total_amount' => (float) $price,
                        'status' => (float) $price <= 0.00 ? SellerInvoice::STATUS_PAID : SellerInvoice::STATUS_UNPAID,
                        'due_date' => $subscription->ends_at->toDateString(),
                        'paid_at' => (float) $price <= 0.00 ? now() : null,
                        'metadata' => $renewalTerms ? ['renewal_terms' => Arr::except($renewalTerms, 'terms_captured_at')] : null,
                        'billing_period_key' => $periodKey,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // Another run billed this period a moment ago; use that bill.
                    $invoice = SellerInvoice::where('billing_period_key', $periodKey)->firstOrFail();
                }
            } else {
                $invoice = $existingInvoice;
            }

            // Free plan or 0 price automatically renews
            if ((float) $price <= 0.00) {
                if ($renewalTerms) {
                    $subscription->forceFill($renewalTerms);
                }
                $cycleDays = $subscription->termBillingCycleDays() ?: 365;
                $subscription->forceFill([
                    'ends_at' => $subscription->ends_at->addDays($cycleDays),
                    'status' => SellerSubscription::STATUS_ACTIVE,
                ])->save();
                $processedCount++;

                continue;
            }

            // If auto-renew with wallet balance is enabled and seller has sufficient funds
            $wallet = $seller->sellerWallet;
            if ($subscription->auto_renew && $config->isWalletAutoRenewAllowed() && $wallet && (float) $wallet->available_balance >= (float) $invoice->total_amount) {
                try {
                    app(SellerInvoiceSettlementService::class)->payFromWallet(
                        $seller,
                        $invoice->id,
                        'AUTO-WALLET',
                        "Auto-renewed subscription for Invoice #{$invoice->invoice_number}"
                    );
                } catch (DomainException) {
                    $notifications->sendSubscriptionRenewalReminder($seller, $subscription, $invoice);

                    continue;
                }

                $notifications->sendSubscriptionRenewalReminder($seller, $subscription, $invoice);
                $processedCount++;
            } else {
                // Send reminder SMS
                $notifications->sendSubscriptionRenewalReminder($seller, $subscription, $invoice);
            }
        }

        $this->info("Processed {$processedCount} subscription renewals.");

        return self::SUCCESS;
    }
}
