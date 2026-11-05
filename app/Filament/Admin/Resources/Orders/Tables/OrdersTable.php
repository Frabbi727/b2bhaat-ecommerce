<?php

namespace App\Filament\Admin\Resources\Orders\Tables;

use App\Models\Order;
use App\Services\Payments\PaymentVerificationService;
use App\Services\Sms\OrderNotificationService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->searchable(),
                TextColumn::make('orderStatus.name')
                    ->searchable(),
                TextColumn::make('paymentStatus.name')
                    ->searchable(),
                TextColumn::make('paymentMethod.name')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('tax_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('shipping_amount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('verifyPayment')
                    ->label('Verify Payment')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->visible(fn (Order $record) => $record->paymentStatus?->slug === 'awaiting_verification')
                    ->requiresConfirmation()
                    ->modalHeading('Verify manual MFS payment')
                    ->modalDescription(fn (Order $record) => 'Confirm the '.($record->shipping_address['mfs_gateway'] ?? 'MFS')
                        .' TrxID '.($record->shipping_address['mfs_transaction_id'] ?? '—')
                        .' from '.($record->shipping_address['mfs_sender_number'] ?? '—')
                        .' was received for ৳'.number_format((float) $record->total_amount, 2).'. This marks the order paid and holds seller escrow.')
                    ->action(function (Order $record): void {
                        app(PaymentVerificationService::class)->markVerified($record);

                        Notification::make()
                            ->title("Payment verified for #{$record->order_number}")
                            ->success()
                            ->send();
                    }),
                Action::make('resendInvoiceSms')
                    ->label('Resend Invoice SMS')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('info')
                    ->requiresConfirmation()
                    ->action(function (Order $record): void {
                        $sent = app(OrderNotificationService::class)->sendInvoiceLink($record);
                        if ($sent) {
                            Notification::make()
                                ->title('Invoice Voucher SMS Dispatched')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Failed to dispatch SMS. Verify customer phone number.')
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
