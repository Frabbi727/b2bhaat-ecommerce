<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SubOrder extends Model
{
    /** The buyer's secret; never serialised (final review C1). */
    protected $hidden = [
        'handover_code',
    ];

    protected $fillable = [
        'parent_order_id',
        'seller_id',
        'sub_order_number',
        'fulfillment_status_id',
        'courier_name',
        'tracking_number',
        'subtotal',
        'commission_amount',
        'shipping_amount',
        'is_self_delivered',
        'handover_code',
        'handover_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'is_self_delivered' => 'boolean',
            'handover_confirmed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function fulfillmentStatus(): BelongsTo
    {
        return $this->belongsTo(FulfillmentStatus::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function trackingLogs(): HasMany
    {
        return $this->hasMany(OrderTrackingLog::class)->orderBy('logged_at', 'desc');
    }

    /**
     * When the consignment was delivered: the latest "delivered" tracking log, falling back to the last update.
     * The escrow release and the return window both count from this moment.
     */
    public function deliveredAt(): ?Carbon
    {
        $deliveredStatusId = FulfillmentStatus::where('slug', 'delivered')->value('id');

        $loggedAt = $this->trackingLogs()
            ->where('fulfillment_status_id', $deliveredStatusId)
            ->reorder('logged_at', 'desc')
            ->value('logged_at');

        return $loggedAt !== null ? Carbon::parse($loggedAt) : $this->updated_at;
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    /**
     * Goods may leave the warehouse only for COD orders or orders whose payment has been verified (PRA-006).
     */
    public function isClearedForDispatch(): bool
    {
        $order = $this->order;

        return $order !== null
            && ($order->paymentMethod?->slug === 'cod' || $order->paymentStatus?->slug === 'paid');
    }

    /**
     * What the platform owes the seller for this sub-order: net earnings, plus the delivery fee when the seller
     * delivered it themselves (D6: whoever delivers keeps the fee).
     */
    public function sellerPayableAmount(): float
    {
        return round($this->net_earnings + ($this->is_self_delivered ? (float) $this->shipping_amount : 0.0), 2);
    }

    /**
     * This consignment's share of the order coupon: discount × (subtotal / Σ sibling subtotals), rounded to 2 places.
     * The rounding remainder goes to the sub-order with the largest subtotal (lowest id on a tie), so shares sum exactly.
     */
    public function allocatedDiscount(): float
    {
        $order = $this->order;
        $discount = round((float) ($order?->discount_amount ?? 0), 2);
        if ($order === null || $discount <= 0) {
            return 0.0;
        }

        $siblings = $order->subOrders()->orderBy('id')->get(['id', 'subtotal']);
        $totalSubtotal = (float) $siblings->sum('subtotal');
        if ($totalSubtotal <= 0) {
            return 0.0;
        }

        $remainderHolder = $siblings
            ->sort(fn (SubOrder $x, SubOrder $y): int => [(float) $y->subtotal, $x->id] <=> [(float) $x->subtotal, $y->id])
            ->first();
        $share = fn (SubOrder $sibling): float => round($discount * (float) $sibling->subtotal / $totalSubtotal, 2);

        if ($remainderHolder->id !== $this->id) {
            return $share($siblings->firstWhere('id', $this->id) ?? $this);
        }

        $othersTotal = $siblings->where('id', '!=', $this->id)->sum(fn (SubOrder $sibling) => $share($sibling));

        return round($discount - $othersTotal, 2);
    }

    /**
     * What the buyer pays for this consignment: goods + delivery fee − allocated coupon share.
     */
    public function paidAmount(): float
    {
        return round((float) $this->subtotal + (float) $this->shipping_amount - $this->allocatedDiscount(), 2);
    }

    /**
     * Cash the deliverer must collect: the paid amount for COD orders, nothing for prepaid orders.
     */
    public function codCollectAmount(): float
    {
        return $this->order?->paymentMethod?->slug === 'cod' ? $this->paidAmount() : 0.0;
    }

    public function getNetEarningsAttribute(): float
    {
        return (float) ($this->subtotal - $this->commission_amount);
    }

    public function getTrackingUrlAttribute(): ?string
    {
        if (empty($this->tracking_number)) {
            return null;
        }

        $courier = strtolower($this->courier_name ?? '');

        if (str_contains($courier, 'steadfast')) {
            return "https://steadfast.com.bd/t/{$this->tracking_number}";
        }

        if (str_contains($courier, 'pathao')) {
            return "https://merchant.pathao.com/tracking?consignment_id={$this->tracking_number}";
        }

        if (str_contains($courier, 'redx')) {
            return "https://redx.com.bd/track?trackingId={$this->tracking_number}";
        }

        if (str_contains($courier, 'paperfly')) {
            return "https://paperfly.com.bd/tracking?id={$this->tracking_number}";
        }

        if (str_contains($courier, 'sundarban')) {
            return "https://sundarbancourierltd.com/track/{$this->tracking_number}";
        }

        return null;
    }
}
