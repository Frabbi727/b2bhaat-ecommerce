<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Refunds;

use App\Events\Finance\RefundSettledEvent;
use App\Filament\Admin\Resources\Refunds\Pages\ListRefunds;
use App\Models\Refund;
use App\Services\Accounting\EventPipeline\CustomerRefundDisbursementJournalHandler;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnitEnum;

/**
 * Customer refunds owed (PRA-027). Read-only ledger; the only change allowed is marking a pending refund paid.
 */
class RefundResource extends Resource
{
    protected static ?string $model = Refund::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Customer refunds';

    protected static ?string $modelLabel = 'refund';

    protected static ?string $pluralModelLabel = 'customer refunds';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order.order_number')->label('Order')->searchable()->copyable(),
                TextColumn::make('subOrder.sub_order_number')->label('Consignment')->placeholder('—')->searchable(),
                TextColumn::make('order.customer.name')->label('Customer')->searchable(),
                TextColumn::make('order.paymentMethod.name')->label('Paid via'),
                TextColumn::make('amount')->label('Owed (Tk)')->money('BDT')->weight('bold')->sortable(),
                TextColumn::make('reason')->wrap()->limit(60),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === Refund::STATUS_PAID ? 'success' : 'warning'),
                TextColumn::make('method')->placeholder('—'),
                TextColumn::make('reference')->label('Refund TrxID')->placeholder('—')->copyable(),
                TextColumn::make('paid_at')->dateTime('M d, Y h:i A')->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Recorded')->dateTime('M d, Y h:i A')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Refund::STATUS_PENDING => 'Pending',
                    Refund::STATUS_PAID => 'Paid',
                ]),
            ])
            ->recordActions([
                Action::make('markPaid')
                    ->label('Mark Refund Paid')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Refund $record): bool => $record->isPending())
                    ->schema([
                        Select::make('method')
                            ->label('Refund Method')
                            ->options(['bkash' => 'bKash', 'nagad' => 'Nagad', 'bank' => 'Bank Transfer', 'cash' => 'Cash'])
                            ->required(),
                        TextInput::make('reference')
                            ->label('Transaction / Reference ID')
                            ->required()
                            ->maxLength(100),
                    ])
                    ->action(function (Refund $record, array $data): void {
                        $updated = DB::transaction(function () use ($record, $data): bool {
                            $locked = Refund::whereKey($record->id)->lockForUpdate()->first();
                            if (! $locked || ! $locked->isPending()) {
                                return false;
                            }

                            $locked->update([
                                'status' => Refund::STATUS_PAID,
                                'method' => $data['method'],
                                'reference' => trim((string) $data['reference']),
                                'paid_at' => now(),
                            ]);

                            app(CustomerRefundDisbursementJournalHandler::class)->recordRefundDisbursed($locked);

                            return true;
                        });

                        if ($updated) {
                            try {
                                event(new RefundSettledEvent($record->fresh()));
                            } catch (\Throwable $e) {
                                Log::warning('Refund settled email event failed: '.$e->getMessage());
                            }

                            Notification::make()->title('Refund marked as paid')->success()->send();
                        } else {
                            Notification::make()->title('Refund was already settled')->warning()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefunds::route('/'),
        ];
    }
}
