<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlashSaleDiscountType;
use App\Enums\FlashSaleSubmissionStatus;
use App\Models\Traits\Auditable;
use App\Services\Promotions\FlashSalePricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FlashSaleProduct extends Model
{
    use Auditable;
    use HasFactory;

    public const CANCELLED_FULFILLMENT_SLUG = 'cancelled';

    protected $fillable = [
        'flash_sale_id',
        'product_id',
        'seller_id',
        'status',
        'discount_type',
        'discount_value',
        'original_retail_price',
        'campaign_price',
        'stock_limit',
        'units_sold',
        'per_customer_limit',
        'sort_order',
        'rejection_reason',
        'seller_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * Any change to a campaign product can change prices, so the in-request offer memo is dropped.
     */
    protected static function booted(): void
    {
        static::saved(static function (): void {
            FlashSalePricing::flushResolved();
        });
        static::deleted(static function (): void {
            FlashSalePricing::flushResolved();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => FlashSaleSubmissionStatus::class,
            'discount_type' => FlashSaleDiscountType::class,
            'discount_value' => 'decimal:2',
            'original_retail_price' => 'decimal:2',
            'campaign_price' => 'decimal:2',
            'stock_limit' => 'integer',
            'units_sold' => 'integer',
            'per_customer_limit' => 'integer',
            'sort_order' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function flashSale(): BelongsTo
    {
        return $this->belongsTo(FlashSale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Campaign price for a line whose normal price is $basePrice, kept between 0.01 and the base.
     */
    public function priceFrom(float $basePrice): float
    {
        $value = (float) $this->discount_value;

        $calculated = match ($this->discount_type) {
            FlashSaleDiscountType::Percentage => $basePrice * (1 - $value / 100),
            FlashSaleDiscountType::FixedAmount => $basePrice - $value,
            FlashSaleDiscountType::FixedPrice => $value,
        };

        return round(min($basePrice, max(0.01, round($calculated, 2))), 2);
    }

    /**
     * Units sold on this offer, ignoring cancelled sub-orders.
     */
    public function unitsSold(): int
    {
        return (int) $this->nonCancelledItemsQuery()->sum('order_items.quantity');
    }

    /**
     * Units sold on many offers in one query, ignoring cancelled sub-orders.
     *
     * @param  array<int, int>  $offerIds
     * @return array<int, int> offer id => units sold
     */
    public static function unitsSoldFor(array $offerIds): array
    {
        if ($offerIds === []) {
            return [];
        }

        return DB::table('order_items')
            ->join('sub_orders', 'sub_orders.id', '=', 'order_items.sub_order_id')
            ->join('fulfillment_statuses', 'fulfillment_statuses.id', '=', 'sub_orders.fulfillment_status_id')
            ->whereIn('order_items.flash_sale_product_id', $offerIds)
            ->where('fulfillment_statuses.slug', '!=', self::CANCELLED_FULFILLMENT_SLUG)
            ->groupBy('order_items.flash_sale_product_id')
            ->selectRaw('order_items.flash_sale_product_id as offer_id, SUM(order_items.quantity) as units')
            ->pluck('units', 'offer_id')
            ->map(fn ($units): int => (int) $units)
            ->all();
    }

    public function unitsBoughtBy(int $customerId): int
    {
        return (int) $this->nonCancelledItemsQuery()
            ->join('orders', 'orders.id', '=', 'sub_orders.parent_order_id')
            ->where('orders.customer_id', $customerId)
            ->sum('order_items.quantity');
    }

    private function nonCancelledItemsQuery(): Builder
    {
        return DB::table('order_items')
            ->join('sub_orders', 'sub_orders.id', '=', 'order_items.sub_order_id')
            ->join('fulfillment_statuses', 'fulfillment_statuses.id', '=', 'sub_orders.fulfillment_status_id')
            ->where('order_items.flash_sale_product_id', $this->getKey())
            ->where('fulfillment_statuses.slug', '!=', self::CANCELLED_FULFILLMENT_SLUG);
    }
}
