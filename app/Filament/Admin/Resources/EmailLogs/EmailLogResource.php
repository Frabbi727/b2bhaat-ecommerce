<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\EmailLogs;

use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Models\EmailLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;
use UnitEnum;

class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static UnitEnum|string|null $navigationGroup = 'Security & Audit';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Email logs';

    protected static ?string $modelLabel = 'Email';

    protected static ?string $pluralModelLabel = 'Email delivery log';

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
                TextColumn::make('recipient_email')
                    ->label('Recipient')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('template_key')
                    ->label('Template')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        'pending' => 'warning',
                        'skipped' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(30)
                    ->tooltip(fn (EmailLog $record): ?string => $record->error_message)
                    ->placeholder('—'),
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime('d M Y, g:i A')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->dateTime('d M Y, g:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'sent' => 'Sent',
                        'failed' => 'Failed',
                        'pending' => 'Pending',
                        'skipped' => 'Skipped',
                    ]),
                SelectFilter::make('template_key')
                    ->label('Template')
                    ->options([
                        'order_confirmation' => 'Order Confirmation',
                        'seller_new_order' => 'Seller Consignment',
                        'order_tracking_update' => 'Parcel Tracking Update',
                        'customer_refund_settled' => 'Refund Settled',
                        'seller_payout_status' => 'Seller Payout Status',
                        'seller_registration_received' => 'Seller Registered',
                        'seller_kyc_approved' => 'Seller Approved',
                        'wholesale_approved' => 'Wholesale Approved',
                    ]),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry Send')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (EmailLog $record): bool => $record->status === 'failed')
                    ->action(function (EmailLog $record): void {
                        $record->update(['status' => 'pending', 'retries_count' => $record->retries_count + 1]);

                        try {
                            if (class_exists($record->mailable_class) && $record->related) {
                                Mail::to($record->recipient_email)->queue(new $record->mailable_class($record->related));
                                $record->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);
                            }
                        } catch (\Throwable $e) {
                            $record->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                        }

                        Notification::make()
                            ->title('Email Queued for Re-delivery')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailLogs::route('/'),
        ];
    }
}
