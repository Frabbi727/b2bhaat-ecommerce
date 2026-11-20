<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SellerMonetization\Pages;

use App\Filament\Admin\Resources\SellerMonetization\SellerMonetizationResource;
use App\Models\SellerProfile;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSellerMonetizations extends ListRecords
{
    protected static string $resource = SellerMonetizationResource::class;

    public function getTabs(): array
    {
        $suspendedCount = SellerProfile::where('monetization_status', 'suspended')->count();
        $graceCount = SellerProfile::where('monetization_status', 'grace_period')->count();

        return [
            'all' => Tab::make('All Sellers'),
            'active' => Tab::make('Active Sellers')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('monetization_status', 'active')),
            'grace_period' => Tab::make('In Grace Period')
                ->badge($graceCount ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('monetization_status', 'grace_period')),
            'suspended' => Tab::make('Suspended')
                ->badge($suspendedCount ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('monetization_status', 'suspended')),
        ];
    }
}
