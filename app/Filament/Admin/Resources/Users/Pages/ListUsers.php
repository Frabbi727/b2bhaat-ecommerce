<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $pendingCount = User::where('is_wholesale_approved', false)
            ->whereNull('wholesale_rejected_at')
            ->where(fn ($q) => $q->whereNotNull('trade_license_number')->orWhereNotNull('business_name'))
            ->count();

        return [
            'all' => Tab::make('All users'),
            'b2b_pending' => Tab::make('Wholesale requests')
                ->badge($pendingCount ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(function (Builder $query) {
                    $query->where('is_wholesale_approved', false)
                        ->whereNull('wholesale_rejected_at')
                        ->where(fn ($q) => $q->whereNotNull('trade_license_number')->orWhereNotNull('business_name'));
                }),
            'b2b_approved' => Tab::make('Wholesale buyers')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_wholesale_approved', true)),
            'b2b_rejected' => Tab::make('Rejected requests')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_wholesale_approved', false)->whereNotNull('wholesale_rejected_at')),
        ];
    }
}
