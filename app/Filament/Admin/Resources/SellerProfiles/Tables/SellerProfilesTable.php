<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Tables;

use App\Filament\Admin\Support\ApplicationReviewActions;
use App\Models\SellerProfile;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SellerProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user')->withCount('products'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('store_name')
                    ->label('Store')
                    ->description(fn (SellerProfile $record): ?string => $record->user?->name)
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('user.phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->visibleFrom('md'),

                TextColumn::make('trade_license_number')
                    ->label('Trade licence')
                    ->placeholder('Not provided')
                    ->copyable()
                    ->searchable()
                    ->visibleFrom('lg'),

                TextColumn::make('products_count')
                    ->label('Products')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('commission_rate_percentage')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state): string => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->state(fn (SellerProfile $record): string => ApplicationReviewActions::sellerStatus($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'warning',
                    }),

                IconColumn::make('is_on_vacation')
                    ->label('On break')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedPauseCircle)
                    ->falseIcon(null)
                    ->trueColor('warning')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Applied')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([
                ApplicationReviewActions::approveSeller()->button(),
                ActionGroup::make([
                    ApplicationReviewActions::viewSellerApplication(),
                    Action::make('viewStorefront')
                        ->label('View storefront')
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->color('gray')
                        ->url(fn (SellerProfile $record): string => url('/store/'.$record->store_slug))
                        ->openUrlInNewTab()
                        ->visible(fn (SellerProfile $record): bool => $record->isApproved() && filled($record->store_slug)),
                    EditAction::make(),
                    ApplicationReviewActions::rejectSeller(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBuildingStorefront)
            ->emptyStateHeading('No sellers here')
            ->emptyStateDescription('Try another tab. New store applications land in “Waiting for review”.');
        // No delete: sellers own products, orders and payouts (PRA-044)
    }
}
