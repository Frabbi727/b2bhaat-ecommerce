<?php

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SellerProfile extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id',
        'store_name',
        'store_slug',
        'trade_license_number',
        'tax_id_number',
        'business_address',
        'business_phone',
        'pending_business_address',
        'pending_business_phone',
        'contact_change_submitted_at',
        'show_contact_on_invoice',
        'commission_rate_percentage',
        'inside_dhaka_rate',
        'outside_dhaka_rate',
        'free_shipping_threshold',
        'monetization_status',
        'is_approved',
        'rejection_reason',
        'approved_at',
        'rejected_at',
        'is_on_vacation',
        'vacation_message',
        'vacation_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate_percentage' => 'decimal:2',
            'inside_dhaka_rate' => 'decimal:2',
            'outside_dhaka_rate' => 'decimal:2',
            'free_shipping_threshold' => 'decimal:2',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'is_on_vacation' => 'boolean',
            'vacation_ends_at' => 'date',
            'contact_change_submitted_at' => 'datetime',
            'show_contact_on_invoice' => 'boolean',
        ];
    }

    public function isPending(): bool
    {
        return ! $this->is_approved && $this->rejected_at === null;
    }

    public function isRejected(): bool
    {
        return ! $this->is_approved && $this->rejected_at !== null;
    }

    public function isApproved(): bool
    {
        return (bool) $this->is_approved;
    }

    public function hasPendingContactChange(): bool
    {
        return $this->contact_change_submitted_at !== null;
    }

    /**
     * Store the seller's requested address and phone until an admin approves them; live values stay unchanged.
     */
    public function submitContactChange(?string $address, ?string $phone): void
    {
        $this->forceFill([
            'pending_business_address' => filled($address) ? trim($address) : null,
            'pending_business_phone' => filled($phone) ? trim($phone) : null,
            'contact_change_submitted_at' => now(),
        ])->save();
    }

    public function approveContactChange(): void
    {
        $this->forceFill([
            'business_address' => $this->pending_business_address,
            'business_phone' => $this->pending_business_phone,
        ])->clearPendingContactChange();
    }

    public function rejectContactChange(): void
    {
        $this->clearPendingContactChange();
    }

    /**
     * Whether this seller's address and phone print on customer invoices: the seller's own override wins, else the platform setting.
     */
    public function showsContactOnInvoice(bool $platformDefault): bool
    {
        return $this->show_contact_on_invoice ?? $platformDefault;
    }

    private function clearPendingContactChange(): void
    {
        $this->forceFill([
            'pending_business_address' => null,
            'pending_business_phone' => null,
            'contact_change_submitted_at' => null,
        ])->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, User::class, 'id', 'seller_id', 'user_id', 'id');
    }
}
