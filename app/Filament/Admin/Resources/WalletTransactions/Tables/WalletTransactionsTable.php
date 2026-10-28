<?php

namespace App\Filament\Admin\Resources\WalletTransactions\Tables;

use App\Models\WalletTransaction;
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
                    ]),
            ])
            ->recordActions([
                Action::make('markDisbursed')
                    ->label('Attach TrxID & Disburse')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (WalletTransaction $record) => $record->transaction_type === 'payout' && ! str_contains($record->reference_note ?? '', 'DISBURSED'))
                    ->schema([
                        TextInput::make('transaction_id')
                            ->label('MFS / Bank Transaction ID (TrxID)')
                            ->placeholder('e.g. 9K28X912 or BEFTN-0012')
                            ->required(),
                    ])
                    ->action(function (WalletTransaction $record, array $data): void {
                        $updatedNote = ($record->reference_note ?? '')." [DISBURSED - TrxID: {$data['transaction_id']}]";
                        $record->update(['reference_note' => $updatedNote]);

                        Notification::make()
                            ->title('Payout Marked as Disbursed')
                            ->body("Disbursed ৳{$record->amount} with TrxID: {$data['transaction_id']}.")
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
