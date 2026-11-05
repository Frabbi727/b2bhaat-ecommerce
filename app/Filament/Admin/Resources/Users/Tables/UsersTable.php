<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
                    ->copyable()
                    ->searchable(),
                TextColumn::make('b2b_status')
                    ->label('B2B Status')
                    ->badge()
                    ->state(function (User $record): string {
                        if ($record->is_wholesale_approved) {
                            return 'B2B Approved';
                        }
                        if ($record->wholesale_rejected_at !== null) {
                            return 'B2B Rejected';
                        }
                        if (filled($record->trade_license_number) || filled($record->business_name)) {
                            return 'Pending Review';
                        }

                        return 'Standard Buyer';
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
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('inspect_business')
                    ->label('Inspect B2B')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->visible(fn (User $record) => filled($record->trade_license_number) || filled($record->business_name))
                    ->modalHeading(fn (User $record) => "Business KYC Dossier: {$record->business_name}")
                    ->modalDescription(fn (User $record) => "Applicant: {$record->name} ({$record->email} | {$record->phone})")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextInput::make('business_name')
                            ->label('Registered Business / Entity Name')
                            ->disabled()
                            ->default(fn (User $record) => $record->business_name ?? 'N/A'),
                        TextInput::make('trade_license_number')
                            ->label('Trade License Number')
                            ->disabled()
                            ->default(fn (User $record) => $record->trade_license_number ?? 'N/A'),
                        TextInput::make('tax_id_number')
                            ->label('TIN (Taxpayer Identification Number)')
                            ->disabled()
                            ->default(fn (User $record) => $record->tax_id_number ?? 'N/A'),
                        Textarea::make('wholesale_rejection_reason')
                            ->label('Rejection Reason / Notes')
                            ->disabled()
                            ->visible(fn (User $record) => filled($record->wholesale_rejection_reason))
                            ->default(fn (User $record) => $record->wholesale_rejection_reason),
                    ]),

                Action::make('approve_wholesale')
                    ->label('Approve B2B')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Wholesale Business Account')
                    ->modalDescription(fn (User $record) => "Approve {$record->business_name} ({$record->trade_license_number}) for wholesale tier pricing and bulk matrix ordering?")
                    ->visible(fn (User $record) => ! $record->is_wholesale_approved && (filled($record->trade_license_number) || filled($record->business_name)))
                    ->action(function (User $record) {
                        $wholesaleRole = Role::where('slug', 'wholesale_buyer')->first();
                        $record->update([
                            'is_wholesale_approved' => true,
                            'wholesale_approved_at' => now(),
                            'wholesale_rejected_at' => null,
                            'wholesale_rejection_reason' => null,
                            'role_id' => $wholesaleRole?->id ?? $record->role_id,
                        ]);

                        Notification::make()
                            ->title('Wholesale Account Approved')
                            ->body("{$record->name} ({$record->business_name}) can now access factory wholesale rates.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject_wholesale')
                    ->label('Reject B2B')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject Wholesale B2B Application')
                    ->modalDescription(fn (User $record) => "Provide reasons for rejecting {$record->business_name} ({$record->name}).")
                    ->modalSubmitActionLabel('Reject Application')
                    ->visible(fn (User $record) => ! $record->is_wholesale_approved && $record->wholesale_rejected_at === null && (filled($record->trade_license_number) || filled($record->business_name)))
                    ->schema([
                        Textarea::make('wholesale_rejection_reason')
                            ->label('Reason for Rejection / Deficiency')
                            ->placeholder('e.g. Incomplete business documentation; Trade license could not be validated.')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update([
                            'is_wholesale_approved' => false,
                            'wholesale_rejected_at' => now(),
                            'wholesale_rejection_reason' => $data['wholesale_rejection_reason'],
                        ]);

                        Notification::make()
                            ->title('Wholesale Application Rejected')
                            ->body("{$record->name} wholesale verification request has been rejected.")
                            ->danger()
                            ->send();
                    }),

                Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The account can no longer sign in or reach any panel. Orders, payouts and ledger history are kept.')
                    ->visible(fn (User $record): bool => $record->is_active && $record->id !== auth()->id())
                    ->action(function (User $record): void {
                        $record->update(['is_active' => false]);

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

                EditAction::make(),
            ]);
        // No delete: users own orders and ledgers; deactivate instead (PRA-044)
    }
}
