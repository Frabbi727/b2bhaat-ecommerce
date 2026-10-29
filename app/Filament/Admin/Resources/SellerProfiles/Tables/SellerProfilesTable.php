<?php

namespace App\Filament\Admin\Resources\SellerProfiles\Tables;

use App\Models\SellerProfile;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SellerProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('store_name')
                    ->label('Store / Factory Name')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('user.name')
                    ->label('Owner / Representative')
                    ->searchable()
                    ->description(fn (SellerProfile $record): string => $record->user?->phone ?? $record->user?->email ?? ''),

                TextColumn::make('trade_license_number')
                    ->label('Trade License')
                    ->fontFamily('mono')
                    ->placeholder('N/A')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('tax_id_number')
                    ->label('TIN / Tax ID')
                    ->fontFamily('mono')
                    ->placeholder('N/A')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('commission_rate_percentage')
                    ->label('Commission')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1).'%')
                    ->sortable()
                    ->alignment('center'),

                TextColumn::make('status')
                    ->label('Verification Status')
                    ->badge()
                    ->state(function (SellerProfile $record): string {
                        if ($record->is_approved) {
                            return 'Approved';
                        }
                        if ($record->rejected_at !== null) {
                            return 'Rejected';
                        }

                        return 'Pending Review';
                    })
                    ->color(function (SellerProfile $record): array {
                        if ($record->is_approved) {
                            return Color::Emerald;
                        }
                        if ($record->rejected_at !== null) {
                            return Color::Rose;
                        }

                        return Color::Amber;
                    }),

                TextColumn::make('created_at')
                    ->label('Applied On')
                    ->dateTime('M d, Y')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('inspect_dossier')
                    ->label('Inspect Dossier')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->color('gray')
                    ->modalHeading(fn (SellerProfile $record) => "KYC Dossier: {$record->store_name}")
                    ->modalDescription(fn (SellerProfile $record) => "Owner: {$record->user?->name} ({$record->user?->email} | {$record->user?->phone})")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextInput::make('store_name')
                            ->label('Store / Factory Name')
                            ->disabled()
                            ->default(fn (SellerProfile $record) => $record->store_name),
                        TextInput::make('trade_license_number')
                            ->label('Govt. Trade License Number')
                            ->disabled()
                            ->default(fn (SellerProfile $record) => $record->trade_license_number ?? 'Not provided'),
                        TextInput::make('tax_id_number')
                            ->label('TIN (Taxpayer Identification Number)')
                            ->disabled()
                            ->default(fn (SellerProfile $record) => $record->tax_id_number ?? 'Not provided'),
                        TextInput::make('commission_rate_percentage')
                            ->label('Commission Rate (%)')
                            ->disabled()
                            ->default(fn (SellerProfile $record) => $record->commission_rate_percentage.'%'),
                        Textarea::make('rejection_reason')
                            ->label('Rejection Reason / Notes')
                            ->disabled()
                            ->visible(fn (SellerProfile $record) => filled($record->rejection_reason))
                            ->default(fn (SellerProfile $record) => $record->rejection_reason),
                    ]),

                Action::make('approve_merchant')
                    ->label('Approve Store')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (SellerProfile $record) => ! $record->is_approved)
                    ->requiresConfirmation()
                    ->modalHeading('Approve Merchant & Authorize Storefront')
                    ->modalDescription(fn (SellerProfile $record) => "Approve '{$record->store_name}' (Trade License: {$record->trade_license_number}) to open their vendor storefront and sell on B2B Haat?")
                    ->modalSubmitActionLabel('Confirm Approval')
                    ->schema([
                        TextInput::make('commission_rate_percentage')
                            ->label('Platform Commission Rate (%)')
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(50.00)
                            ->default(fn (SellerProfile $record) => (float) $record->commission_rate_percentage)
                            ->required(),
                    ])
                    ->action(function (SellerProfile $record, array $data) {
                        $record->update([
                            'is_approved' => true,
                            'approved_at' => now(),
                            'rejected_at' => null,
                            'rejection_reason' => null,
                            'commission_rate_percentage' => $data['commission_rate_percentage'] ?? $record->commission_rate_percentage,
                        ]);

                        Notification::make()
                            ->title('Merchant Approved Successfully')
                            ->body("{$record->store_name} is now authorized to sell on B2B Haat.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject_merchant')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (SellerProfile $record) => ! $record->is_approved && $record->rejected_at === null)
                    ->modalHeading('Reject Merchant Application')
                    ->modalDescription(fn (SellerProfile $record) => "Provide reasons for rejecting {$record->store_name}. This will be logged in the merchant's KYC record.")
                    ->modalSubmitActionLabel('Reject Application')
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Reason for Rejection / Deficiency')
                            ->placeholder('e.g. Trade license expired or unverified in city corporation registry; TIN certificate invalid.')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (SellerProfile $record, array $data) {
                        $record->update([
                            'is_approved' => false,
                            'rejected_at' => now(),
                            'rejection_reason' => $data['rejection_reason'],
                        ]);

                        Notification::make()
                            ->title('Merchant Application Rejected')
                            ->body("{$record->store_name} application has been rejected.")
                            ->danger()
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
