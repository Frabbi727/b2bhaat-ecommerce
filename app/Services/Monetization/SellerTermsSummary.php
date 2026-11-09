<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\Product;
use App\Models\SellerInvoice;
use App\Models\SellerSubscription;
use App\Models\User;
use App\Services\Settings\StoreConfigurationService;
use Carbon\CarbonInterface;

/**
 * UX-5: what the seller actually pays, worked out by the same rules the order and billing code use, so the seller
 * panel never shows a fallback figure that isn't charged.
 */
class SellerTermsSummary
{
    public function __construct(
        private readonly MonetizationEngineService $engine,
        private readonly StoreConfigurationService $config,
    ) {}

    /**
     * @return array{
     *     monetization_on: bool,
     *     retail_rate: float,
     *     wholesale_rate: float,
     *     custom_rate: bool,
     *     listing_limit: ?int,
     *     listings_visible: int,
     *     next_payment_amount: ?float,
     *     next_payment_date: ?CarbonInterface,
     *     next_payment_is_bill: bool,
     *     auto_renew: bool
     * }
     */
    public function forSeller(User $seller): array
    {
        $subscription = $this->engine->getActiveSubscription($seller);
        [$amount, $date, $isBill] = $this->nextPayment($seller, $subscription);

        return [
            'monetization_on' => $this->config->isMonetizationEnabled(),
            'retail_rate' => $this->engine->sellerCommissionRate($seller, wholesale: false),
            'wholesale_rate' => $this->engine->sellerCommissionRate($seller, wholesale: true),
            'custom_rate' => $subscription !== null && ($subscription->custom_retail_commission !== null || $subscription->custom_wholesale_commission !== null),
            'listing_limit' => $this->engine->getAllowedProductCount($seller),
            'listings_visible' => Product::where('seller_id', $seller->id)->where('is_active', true)->count(),
            'next_payment_amount' => $amount,
            'next_payment_date' => $date,
            'next_payment_is_bill' => $isBill,
            'auto_renew' => (bool) ($subscription?->auto_renew ?? false),
        ];
    }

    public static function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.').'%';
    }

    /**
     * An unpaid bill comes first; otherwise the renewal, priced as the renewal command will price it (the custom
     * price, else the plan's current price, MON-2).
     *
     * @return array{0: ?float, 1: ?CarbonInterface, 2: bool}
     */
    private function nextPayment(User $seller, ?SellerSubscription $subscription): array
    {
        $bill = SellerInvoice::where('seller_id', $seller->id)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->orderBy('due_date')
            ->first();

        if ($bill) {
            return [(float) $bill->total_amount, $bill->due_date, true];
        }

        if (! $subscription?->ends_at) {
            return [null, null, false];
        }

        $price = $subscription->custom_subscription_price !== null
            ? (float) $subscription->custom_subscription_price
            : (float) ($subscription->plan?->subscription_price ?? $subscription->termSubscriptionPrice());

        return $price > 0 ? [$price, $subscription->ends_at, false] : [null, null, false];
    }
}
