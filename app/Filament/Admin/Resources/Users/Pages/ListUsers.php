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

        $approvedCount = User::where('is_wholesale_approved', true)->count();
        $rejectedCount = User::where('is_wholesale_approved', false)->whereNotNull('wholesale_rejected_at')->count();

        return [
            'all' => Tab::make('All Users'),
            'b2b_pending' => Tab::make('B2B Pending Review')
                ->badge((string) $pendingCount)
                ->badgeColor('warning')
                ->modifyQueryUsing(function (Builder $query) {
                    $query->where('is_wholesale_approved', false)
                        ->whereNull('wholesale_rejected_at')
                        ->where(fn ($q) => $q->whereNotNull('trade_license_number')->orWhereNotNull('business_name'));
                }),
            'b2b_approved' => Tab::make('Approved B2B')
                ->badge((string) $approvedCount)
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_wholesale_approved', true)),
            'b2b_rejected' => Tab::make('Rejected B2B')
                ->badge((string) $rejectedCount)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_wholesale_approved', false)->whereNotNull('wholesale_rejected_at')),
        ];
    }
}
