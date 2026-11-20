<?php

namespace App\Filament\Admin\Resources\Coupons\Pages;

use App\Filament\Admin\Resources\Coupons\CouponResource;
use App\Models\Coupon;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCoupons extends ListRecords
{
    protected static string $resource = CouponResource::class;

    public function getSubheading(): ?string
    {
        return 'Codes shoppers type at checkout. A coupon that has been used cannot be deleted; turn it off instead.';
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All coupons'),
            'working' => Tab::make('Working now')
                ->modifyQueryUsing(fn (Builder $query) => $query->active()
                    ->where(fn (Builder $limit) => $limit->whereNull('usage_limit_total')->orWhereColumn('used_count', '<', 'usage_limit_total'))),
            'stopped' => Tab::make('Not working now')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('id', Coupon::active()
                    ->where(fn (Builder $limit) => $limit->whereNull('usage_limit_total')->orWhereColumn('used_count', '<', 'usage_limit_total'))
                    ->select('id'))),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New coupon'),
        ];
    }
}
