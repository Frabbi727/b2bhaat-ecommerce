<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('role.name')
                    ->badge()
                    ->color(fn (User $record): array => match ($record->role?->slug) {
                        'super_admin' => Color::Purple,
                        'seller' => Color::Blue,
                        'wholesale_buyer' => Color::Emerald,
                        default => Color::Gray,
                    }),
                TextColumn::make('business_name')
                    ->label('Business / Company')
                    ->placeholder('Individual')
                    ->searchable(),
                TextColumn::make('trade_license_number')
                    ->label('Trade License / NID')
                    ->fontFamily('mono')
                    ->placeholder('N/A')
                    ->searchable(),
                IconColumn::make('is_wholesale_approved')
                    ->label('B2B Approved')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('approve_wholesale')
                    ->label('Approve B2B')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Wholesale Business Account')
                    ->modalDescription(fn (User $record) => "Approve {$record->business_name} ({$record->trade_license_number}) for wholesale tier pricing and bulk matrix ordering?")
                    ->visible(fn (User $record) => ! $record->is_wholesale_approved && filled($record->trade_license_number))
                    ->action(function (User $record) {
                        $wholesaleRole = Role::where('slug', 'wholesale_buyer')->first();
                        $record->update([
                            'is_wholesale_approved' => true,
                            'role_id' => $wholesaleRole?->id ?? $record->role_id,
                        ]);

                        Notification::make()
                            ->title('Wholesale Account Approved')
                            ->body("{$record->name} can now access factory wholesale rates.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
