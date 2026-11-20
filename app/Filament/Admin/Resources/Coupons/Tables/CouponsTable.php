<?php

namespace App\Filament\Admin\Resources\Coupons\Tables;

use App\Models\Coupon;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('Code copied')
                    ->description(fn (Coupon $record): string => $record->name)
                    ->searchable(['code', 'name'])
                    ->sortable(),

                TextColumn::make('offer')
                    ->label('Shoppers get')
                    ->state(fn (Coupon $record): string => $record->offerSummary())
                    ->wrap(),

                TextColumn::make('used_count')
                    ->label('Used')
                    ->formatStateUsing(fn (Coupon $record): string => number_format($record->used_count)
                        .($record->usage_limit_total ? ' / '.number_format($record->usage_limit_total) : ''))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Right now')
                    ->badge()
                    ->state(fn (Coupon $record): string => $record->statusLabel())
                    ->color(fn (Coupon $record): string => $record->statusColor()),

                TextColumn::make('expires_at')
                    ->label('Works until')
                    ->date('d M Y')
                    ->placeholder('No end date')
                    ->sortable(),

                ToggleColumn::make('is_active')
                    ->label('On'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->iconButton()
                    ->hidden(fn (Coupon $record): bool => $record->hasBeenUsed()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTicket)
            ->emptyStateHeading('No coupons yet')
            ->emptyStateDescription('Make a code shoppers can type at checkout for a discount.');
    }
}
