<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Pages;

use App\Filament\Admin\Resources\SellerProfiles\SellerProfileResource;
use App\Models\SellerProfile;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSellerProfiles extends ListRecords
{
    protected static string $resource = SellerProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending Verification')
                ->badge((string) SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)->whereNull('rejected_at')),
            'approved' => Tab::make('Approved Factories')
                ->badge((string) SellerProfile::where('is_approved', true)->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', true)),
            'rejected' => Tab::make('Rejected')
                ->badge((string) SellerProfile::where('is_approved', false)->whereNotNull('rejected_at')->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)->whereNotNull('rejected_at')),
            'all' => Tab::make('All Stores'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
