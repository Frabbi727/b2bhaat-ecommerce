<?php

namespace App\Filament\Seller\Pages;

use App\Models\ProductVariation;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class StoreSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Store & Vacation Mode';

    protected static ?string $title = 'Store & Vacation Mode Settings';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.seller.pages.store-settings';

    public bool $is_on_vacation = false;

    public string $vacation_message = '';

    public ?string $vacation_ends_at = null;

    public string $store_name = '';

    public string $store_slug = '';

    public ?string $trade_license_number = null;

    public ?string $tax_id_number = null;

    public string $commission_rate = '0';

    public bool $is_approved = false;

    public string $seller_email = '';

    public string $seller_phone = '';

    public ?float $inside_dhaka_rate = null;

    public ?float $outside_dhaka_rate = null;

    public ?float $free_shipping_threshold = null;

    public function mount(): void
    {
        $user = auth()->user();
        $profile = $user?->sellerProfile;

        if ($user) {
            $this->seller_email = (string) $user->email;
            $this->seller_phone = (string) ($user->phone ?? 'N/A');
        }

        if ($profile) {
            $this->is_on_vacation = (bool) $profile->is_on_vacation;
            $this->vacation_message = (string) ($profile->vacation_message ?? '');
            $this->vacation_ends_at = $profile->vacation_ends_at ? $profile->vacation_ends_at->format('Y-m-d') : null;
            $this->store_name = (string) $profile->store_name;
            $this->store_slug = (string) $profile->store_slug;
            $this->trade_license_number = $profile->trade_license_number;
            $this->tax_id_number = $profile->tax_id_number;
            $this->commission_rate = (string) ($profile->commission_rate_percentage ?? '10');
            $this->is_approved = (bool) $profile->is_approved;
            $this->inside_dhaka_rate = $profile->inside_dhaka_rate !== null ? (float) $profile->inside_dhaka_rate : null;
            $this->outside_dhaka_rate = $profile->outside_dhaka_rate !== null ? (float) $profile->outside_dhaka_rate : null;
            $this->free_shipping_threshold = $profile->free_shipping_threshold !== null ? (float) $profile->free_shipping_threshold : null;
        }
    }

    public function save(): void
    {
        $this->validate([
            'is_on_vacation' => 'boolean',
            'vacation_message' => 'nullable|string|max:1000',
            'vacation_ends_at' => 'nullable|date',
            'inside_dhaka_rate' => 'nullable|numeric|min:0|max:10000',
            'outside_dhaka_rate' => 'nullable|numeric|min:0|max:10000',
            'free_shipping_threshold' => 'nullable|numeric|min:0|max:1000000',
        ]);

        $profile = auth()->user()?->sellerProfile;

        if (! $profile) {
            Notification::make()
                ->title('Seller profile not found.')
                ->danger()
                ->send();

            return;
        }

        $profile->update([
            'is_on_vacation' => $this->is_on_vacation,
            'vacation_message' => $this->vacation_message ?: null,
            'vacation_ends_at' => $this->vacation_ends_at ?: null,
            'inside_dhaka_rate' => $this->inside_dhaka_rate,
            'outside_dhaka_rate' => $this->outside_dhaka_rate,
            'free_shipping_threshold' => $this->free_shipping_threshold,
        ]);

        Notification::make()
            ->title('Store settings saved successfully')
            ->body($this->is_on_vacation ? 'Vacation mode is currently ACTIVE. Storefront checkouts are paused.' : 'Store is open and active for incoming orders.')
            ->success()
            ->send();
    }

    /**
     * @return Collection<int, ProductVariation>
     */
    public function getLowStockVariationsProperty(): Collection
    {
        $sellerId = auth()->id();

        return ProductVariation::with(['product', 'attributeValues.attribute'])
            ->whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))
            ->where('stock_quantity', '<=', 10)
            ->orderBy('stock_quantity', 'asc')
            ->take(15)
            ->get();
    }
}
