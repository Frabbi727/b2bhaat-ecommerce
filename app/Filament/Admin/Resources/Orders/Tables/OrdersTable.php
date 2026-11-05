<?php

namespace App\Filament\Admin\Resources\Orders\Tables;

use App\Filament\Admin\Support\StatusColors;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Services\Payments\PaymentVerificationService;
use App\Services\Sms\OrderNotificationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customer', 'orderStatus', 'paymentStatus', 'paymentMethod'])->withCount('subOrders'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->description(fn (Order $record): string => $record->created_at->format('d M Y, g:i A'))
                    ->weight('medium')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->description(fn (Order $record): ?string => $record->shipping_address['recipient_phone'] ?? $record->customer?->phone)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))),

                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BDT')
                    ->weight('semibold')
                    ->sortable(),

                TextColumn::make('paymentStatus.name')
                    ->label('Payment')
                    ->badge()
                    ->color(fn (Order $record): string => StatusColors::payment($record->paymentStatus?->slug))
                    ->description(fn (Order $record): ?string => $record->paymentMethod?->slug === 'cod' ? 'Cash on delivery' : $record->paymentMethod?->name),

                TextColumn::make('orderStatus.name')
                    ->label('Status')
                    ->badge()
                    ->color(fn (Order $record): string => StatusColors::order($record->orderStatus?->slug)),

                TextColumn::make('sub_orders_count')
                    ->label('Sellers')
                    ->numeric()
                    ->visibleFrom('lg')
                    ->toggleable(),

                TextColumn::make('shipping_amount')
                    ->label('Delivery')
                    ->money('BDT')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('order_status_id')
                    ->label('Status')
                    ->multiple()
                    ->options(fn () => OrderStatus::pluck('name', 'id')),
                SelectFilter::make('payment_status_id')
                    ->label('Payment')
                    ->multiple()
                    ->options(fn () => PaymentStatus::pluck('name', 'id')),
                SelectFilter::make('payment_method_id')
                    ->label('Payment method')
                    ->options(fn () => PaymentMethod::pluck('name', 'id')),
                Filter::make('placed')
                    ->schema([
                        DatePicker::make('from')->label('Placed from'),
                        DatePicker::make('until')->label('Placed until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): ?string => ($data['from'] ?? null) || ($data['until'] ?? null)
                        ? 'Placed '.trim(($data['from'] ?? '…').' – '.($data['until'] ?? '…'))
                        : null),
            ])
            ->recordUrl(fn (Order $record): string => route('filament.admin.resources.orders.view', $record))
            ->recordActions([
                self::verifyPaymentAction()->button(),
                ActionGroup::make([
                    ViewAction::make()->label('Open order'),
                    self::rejectPaymentAction(),
                    self::invoiceAction(),
                    self::resendInvoiceSmsAction(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShoppingBag)
            ->emptyStateHeading('No orders match')
            ->emptyStateDescription('Clear the filters or search to see every order.');
        // No delete actions: orders are financial records (PRA-044)
    }

    public static function verifyPaymentAction(): Action
    {
        return Action::make('verifyPayment')
            ->label('Verify payment')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Order $record) => $record->paymentStatus?->slug === 'awaiting_verification')
            ->requiresConfirmation()
            ->modalHeading('Verify this mobile payment?')
            ->modalDescription(fn (Order $record) => 'Check that the '.($record->shipping_address['mfs_gateway'] ?? 'MFS')
                .' TrxID '.($record->shipping_address['mfs_transaction_id'] ?? '—')
                .' from '.($record->shipping_address['mfs_sender_number'] ?? '—')
                .' shows ৳'.number_format((float) $record->total_amount, 2).' received. Verifying marks the order paid and holds the sellers’ money in escrow.')
            ->modalSubmitActionLabel('Payment received')
            ->action(function (Order $record): void {
                app(PaymentVerificationService::class)->markVerified($record);

                Notification::make()
                    ->title("Payment verified for #{$record->order_number}")
                    ->success()
                    ->send();
            });
    }

    public static function rejectPaymentAction(): Action
    {
        return Action::make('rejectPayment')
            ->label('Reject payment')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record) => $record->paymentStatus?->slug === 'awaiting_verification')
            ->modalHeading('Reject this payment?')
            ->modalDescription('Use this when the TrxID is not in your statement. The order is cancelled and its stock released.')
            ->modalSubmitActionLabel('Reject and cancel order')
            ->schema([
                TextInput::make('reason')
                    ->label('Reason')
                    ->placeholder('e.g. TrxID not found in the bKash statement')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (Order $record, array $data): void {
                app(PaymentVerificationService::class)->rejectPayment($record, (string) $data['reason']);

                Notification::make()
                    ->title("Payment rejected for #{$record->order_number}")
                    ->body('Order cancelled and stock released.')
                    ->warning()
                    ->send();
            });
    }

    public static function invoiceAction(): Action
    {
        return Action::make('invoice')
            ->label('Open invoice')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (Order $record): bool => filled($record->invoice_token))
            ->url(fn (Order $record): string => route('order.invoice.token', $record->invoice_token))
            ->openUrlInNewTab();
    }

    public static function resendInvoiceSmsAction(): Action
    {
        return Action::make('resendInvoiceSms')
            ->label('Resend invoice SMS')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Send the invoice link again?')
            ->modalDescription(fn (Order $record): string => 'An SMS with the invoice link goes to '.($record->shipping_address['recipient_phone'] ?? $record->customer?->phone ?? 'the customer').'.')
            ->modalSubmitActionLabel('Send SMS')
            ->action(function (Order $record): void {
                $sent = app(OrderNotificationService::class)->sendInvoiceLink($record);

                $sent
                    ? Notification::make()->title('Invoice SMS sent')->success()->send()
                    : Notification::make()->title('SMS not sent')->body('Check the customer’s phone number and the SMS gateway balance.')->danger()->send();
            });
    }
}
