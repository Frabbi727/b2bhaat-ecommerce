<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages;

use App\Models\Product;
use App\Models\SellerInvoice;
use App\Models\SellerPayment;
use App\Models\SellerPlan;
use App\Models\SellerSubscription;
use App\Models\SellerWallet;
use App\Models\WalletTransaction;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Monetization\MonetizationSuspensionService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

class SubscriptionBilling extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static UnitEnum|string|null $navigationGroup = 'Store';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Subscription & Billing';

    protected static ?string $title = 'Subscription & Billing';

    protected static ?string $slug = 'subscription';

    protected string $view = 'filament.seller.pages.subscription-billing';

    public function getActiveSubscription(): ?SellerSubscription
    {
        return app(MonetizationEngineService::class)->getActiveSubscription(auth()->user());
    }

    public function getUsedProductSlots(): int
    {
        return Product::where('seller_id', auth()->id())
            ->where('is_active', true)
            ->count();
    }

    public function getAllowedProductSlots(): ?int
    {
        return app(MonetizationEngineService::class)->getAllowedProductCount(auth()->user());
    }

    public function getPendingInvoices()
    {
        return SellerInvoice::where('seller_id', auth()->id())
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->latest('id')
            ->get();
    }

    public function getAvailablePlans()
    {
        return SellerPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function getWalletBalance(): float
    {
        return (float) (auth()->user()->sellerWallet?->available_balance ?? 0.00);
    }

    public function upgradePlan(int $planId): void
    {
        $plan = SellerPlan::findOrFail($planId);
        $user = auth()->user();

        $invoice = SellerInvoice::create([
            'invoice_number' => 'SINV-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'seller_id' => $user->id,
            'subscription_id' => $this->getActiveSubscription()?->id,
            'invoice_type' => SellerInvoice::TYPE_SUBSCRIPTION_FEE,
            'subtotal' => (float) $plan->subscription_price,
            'total_amount' => (float) $plan->subscription_price,
            'status' => (float) $plan->subscription_price <= 0.00 ? SellerInvoice::STATUS_PAID : SellerInvoice::STATUS_UNPAID,
            'due_date' => now()->toDateString(),
            'paid_at' => (float) $plan->subscription_price <= 0.00 ? now() : null,
            'metadata' => ['target_plan_id' => $plan->id, 'plan_name' => $plan->name],
        ]);

        if ((float) $plan->subscription_price <= 0.00) {
            $this->applyPlanSubscription($user, $plan);
            Notification::make()->title('Subscribed to '.$plan->name)->success()->send();

            return;
        }

        Notification::make()
            ->title('Invoice Generated for '.$plan->name)
            ->body('Invoice #'.$invoice->invoice_number.' generated for ৳'.number_format((float) $invoice->total_amount, 2).'. Please settle payment to activate.')
            ->info()
            ->send();
    }

    public function payWithWallet(int $invoiceId): void
    {
        $invoice = SellerInvoice::where('seller_id', auth()->id())->findOrFail($invoiceId);
        $seller = auth()->user();

        DB::transaction(function () use ($invoice, $seller): void {
            /** @var SellerWallet $wallet */
            $wallet = SellerWallet::where('seller_id', $seller->id)->lockForUpdate()->firstOrFail();

            if ((float) $wallet->available_balance < (float) $invoice->total_amount) {
                throw new \Exception('Insufficient wallet balance. Required: ৳'.number_format((float) $invoice->total_amount, 2).', Available: ৳'.number_format((float) $wallet->available_balance, 2));
            }

            $wallet->decrement('available_balance', (float) $invoice->total_amount);
            $wallet->refresh();

            WalletTransaction::create([
                'seller_wallet_id' => $wallet->id,
                'transaction_type' => 'subscription_payment',
                'status' => 'disbursed',
                'amount' => (float) $invoice->total_amount,
                'running_balance' => (float) $wallet->available_balance,
                'reference_note' => "Platform billing fee settled for Invoice #{$invoice->invoice_number}",
            ]);

            SellerPayment::create([
                'invoice_id' => $invoice->id,
                'seller_id' => $seller->id,
                'amount' => (float) $invoice->total_amount,
                'payment_method' => SellerPayment::METHOD_WALLET_BALANCE,
                'transaction_reference' => "WALLET-OFFSET-{$wallet->id}-".time(),
                'status' => SellerPayment::STATUS_SUCCESSFUL,
                'verified_at' => now(),
            ]);

            $invoice->update([
                'status' => SellerInvoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

            // If this was an upgrade invoice, apply the target plan
            $targetPlanId = $invoice->metadata['target_plan_id'] ?? null;
            if ($targetPlanId) {
                $targetPlan = SellerPlan::find($targetPlanId);
                if ($targetPlan) {
                    $this->applyPlanSubscription($seller, $targetPlan);
                }
            } else {
                // Renew existing subscription
                $sub = $this->getActiveSubscription();
                if ($sub) {
                    $cycleDays = $sub->plan?->billing_cycle_days ?? 30;
                    $sub->update([
                        'ends_at' => now()->addDays($cycleDays),
                        'status' => SellerSubscription::STATUS_ACTIVE,
                    ]);
                }
            }

            // Restore if seller was suspended
            if ($seller->sellerProfile?->monetization_status === 'suspended') {
                app(MonetizationSuspensionService::class)->restoreSeller($seller);
            }
        });

        Notification::make()->title('Invoice Paid Successfully from Wallet')->success()->send();
    }

    protected function applyPlanSubscription($seller, SellerPlan $plan): void
    {
        SellerSubscription::create([
            'seller_id' => $seller->id,
            'plan_id' => $plan->id,
            'status' => SellerSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan->billing_cycle_days ?: 30),
            'auto_renew' => true,
        ]);

        if ($seller->sellerProfile?->monetization_status === 'suspended') {
            app(MonetizationSuspensionService::class)->restoreSeller($seller);
        }
    }
}
