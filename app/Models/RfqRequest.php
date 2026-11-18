<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqRequest extends Model
{
    use Auditable;

    protected $fillable = [
        'product_id',
        'seller_id',
        'buyer_id',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'company_name',
        'target_quantity',
        'option_quantities',
        'target_unit_price',
        'destination_district',
        'specifications',
        'status',
        'seller_quoted_price',
        'seller_notes',
        'valid_until',
        'dispatch_days',
        'rejected_by',
        'rejection_reason',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'integer',
            'option_quantities' => 'array',
            'target_unit_price' => 'decimal:2',
            'seller_quoted_price' => 'decimal:2',
            'valid_until' => 'datetime',
            'dispatch_days' => 'integer',
            'order_id' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && now()->isAfter($this->valid_until);
    }

    public function canBeAccepted(): bool
    {
        return $this->status === 'quoted' && ! $this->isExpired();
    }

    /**
     * Units per option the buyer asked for (WA-PRC-03), keyed by variation id. Empty for a product without options.
     *
     * @return array<int, int>
     */
    public function optionQuantities(): array
    {
        $quantities = [];
        foreach ($this->option_quantities ?? [] as $variationId => $quantity) {
            if ((int) $quantity > 0) {
                $quantities[(int) $variationId] = (int) $quantity;
            }
        }

        return $quantities;
    }

    /** One line per option, e.g. "NAIL-PNK-MED (Colour: Pink): 40 · NAIL-BLK-LNG (Colour: Black): 20". */
    public function optionSummary(): ?string
    {
        $quantities = $this->optionQuantities();
        if ($quantities === []) {
            return null;
        }

        $variations = ProductVariation::with('attributeValues.attribute')->whereIn('id', array_keys($quantities))->get()->keyBy('id');

        return collect($quantities)->map(function (int $quantity, int $variationId) use ($variations): string {
            $variation = $variations->get($variationId);
            if (! $variation) {
                return "Option #{$variationId}: {$quantity}";
            }

            $attributes = $variation->attributeValues->map(fn ($value) => "{$value->attribute->name}: {$value->value}")->implode(', ');

            return $variation->sku.($attributes !== '' ? " ({$attributes})" : '').": {$quantity}";
        })->implode(' · ');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** The order that used this accepted quotation (WA-PRC-01). */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
