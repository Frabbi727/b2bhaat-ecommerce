<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Filament\Admin\Support\ApplicationReviewActions;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
                    ->description(fn (User $record): ?string => $record->email)
                    ->searchable(['name', 'email'])
                    ->weight('medium'),
                TextColumn::make('phone')
                    ->placeholder('—')
                    ->copyable()
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
                    ->label('Business')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trade_license_number')
                    ->label('Trade licence')
                    ->placeholder('—')
                    ->copyable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('b2b_status')
                    ->label('Wholesale')
                    ->badge()
                    ->state(function (User $record): string {
                        if ($record->is_wholesale_approved) {
                            return 'Approved';
                        }
                        if ($record->wholesale_rejected_at !== null) {
                            return 'Rejected';
                        }
                        if (filled($record->trade_license_number) || filled($record->business_name)) {
                            return 'Waiting for review';
                        }

                        return '—';
                    })
                    ->color(fn (User $record): array => match (true) {
                        $record->is_wholesale_approved => Color::Emerald,
                        $record->wholesale_rejected_at !== null => Color::Rose,
                        filled($record->trade_license_number) || filled($record->business_name) => Color::Amber,
                        default => Color::Slate,
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ApplicationReviewActions::approveWholesale()->button(),
                ActionGroup::make([
                    ApplicationReviewActions::viewWholesaleApplication()
                        ->visible(fn (User $record): bool => filled($record->trade_license_number) || filled($record->business_name)),
                    EditAction::make(),
                    ApplicationReviewActions::rejectWholesale(),
                    Action::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('The account can no longer sign in or reach any panel. Orders, payouts and ledger history are kept.')
                        ->visible(fn (User $record): bool => $record->is_active && $record->id !== auth()->id())
                        ->action(function (User $record): void {
                            $record->update(['is_active' => false]);
                            $record->tokens()->delete();

                            Notification::make()->title("{$record->name} deactivated")->success()->send();
                        }),

                    Action::make('reactivate')
                        ->label('Reactivate')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (User $record): bool => ! $record->is_active)
                        ->action(function (User $record): void {
                            $record->update(['is_active' => true]);

                            Notification::make()->title("{$record->name} reactivated")->success()->send();
                        }),

                ]),
            ]);
        // No delete: users own orders and ledgers; deactivate instead (PRA-044)
    }
}
