<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\WalletTransactions\Tables;

use App\Models\WalletTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['subOrder', 'wallet']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Date & Time')
                    ->dateTime('d M Y, g:i A')
                    ->sortable(),

                TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        WalletTransaction::TYPE_ESCROW_RELEASE,
                        WalletTransaction::TYPE_PAYOUT_REVERSAL => 'success',
                        WalletTransaction::TYPE_ESCROW_HOLD,
                        WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT => 'warning',
                        WalletTransaction::TYPE_PAYOUT => 'info',
                        WalletTransaction::TYPE_RETURN_REVERSAL,
                        WalletTransaction::TYPE_PARTIAL_RETURN_HELD,
                        WalletTransaction::TYPE_PARTIAL_RETURN_RELEASED,
                        WalletTransaction::TYPE_ESCROW_REFUND => 'danger',
                        WalletTransaction::TYPE_COD_SELF_COLLECTED,
                        WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('reference_note')
                    ->visibleFrom('md')
                    ->label('Reference / Details')
                    ->wrap()
                    ->description(fn (WalletTransaction $record): ?string => $record->subOrder ? 'Package #'.$record->subOrder->sub_order_number : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                        fn (Builder $q) => $q
                            ->where('reference_note', 'like', "%{$search}%")
                            ->orWhereHas('subOrder', fn (Builder $sq) => $sq->where('sub_order_number', 'like', "%{$search}%"))
                    )),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->sortable()
                    ->formatStateUsing(fn (WalletTransaction $record): string => self::formatSignedAmount($record))
                    ->color(fn (WalletTransaction $record): string => self::amountColor($record)),

                TextColumn::make('running_balance')
                    ->visibleFrom('md')
                    ->label('Running Balance')
                    ->fontFamily('mono')
                    ->formatStateUsing(function ($state): string {
                        $val = (float) $state;

                        return $val < 0 ? '-৳'.number_format(abs($val), 2) : '৳'.number_format($val, 2);
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->visibleFrom('md')
                    ->label('Payout Status')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): ?string => $state ? self::statusLabel($state) : null)
                    ->color(fn (?string $state): string => match ($state) {
                        WalletTransaction::STATUS_REQUESTED => 'warning',
                        WalletTransaction::STATUS_DISBURSED => 'success',
                        WalletTransaction::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('From date'),
                        DatePicker::make('until')->label('Until date'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),

                SelectFilter::make('transaction_type')
                    ->label('Transaction Type')
                    ->options([
                        WalletTransaction::TYPE_ESCROW_RELEASE => 'Released to your balance',
                        WalletTransaction::TYPE_ESCROW_HOLD => 'On hold after delivery',
                        WalletTransaction::TYPE_PAYOUT => 'Payout Withdrawal',
                        WalletTransaction::TYPE_PAYOUT_REVERSAL => 'Payout Refunded',
                        WalletTransaction::TYPE_RETURN_REVERSAL => 'Return / Refund Reversal',
                        WalletTransaction::TYPE_ESCROW_REFUND => 'Refunded to buyer from money on hold',
                        WalletTransaction::TYPE_COD_SELF_COLLECTED => 'COD Adjustment',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return $query;
                        }

                        return match ($value) {
                            WalletTransaction::TYPE_ESCROW_HOLD => $query->whereIn('transaction_type', [
                                WalletTransaction::TYPE_ESCROW_HOLD,
                                WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT,
                            ]),
                            WalletTransaction::TYPE_COD_SELF_COLLECTED => $query->whereIn('transaction_type', [
                                WalletTransaction::TYPE_COD_SELF_COLLECTED,
                                WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL,
                            ]),
                            default => $query->where('transaction_type', $value),
                        };
                    }),

                SelectFilter::make('status')
                    ->label('Payout Status')
                    ->options([
                        WalletTransaction::STATUS_REQUESTED => 'Waiting to be paid',
                        WalletTransaction::STATUS_DISBURSED => 'Paid',
                        WalletTransaction::STATUS_REJECTED => 'Rejected',
                    ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading('No transactions recorded yet')
            ->emptyStateDescription('Money released after delivery, commission and payouts will appear here automatically.');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            WalletTransaction::TYPE_ESCROW_RELEASE => 'Released to your balance',
            WalletTransaction::TYPE_ESCROW_HOLD,
            WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT => 'On hold after delivery',
            WalletTransaction::TYPE_PAYOUT => 'Payout Withdrawal',
            WalletTransaction::TYPE_PAYOUT_REVERSAL => 'Payout Refunded',
            WalletTransaction::TYPE_RETURN_REVERSAL => 'Return / Refund Reversal',
            WalletTransaction::TYPE_ESCROW_REFUND => 'Refunded to buyer from money on hold',
            WalletTransaction::TYPE_COD_SELF_COLLECTED,
            WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL => 'COD Adjustment',
            WalletTransaction::TYPE_COD_DEFICIT_PAYMENT => 'COD commission you paid in',
            WalletTransaction::TYPE_PARTIAL_RETURN_HELD => 'Items returned (taken from money on hold)',
            WalletTransaction::TYPE_PARTIAL_RETURN_RELEASED => 'Items returned (taken from your balance)',
            WalletTransaction::TYPE_PARTIAL_RETURN_COD => 'Commission given back for returned items',
            default => str($type)->headline()->toString(),
        };
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            WalletTransaction::STATUS_REQUESTED => 'Waiting to be paid',
            WalletTransaction::STATUS_DISBURSED => 'Paid',
            WalletTransaction::STATUS_REJECTED => 'Rejected',
            default => '—',
        };
    }

    public static function formatSignedAmount(WalletTransaction $record): string
    {
        $amount = number_format(abs((float) $record->amount), 2);

        if (in_array($record->transaction_type, [
            WalletTransaction::TYPE_ESCROW_RELEASE,
            WalletTransaction::TYPE_PAYOUT_REVERSAL,
            WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL,
            WalletTransaction::TYPE_COD_DEFICIT_PAYMENT,
            WalletTransaction::TYPE_PARTIAL_RETURN_COD,
        ], true)) {
            return '+৳'.$amount;
        }

        if (in_array($record->transaction_type, [
            WalletTransaction::TYPE_PAYOUT,
            WalletTransaction::TYPE_RETURN_REVERSAL,
            WalletTransaction::TYPE_COD_SELF_COLLECTED,
            WalletTransaction::TYPE_ESCROW_REFUND,
            WalletTransaction::TYPE_PARTIAL_RETURN_HELD,
            WalletTransaction::TYPE_PARTIAL_RETURN_RELEASED,
        ], true)) {
            return '-৳'.$amount;
        }

        return '৳'.$amount;
    }

    public static function amountColor(WalletTransaction $record): string
    {
        if (in_array($record->transaction_type, [
            WalletTransaction::TYPE_ESCROW_RELEASE,
            WalletTransaction::TYPE_PAYOUT_REVERSAL,
            WalletTransaction::TYPE_COD_SELF_COLLECTED_REVERSAL,
            WalletTransaction::TYPE_COD_DEFICIT_PAYMENT,
            WalletTransaction::TYPE_PARTIAL_RETURN_COD,
        ], true)) {
            return 'success';
        }

        if (in_array($record->transaction_type, [
            WalletTransaction::TYPE_PAYOUT,
            WalletTransaction::TYPE_RETURN_REVERSAL,
            WalletTransaction::TYPE_ESCROW_REFUND,
            WalletTransaction::TYPE_PARTIAL_RETURN_HELD,
            WalletTransaction::TYPE_PARTIAL_RETURN_RELEASED,
        ], true)) {
            return 'danger';
        }

        if ($record->transaction_type === WalletTransaction::TYPE_COD_SELF_COLLECTED) {
            return 'gray';
        }

        if (in_array($record->transaction_type, [
            WalletTransaction::TYPE_ESCROW_HOLD,
            WalletTransaction::TYPE_ESCROW_HOLD_ADJUSTMENT,
        ], true)) {
            return 'warning';
        }

        return 'gray';
    }
}
