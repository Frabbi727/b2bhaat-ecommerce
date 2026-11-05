<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Tables;

use App\Models\WalletTransaction;
use App\Services\Escrow\EscrowLedgerService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WalletTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('wallet.seller.sellerProfile.store_name')
                    ->label('Store / Factory')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('wallet.seller.name')
                    ->label('Merchant Owner')
                    ->searchable(),

                TextColumn::make('wallet.seller.phone')
                    ->label('Phone Number')
                    ->fontFamily('mono')
                    ->searchable(),

                TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): array => match ($state) {
                        'payout' => Color::Rose,
                        'escrow_release' => Color::Emerald,
                        'escrow_hold' => Color::Amber,
                        'escrow_refund' => Color::Indigo,
                        'payout_reversal', 'return_reversal' => Color::Orange,
                        default => Color::Gray,
                    }),

                TextColumn::make('status')
                    ->label('Payout Status')
                    ->badge()
                    ->placeholder('—')
                    ->color(fn (?string $state): array => match ($state) {
                        WalletTransaction::STATUS_REQUESTED => Color::Amber,
                        WalletTransaction::STATUS_DISBURSED => Color::Emerald,
                        WalletTransaction::STATUS_REJECTED => Color::Rose,
                        default => Color::Gray,
                    }),

                TextColumn::make('amount')
                    ->label('Amount (Tk)')
                    ->money('BDT')
                    ->weight('black')
                    ->size('lg')
                    ->sortable(),

                TextColumn::make('reference_note')
                    ->label('Disbursement Details / Bank A/C')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Date & Time')
                    ->dateTime('M d, Y h:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('transaction_type')
                    ->label('Transaction Type')
                    ->options([
                        'payout' => 'Payout Requests (Withdrawals)',
                        'escrow_hold' => 'Escrow Holds',
                        'escrow_release' => 'Escrow Releases',
                        'escrow_refund' => 'Escrow Refunds (Cancellations)',
                        'escrow_hold_adjustment' => 'Escrow Hold Adjustments (Delivery Fee)',
                        'return_reversal' => 'Return Reversals',
                        'payout_reversal' => 'Rejected Payout Refunds',
                    ]),

                SelectFilter::make('status')
                    ->label('Payout Status')
                    ->options([
                        WalletTransaction::STATUS_REQUESTED => 'Awaiting Disbursement',
                        WalletTransaction::STATUS_DISBURSED => 'Disbursed',
                        WalletTransaction::STATUS_REJECTED => 'Rejected',
                    ]),
            ])
            ->recordActions([
                Action::make('disburse')
                    ->label('Attach TrxID & Disburse')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (WalletTransaction $record): bool => $record->isPendingPayout())
                    ->schema([
                        TextInput::make('transaction_id')
                            ->label('MFS / Bank Transaction ID (TrxID)')
                            ->placeholder('e.g. 9K28X912 or BEFTN-0012')
                            ->required(),
                    ])
                    ->action(function (WalletTransaction $record, array $data): void {
                        try {
                            app(EscrowLedgerService::class)->disbursePayout($record, (string) $data['transaction_id']);
                        } catch (Exception $exception) {
                            Notification::make()->title('Payout not disbursed')->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Payout Marked as Disbursed')
                            ->body("Disbursed ৳{$record->amount} with TrxID: {$data['transaction_id']}.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject & Refund to Wallet')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
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
                            ->title('Payout Rejected')
                            ->body("৳{$record->amount} returned to the seller's available balance.")
                            ->warning()
                            ->send();
                    }),
            ]);
    }
}
