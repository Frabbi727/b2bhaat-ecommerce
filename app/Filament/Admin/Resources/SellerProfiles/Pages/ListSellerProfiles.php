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
            CreateAction::make()
                ->label('Add seller manually')
                ->color('gray'),
        ];
    }

    public function getTabs(): array
    {
        $pending = SellerProfile::where('is_approved', false)->whereNull('rejected_at')->count();

        return [
            'all' => Tab::make('All sellers'),
            'pending' => Tab::make('Waiting for review')
                ->badge($pending ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)->whereNull('rejected_at')),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', true)),
            'rejected' => Tab::make('Rejected')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_approved', false)->whereNotNull('rejected_at')),
        ];
    }
}
