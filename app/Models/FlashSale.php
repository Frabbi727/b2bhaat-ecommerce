<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlashSaleStatus;
use App\Enums\FlashSaleSubmissionStatus;
use App\Enums\SellerEligibilityType;
use App\Models\Traits\Auditable;
use App\Services\Promotions\FlashSalePricing;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FlashSale extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'banner_path',
        'terms',
        'status',
        'starts_at',
        'ends_at',
        'is_featured',
        'display_priority',
        'paused_at',
        'ended_at',
        'created_by',
        'registration_starts_at',
        'registration_ends_at',
        'min_discount_percentage',
        'max_discount_percentage',
        'max_products_per_seller',
        'min_stock_per_product',
        'max_stock_per_product',
        'requires_approval',
        'min_approved_products',
        'seller_eligibility_type',
        'minimum_seller_rating',
    ];

    /**
     * Any change to a campaign can change prices, so the in-request offer memo is dropped.
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
            'status' => FlashSaleStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paused_at' => 'datetime',
            'ended_at' => 'datetime',
            'is_featured' => 'boolean',
            'display_priority' => 'integer',
            'registration_starts_at' => 'datetime',
            'registration_ends_at' => 'datetime',
            'min_discount_percentage' => 'decimal:2',
            'max_discount_percentage' => 'decimal:2',
            'max_products_per_seller' => 'integer',
            'min_stock_per_product' => 'integer',
            'max_stock_per_product' => 'integer',
            'requires_approval' => 'boolean',
            'min_approved_products' => 'integer',
            'seller_eligibility_type' => SellerEligibilityType::class,
            'minimum_seller_rating' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'flash_sale_products')
            ->withPivot(['id', 'seller_id', 'status', 'discount_type', 'discount_value', 'original_retail_price', 'campaign_price', 'stock_limit', 'units_sold', 'per_customer_limit', 'sort_order'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'flash_sale_categories')->withTimestamps();
    }

    /**
     * @return HasMany<FlashSaleProduct, $this>
     */
    /**
     * @return BelongsToMany<Product, $this>
     */
    public function approvedProducts(): BelongsToMany
    {
        return $this->products()
            ->wherePivot('status', FlashSaleSubmissionStatus::Approved->value);
    }

    public function items(): HasMany
    {
        return $this->hasMany(FlashSaleProduct::class);
    }

    /**
     * PERF-3: adds `units_sold_total`, the units sold across all the campaign's products in parcels that were not
     * cancelled, so a list of campaigns needs one query instead of one per product.
     */
    public function scopeWithUnitsSold(Builder $query): Builder
    {
        $unitsSold = DB::table('order_items')
            ->join('flash_sale_products', 'flash_sale_products.id', '=', 'order_items.flash_sale_product_id')
            ->join('sub_orders', 'sub_orders.id', '=', 'order_items.sub_order_id')
            ->join('fulfillment_statuses', 'fulfillment_statuses.id', '=', 'sub_orders.fulfillment_status_id')
            ->whereColumn('flash_sale_products.flash_sale_id', 'flash_sales.id')
            ->where('fulfillment_statuses.slug', '!=', FlashSaleProduct::CANCELLED_FULFILLMENT_SLUG)
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0)');

        if ($query->getQuery()->columns === null) {
            $query->select('flash_sales.*');
        }

        return $query->selectSub($unitsSold, 'units_sold_total');
    }

    /**
     * The status the system acts on at the given moment. Stored `scheduled`
     * and `active` are read from the clock; the other stored states are final.
     */
    public function effectiveStatus(?CarbonInterface $at = null): FlashSaleStatus
    {
        $at ??= now();

        if ($this->status === FlashSaleStatus::Enrolling) {
            if ($this->registration_ends_at && $at->gte($this->registration_ends_at)) {
                return FlashSaleStatus::Reviewing;
            }

            return FlashSaleStatus::Enrolling;
        }

        if (! in_array($this->status, [FlashSaleStatus::Scheduled, FlashSaleStatus::Active], true)) {
            return $this->status;
        }

        if ($this->starts_at === null || $this->ends_at === null) {
            return $this->status;
        }

        if ($at->lt($this->starts_at)) {
            return FlashSaleStatus::Scheduled;
        }

        if ($at->lt($this->ends_at)) {
            return FlashSaleStatus::Active;
        }

        return FlashSaleStatus::Ended;
    }

    public function isLive(?CarbonInterface $at = null): bool
    {
        return $this->effectiveStatus($at) === FlashSaleStatus::Active;
    }

    public function isRegistrationOpen(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        if (in_array($this->effectiveStatus($at), [FlashSaleStatus::Cancelled, FlashSaleStatus::Ended, FlashSaleStatus::Reviewing], true)) {
            return false;
        }

        if ($this->status === FlashSaleStatus::Enrolling) {
            if ($this->registration_ends_at && $at->gte($this->registration_ends_at)) {
                return false;
            }
            if ($this->registration_starts_at && $at->lt($this->registration_starts_at)) {
                return false;
            }

            return true;
        }

        $starts = $this->registration_starts_at ?? $this->created_at ?? $this->starts_at;
        $ends = $this->registration_ends_at ?? $this->starts_at;

        if ($starts === null || $ends === null) {
            return false;
        }

        return $at->gte($starts) && $at->lt($ends);
    }

    /**
     * @param  Builder<FlashSale>  $query
     * @return Builder<FlashSale>
     */
    public function scopeLive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->whereIn('status', $this->publishedStatuses())
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    /**
     * @param  Builder<FlashSale>  $query
     * @return Builder<FlashSale>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', $this->publishedStatuses())
            ->where('starts_at', '>', now());
    }

    /**
     * @return list<string>
     */
    private function publishedStatuses(): array
    {
        return [FlashSaleStatus::Scheduled->value, FlashSaleStatus::Active->value];
    }
}
