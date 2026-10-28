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

    public ?string $trade_license_number = null;

    public ?string $tax_id_number = null;

    public function mount(): void
    {
        $profile = auth()->user()?->sellerProfile;

        if ($profile) {
            $this->is_on_vacation = (bool) $profile->is_on_vacation;
            $this->vacation_message = (string) ($profile->vacation_message ?? '');
            $this->vacation_ends_at = $profile->vacation_ends_at ? $profile->vacation_ends_at->format('Y-m-d') : null;
            $this->store_name = (string) $profile->store_name;
            $this->trade_license_number = $profile->trade_license_number;
            $this->tax_id_number = $profile->tax_id_number;
        }
    }

    public function save(): void
    {
        $this->validate([
            'is_on_vacation' => 'boolean',
            'vacation_message' => 'nullable|string|max:1000',
            'vacation_ends_at' => 'nullable|date',
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
