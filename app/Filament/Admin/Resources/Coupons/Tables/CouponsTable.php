<?php

namespace App\Filament\Admin\Resources\Coupons\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Code')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Campaign')
                    ->searchable(),

                TextColumn::make('discount_value')
                    ->label('Discount')
                    ->formatStateUsing(fn ($record) => $record->discount_type === 'percentage'
                        ? "{$record->discount_value}%"
                        : "৳{$record->discount_value}")
                    ->sortable(),

                TextColumn::make('min_order_amount')
                    ->label('Min Subtotal')
                    ->money('BDT')
                    ->sortable(),

                TextColumn::make('used_count')
                    ->label('Used')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('usage_limit_total')
                    ->label('Limit')
                    ->placeholder('Unlimited')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M d, Y')
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
