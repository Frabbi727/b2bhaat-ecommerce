<?php

namespace App\Models;

use App\Services\Sms\Support\PhoneNormalizer;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'role_id',
        'name',
        'business_name',
        'email',
        'phone',
        'trade_license_number',
        'tax_id_number',
        'password',
        'is_active',
        'is_wholesale_approved',
        'wholesale_rejection_reason',
        'wholesale_approved_at',
        'wholesale_rejected_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_wholesale_approved' => 'boolean',
            'wholesale_approved_at' => 'datetime',
            'wholesale_rejected_at' => 'datetime',
        ];
    }

    public function isWholesalePending(): bool
    {
        return ! $this->is_wholesale_approved && $this->wholesale_rejected_at === null && (filled($this->trade_license_number) || filled($this->business_name));
    }

    public function isWholesaleRejected(): bool
    {
        return ! $this->is_wholesale_approved && $this->wholesale_rejected_at !== null;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    public function sellerWallet(): HasOne
    {
        return $this->hasOne(SellerWallet::class, 'seller_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function subOrders(): HasMany
    {
        return $this->hasMany(SubOrder::class, 'seller_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function favoriteProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists')->withTimestamps();
    }

    /**
     * Match a phone in any stored Bangladeshi format (8801…, +8801…, 01…).
     *
     * @param  Builder<User>  $query
     */
    public function scopeWherePhone(Builder $query, string $phone): void
    {
        $normalized = PhoneNormalizer::normalize($phone);

        $query->whereIn('phone', [$normalized, '+'.$normalized, substr($normalized, 2)]);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->slug === 'super_admin';
    }

    public function isSeller(): bool
    {
        return $this->role?->slug === 'seller';
    }

    public function isWholesaleBuyer(): bool
    {
        return $this->role?->slug === 'wholesale_buyer';
    }

    public function isRetailBuyer(): bool
    {
        return $this->role?->slug === 'retail_buyer';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($panel->getId() === 'admin') {
            return $this->isSuperAdmin();
        }

        if ($panel->getId() === 'seller') {
            return $this->isSeller() && ($this->sellerProfile?->is_approved ?? false);
        }

        return false;
    }
}
