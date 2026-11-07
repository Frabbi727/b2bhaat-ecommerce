<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WalletTransactions\Tables;

use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WalletTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('wallet.seller.sellerProfile.store_name')
                    ->label('Seller')
                    ->description(fn (WalletTransaction $record): ?string => $record->wallet?->seller?->phone)
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('transaction_type')
                    ->label('Entry')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::typeLabels()[$state] ?? str($state)->headline()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        'payout' => 'info',
                        'escrow_release' => 'success',
                        'escrow_hold', 'escrow_hold_adjustment' => 'warning',
                        'payout_reversal', 'return_reversal', 'escrow_refund' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('amount')
                    ->money('BDT')
                    ->weight('semibold')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Payout status')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                        WalletTransaction::STATUS_REQUESTED => 'Waiting to be paid',
                        WalletTransaction::STATUS_DISBURSED => 'Paid',
                        WalletTransaction::STATUS_REJECTED => 'Rejected',
                        default => $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        WalletTransaction::STATUS_REQUESTED => 'warning',
                        WalletTransaction::STATUS_DISBURSED => 'success',
                        WalletTransaction::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('reference_note')
                    ->label('Details')
                    ->limit(70)
                    ->tooltip(fn (WalletTransaction $record): ?string => $record->reference_note)
                    ->wrap()
                    ->searchable()
                    ->visibleFrom('lg'),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('d M Y, g:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('transaction_type')
                    ->label('Entry')
                    ->options(self::typeLabels()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading('Nothing here')
            ->emptyStateDescription('Payout requests from sellers appear in “Waiting to be paid”.')
            ->recordActions([
                Action::make('disburse')
                    ->label('Mark as paid')
                    ->button()
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->modalHeading(fn (WalletTransaction $record): string => 'Mark ৳'.number_format((float) $record->amount, 2).' as paid')
                    ->modalDescription(fn (WalletTransaction $record): ?string => $record->reference_note)
                    ->modalSubmitActionLabel('Mark as paid')
                    ->visible(fn (WalletTransaction $record): bool => $record->isPendingPayout())
                    ->schema([
                        Select::make('channel')
                            ->label('Disbursement Channel')
                            ->options([
                                'bank' => 'Bank Transfer (Bank Operating Account)',
                                'bkash' => 'bKash (bKash Clearing)',
                                'nagad' => 'Nagad (Nagad Clearing)',
                            ])
                            ->default('bank')
                            ->required(),
                        TextInput::make('transaction_id')
                            ->label('Transaction ID (TrxID)')
                            ->helperText('From the bKash, Nagad or bank transfer you just made. The seller sees it.')
                            ->placeholder('e.g. 9K28X912 or BEFTN-0012')
                            ->required(),
                    ])
                    ->action(function (WalletTransaction $record, array $data): void {
                        $channel = (string) ($data['channel'] ?? 'bank');

                        try {
                            app(EscrowLedgerService::class)->disbursePayout($record, (string) $data['transaction_id'], $channel);
                        } catch (Exception $exception) {
                            Notification::make()->title('Payout not disbursed')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Payout marked as paid')
                            ->body("Disbursed ৳{$record->amount} via {$channel} with TrxID: {$data['transaction_id']}.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject this payout request?')
                    ->modalDescription('The money goes back to the seller’s available balance.')
                    ->modalSubmitActionLabel('Reject request')
                    ->visible(fn (WalletTransaction $record): bool => $record->isPendingPayout())
                    ->schema([
                        TextInput::make('reason')
                            ->label('Reason shown to the seller')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (WalletTransaction $record, array $data): void {
                        try {
                            app(EscrowLedgerService::class)->rejectPayout($record, (string) $data['reason']);
                        } catch (Exception $exception) {
                            Notification::make()->title('Payout not rejected')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Payout rejected')
                            ->body("৳{$record->amount} returned to the seller's available balance.")
                            ->warning()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            'payout' => 'Payout request',
            'escrow_hold' => 'Held in escrow',
            'escrow_release' => 'Released to seller',
            'escrow_refund' => 'Escrow refunded',
            'escrow_hold_adjustment' => 'Escrow adjustment',
            'return_reversal' => 'Return reversal',
            'payout_reversal' => 'Rejected payout returned',
        ];
    }
}
