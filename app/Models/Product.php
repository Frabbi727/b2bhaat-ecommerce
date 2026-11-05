<?php

namespace App\Models;

use App\Services\Inventory\ProductCodeGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $fillable = [
        'seller_id',
        'category_id',
        'brand_id',
        'sales_channel_id',
        'unit_id',
        'title',
        'slug',
        'sku',
        'barcode',
        'model_number',
        'short_description',
        'full_description',
        'specifications',
        'features',
        'regular_price',
        'buy_price',
        'warranty_period',
        'warranty_policy',
        'delivery_info',
        'has_variations',
        'is_featured',
        'is_flash_sale',
        'flash_sale_price',
        'flash_sale_end',
        'views_count',
        'sales_count',
        'is_active',
        'blocked_at',
        'blocked_reason',
    ];

    protected $hidden = [
        'buy_price',
    ];

    public const DEFAULT_VARIATION_SUFFIX = '-DEFAULT';

    /**
     * Products get a generated SKU and barcode; simple products (no variations) get one default variation so stock is reserved like any SKU (PRA-011).
     */
    protected static function booted(): void
    {
        // A copied product is a different item: it must not inherit the original's SKU or barcode
        static::replicating(function (Product $replica): void {
            $replica->sku = null;
            $replica->barcode = null;
        });

        static::created(function (Product $product): void {
            // SKU and barcode are generated, never typed, so they are always present and unique
            $codes = array_filter([
                'sku' => blank($product->sku) ? ProductCodeGenerator::productSku($product) : null,
                'barcode' => blank($product->barcode) ? ProductCodeGenerator::barcode($product->id) : null,
            ]);
            if ($codes !== []) {
                $product->forceFill($codes)->saveQuietly();
            }

            if (! $product->has_variations) {
                $product->ensureDefaultVariation();
            }
        });
    }

    /**
     * The product's stock-carrying variation; creates the default one (stock 0) when none exists.
     */
    public function ensureDefaultVariation(): ProductVariation
    {
        $existing = $this->variations()->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        return $this->variations()->create([
            'sku' => static::defaultVariationSku($this->id, $this->sku, $this->slug),
            'stock_quantity' => 0,
            'buy_price' => $this->buy_price ?? 0,
            'is_active' => true,
        ]);
    }

    /**
     * The storefront uses the first photo by sort order as the cover; keep `is_primary` pointing at that same photo.
     */
    public function syncPrimaryImageFlag(): void
    {
        $coverId = $this->images()->reorder()->orderBy('sort_order')->orderBy('id')->value('id');

        $this->images()->where('id', '!=', $coverId ?? 0)->where('is_primary', true)->update(['is_primary' => false]);

        if ($coverId) {
            $this->images()->whereKey($coverId)->update(['is_primary' => true]);
        }
    }

    /**
     * Products that appear on an order must stay on record for invoices and disputes; sellers hide them instead.
     */
    public function hasBeenOrdered(): bool
    {
        return OrderItem::where('product_id', $this->id)->exists();
    }

    /**
     * `{product sku or slug}-DEFAULT`, suffixed with the product id if that SKU is already taken.
     */
    public static function defaultVariationSku(int $productId, ?string $sku, string $slug): string
    {
        $candidate = ($sku ?: $slug).self::DEFAULT_VARIATION_SUFFIX;

        if (ProductVariation::where('sku', $candidate)->exists()) {
            $candidate = ($sku ?: $slug)."-{$productId}".self::DEFAULT_VARIATION_SUFFIX;
        }

        return $candidate;
    }

    protected function casts(): array
    {
        return [
            'regular_price' => 'decimal:2',
            'buy_price' => 'decimal:2',
            'flash_sale_price' => 'decimal:2',
            'has_variations' => 'boolean',
            'is_featured' => 'boolean',
            'is_flash_sale' => 'boolean',
            'flash_sale_end' => 'datetime',
            'specifications' => 'array',
            'features' => 'array',
            'views_count' => 'integer',
            'sales_count' => 'integer',
            'is_active' => 'boolean',
            'blocked_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany([
            'sort_order' => 'min',
            'id' => 'min',
        ]);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class)->where('is_approved', true);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function favoritedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlists')->withTimestamps();
    }

    public function retailPrice()
    {
        return $this->hasOne(ProductPrice::class)
            ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::RETAIL))
            ->whereNull('variation_id')
            ->orderBy('min_quantity');
    }

    public function wholesalePrice()
    {
        return $this->hasOne(ProductPrice::class)
            ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::WHOLESALE))
            ->whereNull('variation_id')
            ->orderBy('min_quantity');
    }

    public function wholesalePrices(): HasMany
    {
        return $this->hasMany(ProductPrice::class)
            ->whereHas('salesChannel', fn (Builder $q) => $q->where('slug', SalesChannel::WHOLESALE))
            ->whereNull('variation_id')
            ->orderBy('min_quantity');
    }

    /**
     * Products a buyer may see and order: the product is active, and its seller is active and approved (PRA-010).
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('is_active'), true)
            ->whereNull($query->qualifyColumn('blocked_at'))
            ->whereHas('seller', fn (Builder $seller) => $seller->where('is_active', true)
                ->whereHas('sellerProfile', fn (Builder $profile) => $profile->where('is_approved', true)));
    }

    /**
     * Products the storefront may show: sellable, with a shop (retail) price above zero, so no card ever reads ৳0.
     */
    public function scopeListable(Builder $query): Builder
    {
        return $query->sellable()
            ->whereHas('prices', fn (Builder $price) => $price->where('unit_price', '>', 0)
                ->whereHas('salesChannel', fn (Builder $channel) => $channel->where('slug', SalesChannel::RETAIL)));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeFlashSale(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_flash_sale', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('flash_sale_end')->orWhere('flash_sale_end', '>=', $now);
            });
    }

    public function scopeForChannel(Builder $query, string $channelSlug): Builder
    {
        return $query->whereHas('salesChannel', function (Builder $q) use ($channelSlug) {
            $q->whereIn('slug', [$channelSlug, SalesChannel::BOTH]);
        });
    }

    /**
     * The flash-sale price while the sale is running, otherwise null. The cart charges it for shop (retail) lines.
     */
    public function activeFlashSalePrice(): ?float
    {
        if ($this->is_flash_sale && (float) $this->flash_sale_price > 0 && (! $this->flash_sale_end || $this->flash_sale_end->isFuture())) {
            return (float) $this->flash_sale_price;
        }

        return null;
    }

    public function getEffectiveSellingPriceAttribute(): float
    {
        if (($flashPrice = $this->activeFlashSalePrice()) !== null) {
            return $flashPrice;
        }

        if ($this->relationLoaded('retailPrice') && $this->retailPrice) {
            return (float) $this->retailPrice->unit_price;
        }

        $retail = $this->retailPrice;
        if ($retail) {
            return (float) $retail->unit_price;
        }

        return (float) ($this->regular_price ?? 0.0);
    }

    public function getDiscountPercentageAttribute(): ?int
    {
        $regular = (float) ($this->regular_price ?? 0.0);
        $selling = $this->effective_selling_price;

        if ($regular > 0 && $regular > $selling) {
            return (int) round((($regular - $selling) / $regular) * 100);
        }

        return null;
    }

    public function getSavingsAmountAttribute(): float
    {
        $regular = (float) ($this->regular_price ?? 0.0);
        $selling = $this->effective_selling_price;

        return max(0.0, round($regular - $selling, 2));
    }

    public function getIsFlashSaleActiveAttribute(): bool
    {
        return $this->is_flash_sale && (! $this->flash_sale_end || $this->flash_sale_end->isFuture());
    }
}
