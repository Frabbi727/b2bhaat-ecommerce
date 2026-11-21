<?php

namespace App\Filament\Seller\Resources\ReturnRequests\Pages;

use App\Filament\Seller\Resources\ReturnRequests\ReturnRequestResource;
use App\Models\ReturnRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListReturnRequests extends ListRecords
{
    protected static string $resource = ReturnRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $waiting = ReturnRequestResource::getEloquentQuery()->where('status', ReturnRequest::STATUS_PENDING)->count();

        return [
            'pending' => Tab::make('Needs your answer')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ReturnRequest::STATUS_PENDING))
                ->badge($waiting > 0 ? $waiting : null)
                ->badgeColor('warning'),
            'approved' => Tab::make('Approved')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ReturnRequest::STATUS_APPROVED)),
            'rejected' => Tab::make('Rejected & closed')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [ReturnRequest::STATUS_REJECTED, ReturnRequest::STATUS_RESOLVED, ReturnRequest::STATUS_ESCALATED])),
            'all' => Tab::make('All'),
        ];
    }

    /** Open on what needs an answer, or on everything when nothing does. */
    public function getDefaultActiveTab(): string|int|null
    {
        return ReturnRequestResource::getEloquentQuery()->where('status', ReturnRequest::STATUS_PENDING)->exists() ? 'pending' : 'all';
    }
}
