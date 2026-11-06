<?php

declare(strict_types=1);

namespace App\Services\Monetization;

use App\Models\Product;
use App\Models\SellerSubscription;
use App\Models\SellerSuspensionEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MonetizationSuspensionService
{
    public function __construct(
        protected MonetizationEngineService $monetizationEngine
    ) {}

    /**
     * Suspend a seller's selling privileges, snapshot active products, and hide them from the storefront.
     */
    public function suspendSeller(User $seller, string $reason, ?User $actor = null): SellerSuspensionEvent
    {
        return DB::transaction(function () use ($seller, $reason, $actor): SellerSuspensionEvent {
            $activeProductIds = Product::where('seller_id', $seller->id)
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            // 1. Record immutable suspension event snapshot
            $event = SellerSuspensionEvent::create([
                'seller_id' => $seller->id,
                'trigger_type' => $actor ? SellerSuspensionEvent::TRIGGER_ADMIN_MANUAL : SellerSuspensionEvent::TRIGGER_AUTOMATED_BILLING,
                'snapshot_active_product_ids' => $activeProductIds,
                'suspended_at' => now(),
                'reason' => $reason,
                'action_by_user_id' => $actor?->id,
            ]);

            // 2. Mark snapshot products with flag and turn off active
            if (! empty($activeProductIds)) {
                Product::whereIn('id', $activeProductIds)->update(['was_active_before_suspension' => true]);
            }
            Product::where('seller_id', $seller->id)->update(['is_active' => false]);

            // 3. Update seller profile monetization status
            $seller->sellerProfile()->update(['monetization_status' => 'suspended']);

            // 4. Update subscription status if exists
            $subscription = $this->monetizationEngine->getActiveSubscription($seller);
            if ($subscription) {
                $subscription->update(['status' => SellerSubscription::STATUS_SUSPENDED]);
            }

            return $event;
        });
    }

    /**
     * Safely restore seller products that were previously active and are not blocked by admin moderation.
     *
     * @return array<int> IDs of restored products
     */
    public function restoreSeller(User $seller, ?User $actor = null): array
    {
        return DB::transaction(function () use ($seller): array {
            $lastEvent = SellerSuspensionEvent::where('seller_id', $seller->id)
                ->whereNull('restored_at')
                ->latest('id')
                ->first();

            $snapshotIds = $lastEvent ? ($lastEvent->snapshot_active_product_ids ?? []) : [];

            // Eligible products: must have been active in snapshot, and NOT blocked by admin moderation
            $eligibleProductsQuery = Product::where('seller_id', $seller->id)
                ->whereIn('id', $snapshotIds)
                ->whereNull('blocked_at');

            // Quota limit respect
            $quota = $this->monetizationEngine->getAllowedProductCount($seller);
            if ($quota !== null) {
                $eligibleProductsQuery->limit($quota);
            }

            $productsToRestore = $eligibleProductsQuery->pluck('id')->map(fn ($id) => (int) $id)->toArray();

            // 1. Activate eligible products
            if (! empty($productsToRestore)) {
                Product::whereIn('id', $productsToRestore)->update([
                    'is_active' => true,
                    'was_active_before_suspension' => false,
                ]);
            }

            // 2. Clean flags on remaining products
            Product::where('seller_id', $seller->id)->update(['was_active_before_suspension' => false]);

            // 3. Update seller profile monetization status
            $seller->sellerProfile()->update(['monetization_status' => 'active']);

            // 4. Update subscription status back to active if was suspended
            $suspendedSub = SellerSubscription::where('seller_id', $seller->id)
                ->where('status', SellerSubscription::STATUS_SUSPENDED)
                ->latest('id')
                ->first();

            if ($suspendedSub) {
                $suspendedSub->update(['status' => SellerSubscription::STATUS_ACTIVE]);
            }

            // 5. Update event audit log
            if ($lastEvent) {
                $lastEvent->update([
                    'restored_at' => now(),
                    'restored_product_ids' => $productsToRestore,
                ]);
            }

            return $productsToRestore;
        });
    }
}
