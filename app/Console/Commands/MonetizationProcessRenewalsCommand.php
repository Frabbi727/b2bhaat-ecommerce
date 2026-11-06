<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\WalletTransaction;
use App\Services\Monetization\MonetizationSuspensionService;
use App\Services\Settings\StoreConfigurationService;
use App\Services\Sms\OrderNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

            $price = $subscription->custom_subscription_price ?? $subscription->plan?->subscription_price ?? 0.00;

            if (! $existingInvoice) {
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
                ]);
            } else {
                $invoice = $existingInvoice;
            }

            // Free plan or 0 price automatically renews
            if ((float) $price <= 0.00) {
                $cycleDays = $subscription->plan?->billing_cycle_days ?: 365;
                $subscription->update([
                    'ends_at' => $subscription->ends_at->addDays($cycleDays),
                    'status' => SellerSubscription::STATUS_ACTIVE,
                ]);
                $processedCount++;

                continue;
            }

            // If auto-renew with wallet balance is enabled and seller has sufficient funds
            $wallet = $seller->sellerWallet;
            if ($subscription->auto_renew && $config->isWalletAutoRenewAllowed() && $wallet && (float) $wallet->available_balance >= (float) $invoice->total_amount) {
                DB::transaction(function () use ($wallet, $invoice, $subscription, $seller): void {
                    $lockedWallet = SellerWallet::where('id', $wallet->id)->lockForUpdate()->firstOrFail();
                    $lockedWallet->decrement('available_balance', (float) $invoice->total_amount);

                    WalletTransaction::create([
                        'seller_wallet_id' => $lockedWallet->id,
                        'transaction_type' => 'subscription_payment',
                        'status' => 'disbursed',
                        'amount' => (float) $invoice->total_amount,
                        'running_balance' => (float) $lockedWallet->available_balance,
                        'reference_note' => "Auto-renewed subscription for Invoice #{$invoice->invoice_number}",
                    ]);

                    SellerPayment::create([
                        'invoice_id' => $invoice->id,
                        'seller_id' => $seller->id,
                        'amount' => (float) $invoice->total_amount,
                        'payment_method' => SellerPayment::METHOD_WALLET_BALANCE,
                        'transaction_reference' => "AUTO-WALLET-{$lockedWallet->id}-".time(),
                        'status' => SellerPayment::STATUS_SUCCESSFUL,
                        'verified_at' => now(),
                    ]);

                    $invoice->update([
                        'status' => SellerInvoice::STATUS_PAID,
                        'paid_at' => now(),
                    ]);

                    $cycleDays = $subscription->plan?->billing_cycle_days ?: 30;
                    $subscription->update([
                        'ends_at' => $subscription->ends_at->addDays($cycleDays),
                        'status' => SellerSubscription::STATUS_ACTIVE,
                    ]);
                });

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
