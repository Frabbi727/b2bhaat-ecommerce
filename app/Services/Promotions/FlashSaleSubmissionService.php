<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Enums\SellerEligibilityType;
use App\Exceptions\FlashSaleSubmissionException;
use App\Models\FlashSale;
use App\Models\FlashSaleProduct;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FlashSaleSubmissionService
{
    /**
     * Submit a seller product to a flash sale campaign with validation.
     *
     * @param  array{
     *     product_id?: int,
     *     product?: Product,
     *     discount_type: FlashSaleDiscountType|string,
     *     discount_value: float|int|string,
     *     stock_limit?: int|null,
     *     campaign_stock?: int|null,
     *     per_customer_limit?: int|null,
     *     sort_order?: int
     * }  $payload
     */
    public function submit(User $seller, FlashSale $campaign, array $payload): FlashSaleProduct
    {
        return DB::transaction(function () use ($seller, $campaign, $payload): FlashSaleProduct {
            $productId = $payload['product_id'] ?? null;
            if (! isset($payload['product']) && ! $productId) {
                throw new FlashSaleSubmissionException('Product ID is required.');
            }
            /** @var Product $product */
            $product = $payload['product'] ?? Product::findOrFail($productId);

            if ((int) $product->seller_id !== (int) $seller->id) {
                throw new FlashSaleSubmissionException('You can only submit your own products.');
            }

            if (! $campaign->isRegistrationOpen()) {
                throw new FlashSaleSubmissionException('Registration is closed for this campaign.');
            }

            if ($campaign->seller_eligibility_type === SellerEligibilityType::VerifiedOnly) {
                if (! ($seller->sellerProfile?->is_approved ?? false)) {
                    throw new FlashSaleSubmissionException('Only KYC-verified sellers can join this campaign.');
                }
            }

            $eligibleCategoryIds = $campaign->categories()->pluck('categories.id')->map(fn ($id) => (int) $id)->all();
            if (! empty($eligibleCategoryIds) && ! in_array((int) $product->category_id, $eligibleCategoryIds, true)) {
                throw new FlashSaleSubmissionException('This product does not belong to an eligible campaign category.');
            }

            if ($campaign->max_products_per_seller !== null && $campaign->max_products_per_seller > 0) {
                $existingCount = FlashSaleProduct::where('flash_sale_id', $campaign->id)
                    ->where('seller_id', $seller->id)
                    ->whereIn('status', [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::Approved])
                    ->count();

                if ($existingCount >= (int) $campaign->max_products_per_seller) {
                    throw new FlashSaleSubmissionException("You have reached the maximum limit of {$campaign->max_products_per_seller} products for this campaign.");
                }
            }

            if (FlashSaleProduct::where('flash_sale_id', $campaign->id)->where('product_id', $product->id)->exists()) {
                throw new FlashSaleSubmissionException('This product has already been submitted to this campaign.');
            }

            $retailPriceRecord = $product->prices()
                ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
                ->whereNull('variation_id')
                ->first()
                ?? $product->prices()
                    ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
                    ->first();

            $basePrice = $retailPriceRecord ? (float) $retailPriceRecord->unit_price : 0.0;

            if ($basePrice <= 0) {
                throw new FlashSaleSubmissionException('Product must have a valid retail price.');
            }

            $discountType = $payload['discount_type'] instanceof FlashSaleDiscountType
                ? $payload['discount_type']
                : FlashSaleDiscountType::from((string) $payload['discount_type']);

            $discountValue = (float) $payload['discount_value'];

            $campaignPrice = match ($discountType) {
                FlashSaleDiscountType::Percentage => $basePrice * (1 - ($discountValue / 100)),
                FlashSaleDiscountType::FixedAmount => $basePrice - $discountValue,
                FlashSaleDiscountType::FixedPrice => $discountValue,
            };

            $campaignPrice = round($campaignPrice, 2);
            if ($campaignPrice <= 0) {
                throw new FlashSaleSubmissionException('Campaign price must be greater than zero.');
            }
            if ($campaignPrice >= $basePrice) {
                throw new FlashSaleSubmissionException('Campaign price must be lower than the original retail price.');
            }

            $effectiveDiscountPercentage = round((($basePrice - $campaignPrice) / $basePrice) * 100, 2);

            if ($campaign->min_discount_percentage !== null && $effectiveDiscountPercentage < (float) $campaign->min_discount_percentage) {
                throw new FlashSaleSubmissionException("Discount must be at least {$campaign->min_discount_percentage}%.");
            }

            if ($campaign->max_discount_percentage !== null && $effectiveDiscountPercentage > (float) $campaign->max_discount_percentage) {
                throw new FlashSaleSubmissionException("Discount cannot exceed {$campaign->max_discount_percentage}%.");
            }

            $allocatedStock = (int) ($payload['stock_limit'] ?? $payload['campaign_stock'] ?? 0);
            if ($allocatedStock <= 0) {
                throw new FlashSaleSubmissionException('Campaign stock must be at least 1 unit.');
            }

            if ($campaign->min_stock_per_product !== null && $allocatedStock < (int) $campaign->min_stock_per_product) {
                throw new FlashSaleSubmissionException("Campaign stock must be at least {$campaign->min_stock_per_product} units.");
            }

            if ($campaign->max_stock_per_product !== null && $campaign->max_stock_per_product > 0 && $allocatedStock > (int) $campaign->max_stock_per_product) {
                throw new FlashSaleSubmissionException("Campaign stock cannot exceed {$campaign->max_stock_per_product} units.");
            }

            $totalPhysicalStock = (int) $product->variations()->sum('stock_quantity');
            if ($allocatedStock > $totalPhysicalStock) {
                throw new FlashSaleSubmissionException("Campaign stock ({$allocatedStock}) cannot exceed your current physical warehouse stock ({$totalPhysicalStock}).");
            }

            $status = $campaign->requires_approval
                ? FlashSaleSubmissionStatus::Pending
                : FlashSaleSubmissionStatus::Approved;

            return FlashSaleProduct::create([
                'flash_sale_id' => $campaign->id,
                'product_id' => $product->id,
                'seller_id' => $seller->id,
                'status' => $status,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'original_retail_price' => $basePrice,
                'campaign_price' => round($campaignPrice, 2),
                'stock_limit' => $allocatedStock,
                'units_sold' => 0,
                'per_customer_limit' => $payload['per_customer_limit'] ?? null,
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'seller_notes' => $payload['seller_notes'] ?? null,
            ]);
        });
    }

    public function updateSubmission(User $seller, FlashSaleProduct $submission, array $payload): FlashSaleProduct
    {
        return DB::transaction(function () use ($seller, $submission, $payload): FlashSaleProduct {
            $campaign = $submission->flashSale;
            if (! $campaign) {
                throw new FlashSaleSubmissionException('Campaign not found.');
            }

            if ((int) $submission->seller_id !== (int) $seller->id) {
                throw new FlashSaleSubmissionException('You can only update your own submissions.');
            }

            if (! in_array($submission->status, [FlashSaleSubmissionStatus::Pending, FlashSaleSubmissionStatus::RevisionRequested], true)) {
                throw new FlashSaleSubmissionException('Only pending or revision-requested submissions can be edited.');
            }

            if (! $campaign->isRegistrationOpen()) {
                throw new FlashSaleSubmissionException('Registration has closed for this campaign.');
            }

            $product = $submission->product;
            $basePrice = (float) $submission->original_retail_price;
            if ($basePrice <= 0) {
                $retailPriceRecord = $product->prices()
                    ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
                    ->whereNull('variation_id')
                    ->first()
                    ?? $product->prices()
                        ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
                        ->first();
                $basePrice = $retailPriceRecord ? (float) $retailPriceRecord->unit_price : 0.0;
            }

            $discountType = isset($payload['discount_type'])
                ? ($payload['discount_type'] instanceof FlashSaleDiscountType ? $payload['discount_type'] : FlashSaleDiscountType::from((string) $payload['discount_type']))
                : $submission->discount_type;

            $discountValue = isset($payload['discount_value']) ? (float) $payload['discount_value'] : (float) $submission->discount_value;

            $campaignPrice = match ($discountType) {
                FlashSaleDiscountType::Percentage => $basePrice * (1 - ($discountValue / 100)),
                FlashSaleDiscountType::FixedAmount => $basePrice - $discountValue,
                FlashSaleDiscountType::FixedPrice => $discountValue,
            };

            $campaignPrice = round($campaignPrice, 2);
            if ($campaignPrice <= 0) {
                throw new FlashSaleSubmissionException('Campaign price must be greater than zero.');
            }
            if ($campaignPrice >= $basePrice) {
                throw new FlashSaleSubmissionException('Campaign price must be lower than the original retail price.');
            }

            $effectiveDiscountPercentage = round((($basePrice - $campaignPrice) / $basePrice) * 100, 2);

            if ($campaign->min_discount_percentage !== null && $effectiveDiscountPercentage < (float) $campaign->min_discount_percentage) {
                throw new FlashSaleSubmissionException("Discount must be at least {$campaign->min_discount_percentage}%.");
            }

            if ($campaign->max_discount_percentage !== null && $effectiveDiscountPercentage > (float) $campaign->max_discount_percentage) {
                throw new FlashSaleSubmissionException("Discount cannot exceed {$campaign->max_discount_percentage}%.");
            }

            $allocatedStock = (int) ($payload['stock_limit'] ?? $payload['campaign_stock'] ?? $submission->stock_limit);
            if ($allocatedStock <= 0) {
                throw new FlashSaleSubmissionException('Campaign stock must be at least 1 unit.');
            }

            if ($campaign->min_stock_per_product !== null && $allocatedStock < (int) $campaign->min_stock_per_product) {
                throw new FlashSaleSubmissionException("Campaign stock must be at least {$campaign->min_stock_per_product} units.");
            }

            if ($campaign->max_stock_per_product !== null && $campaign->max_stock_per_product > 0 && $allocatedStock > (int) $campaign->max_stock_per_product) {
                throw new FlashSaleSubmissionException("Campaign stock cannot exceed {$campaign->max_stock_per_product} units.");
            }

            $totalPhysicalStock = (int) $product->variations()->sum('stock_quantity');
            if ($allocatedStock > $totalPhysicalStock) {
                throw new FlashSaleSubmissionException("Campaign stock ({$allocatedStock}) cannot exceed your current physical warehouse stock ({$totalPhysicalStock}).");
            }

            $submission->update([
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'original_retail_price' => $basePrice,
                'campaign_price' => $campaignPrice,
                'stock_limit' => $allocatedStock,
                'per_customer_limit' => $payload['per_customer_limit'] ?? $submission->per_customer_limit,
                'seller_notes' => $payload['seller_notes'] ?? $submission->seller_notes,
                'status' => FlashSaleSubmissionStatus::Pending,
                'rejection_reason' => null,
            ]);

            return $submission->fresh();
        });
    }

    public function requestRevision(FlashSaleProduct $submission, string $reason, ?User $reviewer = null): FlashSaleProduct
    {
        $submission->update([
            'status' => FlashSaleSubmissionStatus::RevisionRequested,
            'rejection_reason' => $reason,
            'reviewed_by' => $reviewer?->id ?? auth()->id(),
            'reviewed_at' => now(),
        ]);

        return $submission->fresh();
    }
}
