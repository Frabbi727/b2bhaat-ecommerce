<?php

namespace App\Filament\Admin\Support;

use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Kyc\ApplicationReviewService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

/**
 * Review buttons for seller-store and wholesale-buyer applications, shared by the KYC inbox, Sellers and Users so
 * every screen asks the same questions and records the same decision.
 */
class ApplicationReviewActions
{
    public static function viewSellerApplication(): Action
    {
        return Action::make('viewSellerApplication')
            ->label('View details')
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->color('gray')
            ->modalHeading(fn (SellerProfile $record): string => $record->store_name)
            ->modalDescription('Seller store application')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('status')->state(fn (SellerProfile $record): string => self::sellerStatus($record))->badge()
                        ->color(fn (SellerProfile $record): string => $record->isApproved() ? 'success' : ($record->isRejected() ? 'danger' : 'warning')),
                    TextEntry::make('applied')->label('Applied')->state(fn (SellerProfile $record) => $record->created_at)->dateTime('d M Y, g:i A'),
                    TextEntry::make('owner')->label('Owner')->state(fn (SellerProfile $record): string => $record->user?->name ?? '—'),
                    TextEntry::make('contact')->label('Contact')->state(fn (SellerProfile $record): string => collect([$record->user?->phone, $record->user?->email])->filter()->implode(' · ') ?: '—')->copyable(),
                    TextEntry::make('trade_license_number')->label('Trade licence')->state(fn (SellerProfile $record): string => $record->trade_license_number ?: 'Not provided')->copyable(),
                    TextEntry::make('tax_id_number')->label('TIN')->state(fn (SellerProfile $record): string => $record->tax_id_number ?: 'Not provided')->copyable(),
                    TextEntry::make('commission')->label('Commission')->state(fn (SellerProfile $record): string => rtrim(rtrim(number_format((float) $record->commission_rate_percentage, 2), '0'), '.').'%'),
                    TextEntry::make('decided')->label('Decided')->state(fn (SellerProfile $record) => $record->approved_at ?? $record->rejected_at)->dateTime('d M Y, g:i A')->placeholder('Not yet'),
                    TextEntry::make('rejection_reason')->label('Reason for rejection')->state(fn (SellerProfile $record): ?string => $record->rejection_reason)
                        ->visible(fn (SellerProfile $record): bool => $record->isRejected() && filled($record->rejection_reason))->columnSpanFull(),
                ]),
            ]);
    }

    public static function approveSeller(): Action
    {
        return Action::make('approveSeller')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (SellerProfile $record): bool => ! $record->isApproved())
            ->modalHeading(fn (SellerProfile $record): string => "Approve {$record->store_name}?")
            ->modalDescription(fn (SellerProfile $record): string => 'Their store opens and their products can be listed. Trade licence: '.($record->trade_license_number ?: 'not provided').'.')
            ->modalSubmitActionLabel('Approve store')
            ->schema([
                TextInput::make('commission_rate_percentage')
                    ->label('Marketplace commission')
                    ->suffix('%')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(50)
                    ->default(fn (SellerProfile $record): float => (float) $record->commission_rate_percentage)
                    ->helperText('Taken from each order this seller delivers.')
                    ->required(),
            ])
            ->action(function (SellerProfile $record, array $data): void {
                app(ApplicationReviewService::class)->approveSeller($record, (float) $data['commission_rate_percentage']);

                Notification::make()
                    ->title("{$record->store_name} approved")
                    ->body('The store is open and can sell on B2B Haat.')
                    ->success()
                    ->send();
            });
    }

    public static function rejectSeller(): Action
    {
        return Action::make('rejectSeller')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (SellerProfile $record): bool => $record->isPending())
            ->modalHeading(fn (SellerProfile $record): string => "Reject {$record->store_name}?")
            ->modalDescription('Say what is wrong so the seller can fix it and apply again.')
            ->modalSubmitActionLabel('Reject application')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. The trade licence has expired. Please upload the renewed licence.')
                    ->rows(3)
                    ->required()
                    ->minLength(5)
                    ->maxLength(1000),
            ])
            ->action(function (SellerProfile $record, array $data): void {
                app(ApplicationReviewService::class)->rejectSeller($record, (string) $data['reason']);

                Notification::make()
                    ->title("{$record->store_name} rejected")
                    ->body('The reason is saved on their application.')
                    ->warning()
                    ->send();
            });
    }

    public static function viewWholesaleApplication(): Action
    {
        return Action::make('viewWholesaleApplication')
            ->label('View details')
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->color('gray')
            ->modalHeading(fn (User $record): string => $record->business_name ?: $record->name)
            ->modalDescription('Wholesale buyer application')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextEntry::make('status')->state(fn (User $record): string => self::wholesaleStatus($record))->badge()
                        ->color(fn (User $record): string => $record->is_wholesale_approved ? 'success' : ($record->isWholesaleRejected() ? 'danger' : 'warning')),
                    TextEntry::make('registered')->label('Registered')->state(fn (User $record) => $record->created_at)->dateTime('d M Y, g:i A'),
                    TextEntry::make('name')->label('Applicant')->state(fn (User $record): string => $record->name),
                    TextEntry::make('contact')->label('Contact')->state(fn (User $record): string => collect([$record->phone, $record->email])->filter()->implode(' · ') ?: '—')->copyable(),
                    TextEntry::make('trade_license_number')->label('Trade licence')->state(fn (User $record): string => $record->trade_license_number ?: 'Not provided')->copyable(),
                    TextEntry::make('tax_id_number')->label('TIN')->state(fn (User $record): string => $record->tax_id_number ?: 'Not provided')->copyable(),
                    TextEntry::make('decided')->label('Decided')->state(fn (User $record) => $record->wholesale_approved_at ?? $record->wholesale_rejected_at)->dateTime('d M Y, g:i A')->placeholder('Not yet'),
                    TextEntry::make('wholesale_rejection_reason')->label('Reason for rejection')->state(fn (User $record): ?string => $record->wholesale_rejection_reason)
                        ->visible(fn (User $record): bool => $record->isWholesaleRejected() && filled($record->wholesale_rejection_reason))->columnSpanFull(),
                ]),
            ]);
    }

    public static function approveWholesale(): Action
    {
        return Action::make('approveWholesale')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (User $record): bool => ! $record->is_wholesale_approved && (filled($record->trade_license_number) || filled($record->business_name)))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => 'Approve '.($record->business_name ?: $record->name).'?')
            ->modalDescription(fn (User $record): string => 'They will see wholesale prices and can place bulk orders. Trade licence: '.($record->trade_license_number ?: 'not provided').'. They get an SMS with the decision.')
            ->modalSubmitActionLabel('Approve buyer')
            ->action(function (User $record): void {
                app(ApplicationReviewService::class)->approveWholesaleBuyer($record);

                Notification::make()
                    ->title(($record->business_name ?: $record->name).' approved')
                    ->body('They can now buy at wholesale prices.')
                    ->success()
                    ->send();
            });
    }

    public static function rejectWholesale(): Action
    {
        return Action::make('rejectWholesale')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (User $record): bool => $record->isWholesalePending())
            ->modalHeading(fn (User $record): string => 'Reject '.($record->business_name ?: $record->name).'?')
            ->modalDescription('Say what is wrong so they can fix it. They get an SMS with the decision and can keep shopping at retail prices.')
            ->modalSubmitActionLabel('Reject application')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. The trade licence number does not match the business name.')
                    ->rows(3)
                    ->required()
                    ->minLength(5)
                    ->maxLength(1000),
            ])
            ->action(function (User $record, array $data): void {
                app(ApplicationReviewService::class)->rejectWholesaleBuyer($record, (string) $data['reason']);

                Notification::make()
                    ->title(($record->business_name ?: $record->name).' rejected')
                    ->body('The reason is saved on their account.')
                    ->warning()
                    ->send();
            });
    }

    public static function sellerStatus(SellerProfile $profile): string
    {
        return $profile->isApproved() ? 'Approved' : ($profile->isRejected() ? 'Rejected' : 'Waiting for review');
    }

    public static function wholesaleStatus(User $user): string
    {
        return $user->is_wholesale_approved ? 'Approved' : ($user->isWholesaleRejected() ? 'Rejected' : 'Waiting for review');
    }
}
