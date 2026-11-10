<?php

declare(strict_types=1);

namespace App\Filament\Seller\Pages;

use App\Models\Product;
use App\Models\SellerInvoice;
use App\Models\SellerPlan;
use App\Models\SellerSubscription;
use App\Services\Monetization\MonetizationEngineService;
use App\Services\Monetization\SellerInvoiceSettlementService;
use App\Services\Monetization\SellerTermsSummary;
use App\Services\Settings\StoreConfigurationService;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SubscriptionBilling extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Subscription & billing';

    protected static ?string $title = 'Subscription & Billing';

    protected static ?string $slug = 'subscription';

    protected string $view = 'filament.seller.pages.subscription-billing';

    public function getActiveSubscription(): ?SellerSubscription
    {
        return app(MonetizationEngineService::class)->getActiveSubscription(auth()->user());
    }

    /**
     * UX-5: commission, listing limit and next payment as actually charged.
     *
     * @return array<string, mixed>
     */
    public function getTermsSummary(): array
    {
        return app(SellerTermsSummary::class)->forSeller(auth()->user());
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

    public function getPaidInvoices()
    {
        return SellerInvoice::where('seller_id', auth()->id())
            ->where('status', SellerInvoice::STATUS_PAID)
            ->latest('paid_at')
            ->take(5)
            ->get();
    }

    public function toggleAutoRenew(): void
    {
        $subscription = $this->getActiveSubscription();
        if (! $subscription) {
            return;
        }

        $subscription->update([
            'auto_renew' => ! $subscription->auto_renew,
        ]);

        Notification::make()
            ->title($subscription->auto_renew ? 'Auto-renewal enabled' : 'Auto-renewal paused')
            ->body($subscription->auto_renew ? 'Your subscription will automatically renew using available wallet balance.' : 'Auto-renewal has been paused.')
            ->success()
            ->send();
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
        try {
            $invoice = app(SellerInvoiceSettlementService::class)->startPlanChange(auth()->user(), $planId);
        } catch (DomainException $exception) {
            Notification::make()->title('Plan not changed')->body($exception->getMessage())->danger()->send();

            return;
        }

        $planName = $invoice->metadata['plan_name'] ?? 'the new plan';

        if ($invoice->status === SellerInvoice::STATUS_PAID) {
            Notification::make()->title('Subscribed to '.$planName)->success()->send();

            return;
        }

        Notification::make()
            ->title('Invoice Generated for '.$planName)
            ->body('Invoice #'.$invoice->invoice_number.' generated for ৳'.number_format((float) $invoice->total_amount, 2).'. Your current plan stays as it is until this bill is paid.')
            ->info()
            ->send();
    }

    /**
     * MON-11: what a listing pack gives and costs, or null when this seller can't buy one.
     *
     * @return array{slots: int, price: float, days: int}|null
     */
    public function getListingPackOffer(): ?array
    {
        $subscription = $this->getActiveSubscription();
        $config = app(StoreConfigurationService::class);

        if (! $config->isMonetizationEnabled() || ! $subscription?->plan?->allow_listing_addons || ! $subscription->termListingLimitEnabled() || $subscription->termMaxActiveProducts() === null) {
            return null;
        }

        return [
            'slots' => $config->getListingAddonPackSize(),
            'price' => round($config->getListingAddonPackPrice(), 2),
            'days' => SellerInvoiceSettlementService::LISTING_PACK_DAYS,
        ];
    }

    public function buyListingPack(): void
    {
        try {
            $invoice = app(SellerInvoiceSettlementService::class)->startListingPackPurchase(auth()->user());
        } catch (DomainException $exception) {
            Notification::make()->title('Listing pack not added')->body($exception->getMessage())->danger()->send();

            return;
        }

        if ($invoice->status === SellerInvoice::STATUS_PAID) {
            Notification::make()->title('Listing pack added')->success()->send();

            return;
        }

        Notification::make()
            ->title('Bill raised for your listing pack')
            ->body('Bill #'.$invoice->invoice_number.' for ৳'.number_format((float) $invoice->total_amount, 2).' is below. The extra listings start as soon as it is paid.')
            ->info()
            ->send();
    }

    public function payWithWallet(int $invoiceId): void
    {
        try {
            app(SellerInvoiceSettlementService::class)->payFromWallet(auth()->user(), $invoiceId);
        } catch (DomainException $exception) {
            Notification::make()->title('Payment not made')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Invoice Paid Successfully from Wallet')->success()->send();
    }
}
